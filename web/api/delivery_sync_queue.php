<?php

declare(strict_types=1);

require_once __DIR__ . '/delivery.php';
require_once __DIR__ . '/dims_backfill_queue.php';

function efpic_delivery_sync_queue_dir(array $config): string
{
    return dirname(efpic_storage_path($config)) . DIRECTORY_SEPARATOR . 'delivery_sync_queue';
}

function efpic_delivery_sync_job_path(array $config, string $slug): string
{
    $slug = trim($slug);
    if ($slug === '' || preg_match('/^[a-z0-9-]{1,80}$/', $slug) !== 1) {
        throw new InvalidArgumentException('Nederīgs galerijas slug');
    }

    return efpic_delivery_sync_queue_dir($config) . DIRECTORY_SEPARATOR . $slug . '.json';
}

/** @return array<string, mixed>|null */
function efpic_delivery_sync_load_job(array $config, string $slug): ?array
{
    try {
        return efpic_read_json_file(efpic_delivery_sync_job_path($config, $slug));
    } catch (Throwable) {
        return null;
    }
}

/** @param array<string, mixed> $job */
function efpic_delivery_sync_save_job(array $config, array $job): void
{
    $slug = (string) ($job['slug'] ?? '');
    if ($slug === '') {
        throw new InvalidArgumentException('Job bez slug');
    }
    $job['updated_at'] = gmdate('c');
    $dir = efpic_delivery_sync_queue_dir($config);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    efpic_write_json_file(efpic_delivery_sync_job_path($config, $slug), $job);
}

/**
 * @return array{ok: bool, job: array<string, mixed>, started: bool}
 */
function efpic_delivery_sync_enqueue(array $config, string $slug): array
{
    $meta = efpic_load_gallery_meta($config, $slug);
    if ($meta === null || !efpic_is_delivery_gallery($meta)) {
        throw new RuntimeException('Galerija nav atrasta');
    }

    $existing = efpic_delivery_sync_load_job($config, $slug);
    $status = (string) ($existing['status'] ?? '');
    if (($status === 'queued' || $status === 'running') && is_array($existing)) {
        return ['ok' => true, 'job' => $existing, 'started' => false];
    }

    $job = [
        'slug' => $slug,
        'status' => 'queued',
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
        'started_at' => '',
        'finished_at' => '',
        'claimed_at' => '',
        'error' => '',
        'warnings' => [],
        'sync_stats' => [],
        'dims_started' => false,
    ];
    efpic_delivery_sync_save_job($config, $job);

    return ['ok' => true, 'job' => $job, 'started' => true];
}

/**
 * @return array{ok: bool, job: array<string, mixed>|null, active: bool}
 */
function efpic_delivery_sync_status(array $config, string $slug): array
{
    $job = efpic_delivery_sync_load_job($config, $slug);
    if ($job === null) {
        return ['ok' => true, 'job' => null, 'active' => false];
    }
    $status = (string) ($job['status'] ?? '');
    $active = $status === 'queued' || $status === 'running';

    return ['ok' => true, 'job' => $job, 'active' => $active];
}

/**
 * @return array{ok: bool, job: array<string, mixed>|null, ran: bool, dims_queued: bool}
 */
function efpic_delivery_sync_process_slug(array $config, string $slug): array
{
    @set_time_limit(0);
    @ignore_user_abort(true);

    $job = efpic_delivery_sync_load_job($config, $slug);
    if ($job === null) {
        return ['ok' => true, 'job' => null, 'ran' => false, 'dims_queued' => false];
    }

    $status = (string) ($job['status'] ?? '');
    if ($status === 'done' || $status === 'failed') {
        return ['ok' => true, 'job' => $job, 'ran' => false, 'dims_queued' => false];
    }

    $claimedAt = strtotime((string) ($job['claimed_at'] ?? '')) ?: 0;
    // Sync var ilgt vairākas minūtes — atbloķē tikai pēc 10 min.
    if ($status === 'running' && $claimedAt > 0 && (time() - $claimedAt) < 600) {
        return ['ok' => true, 'job' => $job, 'ran' => false, 'dims_queued' => false, 'busy' => true];
    }

    $job['status'] = 'running';
    $job['claimed_at'] = gmdate('c');
    if ((string) ($job['started_at'] ?? '') === '') {
        $job['started_at'] = gmdate('c');
    }
    $job['error'] = '';
    efpic_delivery_sync_save_job($config, $job);

    try {
        $syncResult = efpic_sync_delivery_gallery($config, $slug);
        $meta = efpic_load_gallery_meta($config, $slug);
        if ($meta !== null) {
            $paired = (int) (($meta['failiem']['sync_stats']['paired'] ?? 0));
            efpic_gallery_log_activity(
                $config,
                $slug,
                $meta,
                'sync',
                'Sinhronizēts no Failiem (' . $paired . ' pāri)',
                'admin',
            );
        }

        $job['status'] = 'done';
        $job['finished_at'] = gmdate('c');
        $job['claimed_at'] = '';
        $job['error'] = '';
        $job['warnings'] = is_array($syncResult['warnings'] ?? null) ? $syncResult['warnings'] : [];
        $job['sync_stats'] = is_array($syncResult['stats'] ?? null) ? $syncResult['stats'] : [];
        $job['dimensions_stats'] = is_array($syncResult['dimensions_stats'] ?? null)
            ? $syncResult['dimensions_stats']
            : [];

        $enq = efpic_dims_backfill_enqueue($config, $slug, false);
        $dimsJob = is_array($enq['job'] ?? null) ? $enq['job'] : [];
        $dimsQueued = in_array((string) ($dimsJob['status'] ?? ''), ['queued', 'running'], true);
        $job['dims_started'] = $dimsQueued;
        efpic_delivery_sync_save_job($config, $job);

        return [
            'ok' => true,
            'job' => $job,
            'ran' => true,
            'dims_queued' => $dimsQueued,
        ];
    } catch (Throwable $e) {
        $job['status'] = 'failed';
        $job['finished_at'] = gmdate('c');
        $job['claimed_at'] = '';
        $job['error'] = $e->getMessage();
        efpic_delivery_sync_save_job($config, $job);

        return [
            'ok' => false,
            'job' => $job,
            'ran' => true,
            'dims_queued' => false,
            'error' => $e->getMessage(),
        ];
    }
}

function efpic_delivery_sync_kick_async(array $config, string $slug): void
{
    $slug = trim($slug);
    if ($slug === '') {
        return;
    }
    $token = (string) ($config['api_token'] ?? '');
    $base = rtrim(efpic_base_url($config), '/');
    if ($token === '' || $base === '') {
        return;
    }
    $url = $base . '/api/delivery-sync/run';
    if (!function_exists('curl_init')) {
        return;
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['slug' => $slug]),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 1,
    ]);
    @curl_exec($ch);
    curl_close($ch);
}

/** Sync + pēc tam izmēri — pēc HTTP atbildes. */
function efpic_delivery_sync_run_background(array $config, string $slug): void
{
    if (function_exists('session_write_close')) {
        @session_write_close();
    }
    @set_time_limit(0);
    @ignore_user_abort(true);

    $result = efpic_delivery_sync_process_slug($config, $slug);
    if (!empty($result['busy'])) {
        return;
    }
    if (!empty($result['dims_queued'])) {
        efpic_dims_backfill_run_background_chain($config, $slug, 180);
    }
}

function efpic_json_response_then_delivery_sync(array $config, int $code, array $data, string $slug): void
{
    register_shutdown_function(static function () use ($config, $slug): void {
        efpic_delivery_sync_run_background($config, $slug);
    });

    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Connection: close');
    $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        $body = '{"ok":false}';
    }
    header('Content-Length: ' . (string) strlen($body));
    echo $body;

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }
    exit;
}

function efpic_redirect_then_delivery_sync(array $config, string $location, string $slug): void
{
    register_shutdown_function(static function () use ($config, $slug): void {
        efpic_delivery_sync_run_background($config, $slug);
    });

    header('Location: ' . $location);
    header('Connection: close');
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }
    exit;
}

function efpic_handle_delivery_sync_queue_run(array $config): void
{
    efpic_require_token($config);
    @set_time_limit(0);
    @ignore_user_abort(true);

    $slug = trim((string) ($_POST['slug'] ?? $_GET['slug'] ?? ''));
    if ($slug === '') {
        $dir = efpic_delivery_sync_queue_dir($config);
        $processed = 0;
        if (is_dir($dir)) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
                $job = efpic_read_json_file($path);
                if (!is_array($job)) {
                    continue;
                }
                $st = (string) ($job['status'] ?? '');
                if ($st !== 'queued' && $st !== 'running') {
                    continue;
                }
                $s = (string) ($job['slug'] ?? '');
                if ($s === '') {
                    continue;
                }
                efpic_delivery_sync_run_background($config, $s);
                $processed++;
                if ($processed >= 2) {
                    break;
                }
            }
        }
        efpic_json_response(200, ['ok' => true, 'processed' => $processed]);
    }

    efpic_delivery_sync_run_background($config, $slug);
    $job = efpic_delivery_sync_load_job($config, $slug);
    efpic_json_response(200, [
        'ok' => true,
        'slug' => $slug,
        'job' => $job,
    ]);
}

/**
 * Kopējais statuss adminam: sync + dims.
 *
 * @return array<string, mixed>
 */
function efpic_admin_gallery_background_status(array $config, string $slug): array
{
    $sync = efpic_delivery_sync_status($config, $slug);
    $dims = efpic_dims_backfill_status($config, $slug);
    $syncJob = is_array($sync['job'] ?? null) ? $sync['job'] : null;
    $dimsJob = is_array($dims['job'] ?? null) ? $dims['job'] : null;
    $stats = is_array($dims['stats'] ?? null)
        ? $dims['stats']
        : efpic_gallery_image_dimensions_stats(efpic_load_gallery_meta($config, $slug) ?? []);

    $syncActive = !empty($sync['active']);
    $dimsActive = !empty($dims['active']);
    $syncStatus = (string) ($syncJob['status'] ?? '');
    $phase = 'idle';
    $message = '';

    if ($syncActive) {
        $phase = 'sync';
        $message = 'Sinhronizēju no Failiem serverī… Vari aizvērt lapu — process turpinās.';
    } elseif ($syncStatus === 'failed') {
        $phase = 'error';
        $message = 'Sinhronizācija neizdevās: ' . (string) ($syncJob['error'] ?? 'kļūda');
    } elseif ($dimsActive) {
        $phase = 'dims';
        $done = (int) ($stats['with_dims'] ?? 0);
        $total = (int) ($stats['total'] ?? 0);
        $message = 'Ievācu izmērus serverī… ' . $done . ' / ' . $total
            . '. Vari aizvērt lapu — process turpinās.';
    } elseif ($syncStatus === 'done' && (int) ($stats['missing'] ?? 0) <= 0 && (int) ($stats['stale'] ?? 0) <= 0) {
        $phase = 'done';
        $paired = (int) (($syncJob['sync_stats']['paired'] ?? 0));
        $message = 'Gatavs'
            . ($paired > 0 ? ' — sync ' . $paired . ' pāri' : '')
            . ', izmēri ' . (int) ($stats['with_dims'] ?? 0) . ' / ' . (int) ($stats['total'] ?? 0) . '.';
    } elseif ($syncStatus === 'done') {
        $phase = 'dims';
        $message = 'Sync gatavs. Izmēri: ' . (int) ($stats['with_dims'] ?? 0) . ' / ' . (int) ($stats['total'] ?? 0) . '.';
    }

    $meta = efpic_load_gallery_meta($config, $slug);
    $failiem = is_array($meta['failiem'] ?? null) ? $meta['failiem'] : [];

    return [
        'ok' => true,
        'phase' => $phase,
        'message' => $message,
        'active' => $syncActive || $dimsActive,
        'sync' => [
            'active' => $syncActive,
            'status' => $syncStatus,
            'job' => $syncJob,
            'error' => (string) ($syncJob['error'] ?? ''),
            'warnings' => is_array($syncJob['warnings'] ?? null) ? $syncJob['warnings'] : [],
            'stats' => is_array($syncJob['sync_stats'] ?? null) ? $syncJob['sync_stats'] : [],
        ],
        'dims' => [
            'active' => $dimsActive,
            'job' => $dimsJob,
            'stats' => $stats,
        ],
        'paired' => (int) (($failiem['sync_stats']['paired'] ?? 0)),
        'image_count' => count($meta['images'] ?? []),
        'last_sync_at' => (string) ($failiem['last_sync_at'] ?? ''),
    ];
}

/** Saglabā Failiem mapju URL no POST pirms fona sync. */
function efpic_admin_save_failiem_folders_from_post(array $config, string $slug): void
{
    $meta = efpic_load_gallery_meta($config, $slug);
    if ($meta === null) {
        throw new RuntimeException('Galerija nav atrasta');
    }
    if (!isset($meta['failiem']) || !is_array($meta['failiem'])) {
        $meta['failiem'] = [];
    }
    $meta['failiem']['folder_parent_url'] = trim((string) ($_POST['folder_parent_url'] ?? $meta['failiem']['folder_parent_url'] ?? ''));
    $meta['failiem']['folder_parent_hash'] = efpic_failiem_parse_folder_hash($meta['failiem']['folder_parent_url']);
    $meta['failiem']['folder_full_url'] = trim((string) ($_POST['folder_full_url'] ?? $meta['failiem']['folder_full_url'] ?? ''));
    $meta['failiem']['folder_web_url'] = trim((string) ($_POST['folder_web_url'] ?? $meta['failiem']['folder_web_url'] ?? ''));
    $meta['failiem']['folder_video_url'] = trim((string) ($_POST['folder_video_url'] ?? $meta['failiem']['folder_video_url'] ?? ''));
    $meta['failiem']['folder_full_hash'] = efpic_failiem_parse_folder_hash($meta['failiem']['folder_full_url']);
    $meta['failiem']['folder_web_hash'] = efpic_failiem_parse_folder_hash($meta['failiem']['folder_web_url']);
    $meta['failiem']['folder_video_hash'] = efpic_failiem_parse_folder_hash($meta['failiem']['folder_video_url']);
    efpic_save_gallery_meta($config, $slug, $meta);
}

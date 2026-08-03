<?php

declare(strict_types=1);

require_once __DIR__ . '/image_dimensions.php';

function efpic_dims_backfill_queue_dir(array $config): string
{
    return dirname(efpic_storage_path($config)) . DIRECTORY_SEPARATOR . 'dims_backfill_queue';
}

function efpic_dims_backfill_job_path(array $config, string $slug): string
{
    $slug = trim($slug);
    if ($slug === '' || preg_match('/^[a-z0-9-]{1,80}$/', $slug) !== 1) {
        throw new InvalidArgumentException('Nederīgs galerijas slug');
    }

    return efpic_dims_backfill_queue_dir($config) . DIRECTORY_SEPARATOR . $slug . '.json';
}

/** @return array<string, mixed>|null */
function efpic_dims_backfill_load_job(array $config, string $slug): ?array
{
    try {
        return efpic_read_json_file(efpic_dims_backfill_job_path($config, $slug));
    } catch (Throwable) {
        return null;
    }
}

/** @param array<string, mixed> $job */
function efpic_dims_backfill_save_job(array $config, array $job): void
{
    $slug = (string) ($job['slug'] ?? '');
    if ($slug === '') {
        throw new InvalidArgumentException('Job bez slug');
    }
    $job['updated_at'] = gmdate('c');
    $dir = efpic_dims_backfill_queue_dir($config);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    efpic_write_json_file(efpic_dims_backfill_job_path($config, $slug), $job);
}

/**
 * @return array{ok: bool, job: array<string, mixed>, started: bool}
 */
function efpic_dims_backfill_enqueue(array $config, string $slug, bool $force = false): array
{
    $meta = efpic_load_gallery_meta($config, $slug);
    if ($meta === null) {
        throw new RuntimeException('Galerija nav atrasta');
    }

    if ($force) {
        foreach ($meta['images'] ?? [] as &$img) {
            if (is_array($img)) {
                efpic_image_clear_dimensions($img);
            }
        }
        unset($img);
        efpic_save_gallery_meta($config, $slug, $meta);
        $meta = efpic_load_gallery_meta($config, $slug) ?? $meta;
    }

    $stats = efpic_gallery_image_dimensions_stats($meta);
    $existing = efpic_dims_backfill_load_job($config, $slug);
    $status = (string) ($existing['status'] ?? '');
    if (
        !$force
        && ($status === 'queued' || $status === 'running')
        && is_array($existing)
    ) {
        $existing['stats'] = $stats;
        efpic_dims_backfill_save_job($config, $existing);

        return ['ok' => true, 'job' => $existing, 'started' => false];
    }

    if ($stats['missing'] <= 0 && ($stats['stale'] ?? 0) <= 0) {
        $job = [
            'slug' => $slug,
            'status' => 'done',
            'force' => $force,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
            'started_at' => gmdate('c'),
            'finished_at' => gmdate('c'),
            'updated_total' => (int) ($existing['updated_total'] ?? 0),
            'batches' => (int) ($existing['batches'] ?? 0),
            'stats' => $stats,
            'error' => '',
            'claimed_at' => '',
        ];
        efpic_dims_backfill_save_job($config, $job);

        return ['ok' => true, 'job' => $job, 'started' => false];
    }

    $job = [
        'slug' => $slug,
        'status' => 'queued',
        'force' => $force,
        'created_at' => is_array($existing) ? (string) ($existing['created_at'] ?? gmdate('c')) : gmdate('c'),
        'updated_at' => gmdate('c'),
        'started_at' => '',
        'finished_at' => '',
        'updated_total' => $force ? 0 : (int) ($existing['updated_total'] ?? 0),
        'batches' => $force ? 0 : (int) ($existing['batches'] ?? 0),
        'stats' => $stats,
        'error' => '',
        'claimed_at' => '',
    ];
    efpic_dims_backfill_save_job($config, $job);

    return ['ok' => true, 'job' => $job, 'started' => true];
}

/**
 * @return array{ok: bool, job: array<string, mixed>|null, ran: bool, updated: int}
 */
function efpic_dims_backfill_status(array $config, string $slug): array
{
    $job = efpic_dims_backfill_load_job($config, $slug);
    $meta = efpic_load_gallery_meta($config, $slug);
    $stats = efpic_gallery_image_dimensions_stats(is_array($meta) ? $meta : []);
    if ($job === null) {
        return [
            'ok' => true,
            'job' => null,
            'ran' => false,
            'updated' => 0,
            'stats' => $stats,
            'active' => false,
        ];
    }
    $job['stats'] = $stats;
    $status = (string) ($job['status'] ?? '');
    $active = $status === 'queued' || $status === 'running';
    if ($active && $stats['missing'] <= 0 && ($stats['stale'] ?? 0) <= 0) {
        $job['status'] = 'done';
        $job['finished_at'] = gmdate('c');
        $job['error'] = '';
        efpic_dims_backfill_save_job($config, $job);
        $active = false;
    }

    return [
        'ok' => true,
        'job' => $job,
        'ran' => false,
        'updated' => 0,
        'stats' => $stats,
        'active' => $active,
    ];
}

/**
 * Apstrādā vienu galerijas job uz laiku (sekundēs).
 *
 * @return array{ok: bool, job: array<string, mixed>|null, ran: bool, updated: int, stats: array}
 */
function efpic_dims_backfill_process_slug(array $config, string $slug, int $maxRuntimeSec = 25): array
{
    @set_time_limit(max(30, $maxRuntimeSec + 15));
    @ignore_user_abort(true);

    $job = efpic_dims_backfill_load_job($config, $slug);
    if ($job === null) {
        return ['ok' => true, 'job' => null, 'ran' => false, 'updated' => 0, 'stats' => ['total' => 0, 'with_dims' => 0, 'missing' => 0]];
    }

    $status = (string) ($job['status'] ?? '');
    if ($status === 'done' || $status === 'failed') {
        $meta = efpic_load_gallery_meta($config, $slug);
        $stats = efpic_gallery_image_dimensions_stats(is_array($meta) ? $meta : []);
        $job['stats'] = $stats;

        return ['ok' => true, 'job' => $job, 'ran' => false, 'updated' => 0, 'stats' => $stats];
    }

    $nextAttempt = strtotime((string) ($job['next_attempt_at'] ?? '')) ?: 0;
    if ($nextAttempt > time()) {
        $meta = efpic_load_gallery_meta($config, $slug);
        $stats = efpic_gallery_image_dimensions_stats(is_array($meta) ? $meta : []);
        $job['stats'] = $stats;

        return [
            'ok' => true,
            'job' => $job,
            'ran' => false,
            'updated' => 0,
            'stats' => $stats,
            'continues' => false,
            'waiting' => true,
        ];
    }

    // Atbloķē iestrēgušu «running».
    $claimedAt = strtotime((string) ($job['claimed_at'] ?? '')) ?: 0;
    if ($status === 'running' && $claimedAt > 0 && (time() - $claimedAt) < 90) {
        $meta = efpic_load_gallery_meta($config, $slug);
        $stats = efpic_gallery_image_dimensions_stats(is_array($meta) ? $meta : []);
        $job['stats'] = $stats;

        return ['ok' => true, 'job' => $job, 'ran' => false, 'updated' => 0, 'stats' => $stats, 'busy' => true];
    }

    $job['status'] = 'running';
    $job['claimed_at'] = gmdate('c');
    if ((string) ($job['started_at'] ?? '') === '') {
        $job['started_at'] = gmdate('c');
    }
    efpic_dims_backfill_save_job($config, $job);

    $deadline = time() + max(5, $maxRuntimeSec);
    $totalUpdated = 0;
    $batches = 0;
    $stalled = false;
    $stats = ['total' => 0, 'with_dims' => 0, 'missing' => 0, 'stale' => 0];

    try {
        while (time() < $deadline) {
            $meta = efpic_load_gallery_meta($config, $slug);
            if ($meta === null) {
                throw new RuntimeException('Galerija nav atrasta');
            }
            $stats = efpic_gallery_image_dimensions_stats($meta);
            if ($stats['missing'] <= 0 && ($stats['stale'] ?? 0) <= 0) {
                break;
            }
            $updated = efpic_gallery_backfill_image_dimensions(
                $config,
                $slug,
                $meta,
                EFPIC_DIMS_BACKFILL_BATCH,
                true,
            );
            $batches++;
            $totalUpdated += $updated;
            $meta = efpic_load_gallery_meta($config, $slug);
            $stats = efpic_gallery_image_dimensions_stats(is_array($meta) ? $meta : []);
            $job['updated_total'] = (int) ($job['updated_total'] ?? 0) + $updated;
            $job['batches'] = (int) ($job['batches'] ?? 0) + 1;
            $job['stats'] = $stats;
            $job['claimed_at'] = gmdate('c');
            $job['error'] = '';
            $job['next_attempt_at'] = '';
            efpic_dims_backfill_save_job($config, $job);
            if ($updated <= 0) {
                // Failiem neatbild — bez auto-kick, lai nerastos bezgalīga cilpa.
                $stalled = true;
                break;
            }
        }
    } catch (Throwable $e) {
        $job['status'] = 'queued';
        $job['claimed_at'] = '';
        $job['error'] = $e->getMessage();
        $job['next_attempt_at'] = gmdate('c', time() + 45);
        efpic_dims_backfill_save_job($config, $job);

        return [
            'ok' => false,
            'job' => $job,
            'ran' => true,
            'updated' => $totalUpdated,
            'stats' => $stats,
            'error' => $e->getMessage(),
            'continues' => false,
        ];
    }

    $meta = efpic_load_gallery_meta($config, $slug);
    $stats = efpic_gallery_image_dimensions_stats(is_array($meta) ? $meta : []);
    $job['stats'] = $stats;
    $job['claimed_at'] = '';
    $continues = false;
    if ($stats['missing'] <= 0 && ($stats['stale'] ?? 0) <= 0) {
        $job['status'] = 'done';
        $job['finished_at'] = gmdate('c');
        $job['error'] = '';
        $job['next_attempt_at'] = '';
    } else {
        $job['status'] = 'queued';
        if ($stalled) {
            $job['next_attempt_at'] = gmdate('c', time() + 60);
            $job['error'] = 'Failiem neatbildēja — mēģināšu vēlāk';
        } else {
            $job['next_attempt_at'] = '';
            $job['error'] = '';
            $continues = true;
        }
    }
    efpic_dims_backfill_save_job($config, $job);

    return [
        'ok' => true,
        'job' => $job,
        'ran' => $batches > 0,
        'updated' => $totalUpdated,
        'stats' => $stats,
        'continues' => $continues,
    ];
}

/** Uzsāk fonā nākamo apstrādes gabalu (nebloķē pašreizējo atbildi). */
function efpic_dims_backfill_kick_async(array $config, string $slug): void
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
    $url = $base . '/api/dims-backfill/run';
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

/**
 * Pēc HTTP atbildes turpina job un, ja vajag, kick sevi vēlreiz.
 */
function efpic_dims_backfill_finish_response_and_process(array $config, string $slug): void
{
    @set_time_limit(0);
    @ignore_user_abort(true);
    if (function_exists('session_write_close')) {
        @session_write_close();
    }

    $result = efpic_dims_backfill_process_slug($config, $slug, 40);
    if (!empty($result['continues'])) {
        usleep(250000);
        efpic_dims_backfill_kick_async($config, $slug);
    }
}

/** Redirect klientam, tad serverī turpina izmēru ievākšanu (kā ZIP rinda). */
function efpic_redirect_then_dims_backfill(array $config, string $location, string $slug): void
{
    register_shutdown_function(static function () use ($config, $slug): void {
        efpic_dims_backfill_run_background_chain($config, $slug, 180);
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

/** JSON atbilde, tad fonā apstrādā dims job. */
function efpic_json_response_then_dims_backfill(array $config, int $code, array $data, string $slug): void
{
    register_shutdown_function(static function () use ($config, $slug): void {
        efpic_dims_backfill_run_background_chain($config, $slug, 180);
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

/**
 * Apstrādā job gabalos līdz limitem; starp gabaliem mēģina self-HTTP kick
 * (ja kick strādā — šis worker beidzas agrāk).
 */
function efpic_dims_backfill_run_background_chain(array $config, string $slug, int $maxTotalSec = 180): void
{
    if (function_exists('session_write_close')) {
        @session_write_close();
    }
    @set_time_limit(0);
    @ignore_user_abort(true);

    $deadline = time() + max(40, $maxTotalSec);
    while (time() < $deadline) {
        $result = efpic_dims_backfill_process_slug($config, $slug, 40);
        if (empty($result['continues'])) {
            return;
        }
        efpic_dims_backfill_kick_async($config, $slug);
        usleep(900000);
        $job = efpic_dims_backfill_load_job($config, $slug);
        $st = (string) ($job['status'] ?? '');
        $claimedAt = strtotime((string) ($job['claimed_at'] ?? '')) ?: 0;
        // Cits worker jau paņēma — šis beidzas.
        if ($st === 'running' && $claimedAt > 0 && (time() - $claimedAt) < 80) {
            return;
        }
        if ($st === 'done' || $st === 'failed') {
            return;
        }
        usleep(200000);
    }
    // Laiks beidzās — pēdējais kick, lai turpinātu citā pieprasījumā / cron.
    efpic_dims_backfill_kick_async($config, $slug);
}

function efpic_handle_dims_backfill_queue_run(array $config): void
{
    efpic_require_token($config);
    @set_time_limit(0);
    @ignore_user_abort(true);

    $slug = trim((string) ($_POST['slug'] ?? $_GET['slug'] ?? ''));
    if ($slug === '') {
        // Bez slug — apstrādā visas queued job.
        $dir = efpic_dims_backfill_queue_dir($config);
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
                $result = efpic_dims_backfill_process_slug($config, $s, 35);
                $processed++;
                if (!empty($result['continues'])) {
                    efpic_dims_backfill_kick_async($config, $s);
                }
                if ($processed >= 3) {
                    break;
                }
            }
        }
        efpic_json_response(200, ['ok' => true, 'processed' => $processed]);
    }

    $result = efpic_dims_backfill_process_slug($config, $slug, 40);
    if (!empty($result['continues'])) {
        // Kick nākamo gabalu pēc atbildes.
        register_shutdown_function(static function () use ($config, $slug): void {
            usleep(200000);
            efpic_dims_backfill_kick_async($config, $slug);
        });
    }
    efpic_json_response(200, [
        'ok' => true,
        'slug' => $slug,
        'updated' => (int) ($result['updated'] ?? 0),
        'job' => $result['job'] ?? null,
        'stats' => $result['stats'] ?? null,
        'continues' => !empty($result['continues']),
    ]);
}

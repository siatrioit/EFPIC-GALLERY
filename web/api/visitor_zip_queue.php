<?php

declare(strict_types=1);

require_once __DIR__ . '/visitor_collections.php';

function efpic_visitor_zip_queue_dir(array $config): string
{
    return dirname(efpic_storage_path($config)) . DIRECTORY_SEPARATOR . 'visitor_zip_queue';
}

function efpic_visitor_zip_job_path(array $config, string $jobId): string
{
    if (preg_match('/^[a-f0-9]{32}$/', $jobId) !== 1) {
        throw new InvalidArgumentException('Nederīgs job ID');
    }

    return efpic_visitor_zip_queue_dir($config) . DIRECTORY_SEPARATOR . $jobId . '.json';
}

/** @return array<string, mixed>|null */
function efpic_visitor_zip_load_job(array $config, string $jobId): ?array
{
    return efpic_read_json_file(efpic_visitor_zip_job_path($config, $jobId));
}

/** @param array<string, mixed> $job */
function efpic_visitor_zip_save_job(array $config, array $job): void
{
    $id = (string) ($job['id'] ?? '');
    if ($id === '') {
        throw new InvalidArgumentException('Job bez ID');
    }
    $job['updated_at'] = gmdate('c');
    $dir = efpic_visitor_zip_queue_dir($config);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    efpic_write_json_file(efpic_visitor_zip_job_path($config, $id), $job);
}

function efpic_visitor_zip_stuck_seconds(): int
{
    return 90 * 60;
}

function efpic_visitor_zip_run_maintenance(array $config): void
{
    $dir = efpic_visitor_zip_queue_dir($config);
    if (!is_dir($dir)) {
        return;
    }
    $now = time();
    $stuckSec = efpic_visitor_zip_stuck_seconds();
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
        $job = efpic_read_json_file($path);
        if (!is_array($job)) {
            continue;
        }
        if ((string) ($job['status'] ?? '') !== 'processing') {
            continue;
        }
        $claimed = strtotime((string) ($job['claimed_at'] ?? '')) ?: 0;
        if ($claimed <= 0 || ($now - $claimed) <= $stuckSec) {
            continue;
        }
        $job['status'] = 'failed';
        $job['error'] = 'ZIP sagatavošanas timeout';
        efpic_visitor_zip_save_job($config, $job);
    }
}

/**
 * @return array<string, mixed>|null
 */
function efpic_visitor_zip_find_active_job(
    array $config,
    string $slug,
    string $visitorId,
    string $size,
    string $type = 'visitor_collections',
    string $guestToken = '',
): ?array {
    $dir = efpic_visitor_zip_queue_dir($config);
    if (!is_dir($dir)) {
        return null;
    }
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
        $job = efpic_read_json_file($path);
        if (!is_array($job)) {
            continue;
        }
        if ((string) ($job['slug'] ?? '') !== $slug) {
            continue;
        }
        if ((string) ($job['visitor_id'] ?? '') !== $visitorId) {
            continue;
        }
        if ((string) ($job['size'] ?? '') !== $size) {
            continue;
        }
        if ((string) ($job['type'] ?? 'visitor_collections') !== $type) {
            continue;
        }
        if ($type === 'share_all' && (string) ($job['guest_token'] ?? '') !== $guestToken) {
            continue;
        }
        $status = (string) ($job['status'] ?? '');
        if (in_array($status, ['queued', 'processing'], true)) {
            return $job;
        }
    }

    return null;
}

/**
 * @return array{ok: bool, job_id?: string, already_queued?: bool, error?: string}
 */
function efpic_visitor_zip_enqueue_collections_job(
    array $config,
    string $slug,
    array $meta,
    array $ctx,
    string $galleryToken,
    string $visitorId,
    string $size,
): array {
    if (!efpic_can_download_collection_zip($meta, $ctx, $size)) {
        return ['ok' => false, 'error' => 'download_disabled'];
    }

    $pending = efpic_visitor_zip_find_active_job($config, $slug, $visitorId, $size, 'visitor_collections');
    if ($pending !== null) {
        return ['ok' => true, 'job_id' => (string) ($pending['id'] ?? ''), 'already_queued' => true];
    }

    $data = efpic_visitor_collections_load($config, $slug);
    $visitor = efpic_visitor_get_visitor($data, $visitorId);
    if ($visitor === null) {
        return ['ok' => false, 'error' => 'not_found'];
    }

    $hasImages = false;
    foreach (efpic_visitor_collections_for_visitor($data, $visitorId) as $collection) {
        $tokens = is_array($collection['image_tokens'] ?? null) ? $collection['image_tokens'] : [];
        if ($tokens !== []) {
            $hasImages = true;
            break;
        }
    }
    if (!$hasImages) {
        return ['ok' => false, 'error' => 'empty_collection'];
    }

    $jobId = efpic_random_hex(16);
    $collectionIds = efpic_visitor_zip_job_collection_ids($data, $visitorId);
    efpic_visitor_zip_save_job($config, [
        'id' => $jobId,
        'type' => 'visitor_collections',
        'slug' => $slug,
        'gallery_token' => $galleryToken,
        'visitor_id' => $visitorId,
        'guest_token' => '',
        'size' => $size,
        'status' => 'queued',
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
        'claimed_at' => '',
        'error' => '',
        'collections_prepared' => 0,
        'collection_ids' => $collectionIds,
        'prepared' => [],
        'email_sent' => false,
    ]);

    efpic_visitor_log_zip_email_request(
        $config,
        $slug,
        $meta,
        $visitor,
        $visitorId,
        $size,
        $data,
        $collectionIds,
    );

    return ['ok' => true, 'job_id' => $jobId];
}

/**
 * @return array{ok: bool, job_id?: string, already_queued?: bool, error?: string}
 */
function efpic_visitor_zip_enqueue_share_all_job(
    array $config,
    string $slug,
    array $meta,
    array $ctx,
    string $galleryToken,
    string $visitorId,
    string $size,
    string $guestToken,
): array {
    if (!efpic_viewer_is_restricted_share($ctx)) {
        return ['ok' => false, 'error' => 'not_share_link'];
    }
    if (!efpic_can_download_share_set_zip($meta, $ctx, $size)) {
        return ['ok' => false, 'error' => 'download_disabled'];
    }

    $pending = efpic_visitor_zip_find_active_job($config, $slug, $visitorId, $size, 'share_all', $guestToken);
    if ($pending !== null) {
        return ['ok' => true, 'job_id' => (string) ($pending['id'] ?? ''), 'already_queued' => true];
    }

    if (efpic_client_navigable_images($meta, $ctx) === []) {
        return ['ok' => false, 'error' => 'empty_collection'];
    }

    $data = efpic_visitor_collections_load($config, $slug);
    $visitor = efpic_visitor_get_visitor($data, $visitorId);
    if ($visitor === null) {
        return ['ok' => false, 'error' => 'not_found'];
    }

    $shareLabel = trim((string) ($ctx['share_label'] ?? ''));
    if ($shareLabel === '') {
        $shareLabel = 'Izlase';
    }
    $images = efpic_client_navigable_images($meta, $ctx);

    $jobId = efpic_random_hex(16);
    efpic_visitor_zip_save_job($config, [
        'id' => $jobId,
        'type' => 'share_all',
        'slug' => $slug,
        'gallery_token' => $galleryToken,
        'visitor_id' => $visitorId,
        'guest_token' => $guestToken,
        'size' => $size,
        'status' => 'queued',
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
        'claimed_at' => '',
        'error' => '',
        'collections_prepared' => 0,
        'collection_ids' => [],
        'prepared' => [],
        'email_sent' => false,
    ]);

    $summaries = [['name' => $shareLabel, 'count' => count($images)]];
    efpic_gallery_log_activity(
        $config,
        $slug,
        $meta,
        'visitor_share_download',
        efpic_visitor_zip_activity_message($visitor, $size, $summaries, 'request'),
        'visitor:' . (string) ($visitor['email'] ?? ''),
        efpic_visitor_zip_activity_extra($visitor, $visitorId, $size, $summaries),
    );

    return ['ok' => true, 'job_id' => $jobId];
}

/** @param array<string, mixed> $job */
function efpic_visitor_zip_process_job(array $config, array $job): void
{
    $slug = (string) ($job['slug'] ?? '');
    $galleryToken = (string) ($job['gallery_token'] ?? '');
    $visitorId = (string) ($job['visitor_id'] ?? '');
    $size = (string) ($job['size'] ?? 'web');
    $type = (string) ($job['type'] ?? 'visitor_collections');

    $found = efpic_find_gallery_by_token($config, $galleryToken);
    if ($found === null) {
        $job['status'] = 'failed';
        $job['error'] = 'gallery_not_found';
        efpic_visitor_zip_save_job($config, $job);

        return;
    }

    $meta = $found['meta'];
    $ctx = efpic_viewer_context($config, $meta);
    if ($type === 'share_all') {
        $guestToken = (string) ($job['guest_token'] ?? '');
        if ($guestToken !== '' && ($ctx['guest_token'] ?? '') !== $guestToken) {
            $_GET['g'] = $guestToken;
            $ctx = efpic_viewer_context($config, $meta);
        }
    }

    @set_time_limit(0);
    @ignore_user_abort(true);

    $data = efpic_visitor_collections_load($config, $slug);
    $visitor = efpic_visitor_get_visitor($data, $visitorId);
    if ($visitor === null) {
        $job['status'] = 'failed';
        $job['error'] = 'not_found';
        efpic_visitor_zip_save_job($config, $job);

        return;
    }

    $advance = efpic_visitor_zip_advance_job($config, $job, $meta, $ctx);
    if (empty($advance['ok'])) {
        $job['status'] = 'failed';
        $job['error'] = (string) ($advance['error'] ?? 'zip_build_failed');
        efpic_visitor_zip_save_job($config, $job);

        return;
    }

    $prepared = is_array($job['prepared'] ?? null) ? $job['prepared'] : [];
    $job['collections_prepared'] = count($prepared);

    if (!empty($advance['done'])) {
        if ($prepared === []) {
            $job['status'] = 'failed';
            $job['error'] = 'empty_collection';
            efpic_visitor_zip_save_job($config, $job);

            return;
        }
        if (empty($job['email_sent'])) {
            try {
                efpic_visitor_zip_finalize_job_email($config, $slug, $meta, $visitor, $prepared, $size, $visitorId);
                $job['email_sent'] = true;
            } catch (Throwable $e) {
                $job['status'] = 'failed';
                $job['error'] = 'zip_verify_failed';
                efpic_visitor_zip_save_job($config, $job);

                return;
            }
        }
        $job['status'] = 'done';
        $job['error'] = '';
        efpic_visitor_zip_save_job($config, $job);

        return;
    }

    $job['status'] = 'queued';
    $job['claimed_at'] = '';
    $job['error'] = '';
    efpic_visitor_zip_save_job($config, $job);
}

function efpic_visitor_zip_run_pending(array $config, int $limit = 1): int
{
    efpic_visitor_zip_run_maintenance($config);
    $dir = efpic_visitor_zip_queue_dir($config);
    if (!is_dir($dir)) {
        return 0;
    }

    $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    sort($files);
    $processed = 0;

    foreach ($files as $path) {
        if ($processed >= $limit) {
            break;
        }
        $job = efpic_read_json_file($path);
        if (!is_array($job)) {
            continue;
        }
        if ((string) ($job['status'] ?? '') !== 'queued') {
            continue;
        }
        $job['status'] = 'processing';
        $job['claimed_at'] = gmdate('c');
        efpic_visitor_zip_save_job($config, $job);
        efpic_visitor_zip_process_job($config, $job);
        $processed++;
    }

    return $processed;
}

function efpic_visitor_zip_process_job_chain(array $config, string $jobId, int $maxSteps = 50): void
{
    $steps = 0;
    while ($steps < $maxSteps) {
        $job = efpic_visitor_zip_load_job($config, $jobId);
        if ($job === null) {
            return;
        }
        $status = (string) ($job['status'] ?? '');
        if ($status !== 'queued') {
            return;
        }
        $job['status'] = 'processing';
        $job['claimed_at'] = gmdate('c');
        efpic_visitor_zip_save_job($config, $job);
        efpic_visitor_zip_process_job($config, $job);
        $steps++;
        $job = efpic_visitor_zip_load_job($config, $jobId);
        if ($job === null) {
            return;
        }
        $status = (string) ($job['status'] ?? '');
        if ($status === 'done' || $status === 'failed') {
            return;
        }
    }
}

function efpic_visitor_zip_finish_response_and_process(array $config, ?string $preferJobId = null): void
{
    @set_time_limit(0);
    @ignore_user_abort(true);

    if ($preferJobId !== null && $preferJobId !== '') {
        efpic_visitor_zip_process_job_chain($config, $preferJobId, 50);
        efpic_visitor_zip_run_pending($config, 3);

        return;
    }

    efpic_visitor_zip_run_pending($config, 3);
}

function efpic_json_response_then_process(array $config, int $code, array $data, ?string $jobId = null): void
{
    register_shutdown_function(static function () use ($config, $jobId): void {
        if (function_exists('session_write_close')) {
            @session_write_close();
        }
        @set_time_limit(0);
        @ignore_user_abort(true);
        efpic_visitor_zip_finish_response_and_process($config, $jobId);
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

function efpic_handle_visitor_zip_queue_run(array $config): void
{
    efpic_require_token($config);
    @set_time_limit(0);
    @ignore_user_abort(true);
    $count = efpic_visitor_zip_run_pending($config, 5);
    efpic_json_response(200, ['ok' => true, 'processed' => $count]);
}

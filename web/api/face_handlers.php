<?php

declare(strict_types=1);

require_once __DIR__ . '/face_index.php';
require_once __DIR__ . '/image_dimensions.php';
require_once __DIR__ . '/failiem_client.php';
require_once __DIR__ . '/failiem_face.php';
require_once __DIR__ . '/gallery_access.php';

function efpic_handle_face_ping(array $config): void
{
    efpic_require_token($config);
    efpic_face_worker_touch($config, 'ping');
    efpic_json_response(200, [
        'ok' => true,
        'service' => 'efpic-face',
        'app_version' => efpic_app_version(),
    ]);
}

function efpic_handle_face_claim_job(array $config): void
{
    efpic_require_token($config);
    efpic_face_worker_touch($config, 'claim');
    $payload = efpic_face_claim_next_job($config);
    if ($payload === null) {
        efpic_json_response(200, ['ok' => true, 'job' => null]);
    }
    efpic_json_response(200, $payload);
}

function efpic_handle_face_job_selfie(array $config, string $jobId): void
{
    efpic_require_token($config);
    $path = efpic_face_job_selfie_path($config, $jobId);
    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}

function efpic_handle_face_job_image(array $config, string $jobId, string $imageToken): void
{
    efpic_require_token($config);
    $job = efpic_face_load_job($config, $jobId);
    if (!is_array($job)) {
        http_response_code(404);
        exit;
    }
    $allowed = false;
    $url = '';
    foreach ($job['images'] ?? [] as $img) {
        if (is_array($img) && (string) ($img['token'] ?? '') === $imageToken) {
            $allowed = true;
            $url = (string) ($img['url'] ?? '');
            break;
        }
    }
    if (!$allowed) {
        http_response_code(404);
        exit;
    }
    if (!empty($url)) {
        $binary = efpic_fetch_binary_quick($config, $url, 20);
        if ($binary !== null) {
            header('Content-Type: image/jpeg');
            header('Cache-Control: no-store');
            echo $binary;
            exit;
        }
    }
    $slug = (string) ($job['slug'] ?? '');
    $meta = $slug !== '' ? efpic_load_gallery_meta($config, $slug) : null;
    if ($meta === null) {
        http_response_code(404);
        exit;
    }
    foreach ($meta['images'] ?? [] as $img) {
        if (!is_array($img) || (string) ($img['token'] ?? '') !== $imageToken) {
            continue;
        }
        $hash = trim((string) ($img['failiem_web']['file_hash'] ?? ''));
        if ($hash === '') {
            break;
        }
        $thumbUrl = efpic_failiem_thumb_url($config, $hash, 960);
        $binary = efpic_fetch_binary_quick($config, $thumbUrl, 20);
        if ($binary !== null) {
            header('Content-Type: image/jpeg');
            header('Cache-Control: no-store');
            echo $binary;
            exit;
        }
    }
    http_response_code(404);
    exit;
}

function efpic_handle_face_job_batch(array $config, string $jobId): void
{
    efpic_require_token($config);
    $job = efpic_face_load_job($config, $jobId);
    if (!is_array($job) || ($job['type'] ?? '') !== 'index') {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_found']);
    }
    $raw = file_get_contents('php://input');
    $body = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($body) || !is_array($body['results'] ?? null)) {
        efpic_json_response(400, ['ok' => false, 'error' => 'invalid_payload']);
    }
    $slug = (string) ($job['slug'] ?? '');
    $meta = efpic_load_gallery_meta($config, $slug);
    if ($meta === null) {
        efpic_json_response(404, ['ok' => false, 'error' => 'gallery_not_found']);
    }
    $updated = efpic_face_apply_index_results($config, $slug, $meta, $body['results']);
    efpic_json_response(200, ['ok' => true, 'updated' => $updated]);
}

function efpic_handle_face_job_complete(array $config, string $jobId): void
{
    efpic_require_token($config);
    $job = efpic_face_load_job($config, $jobId);
    if (!is_array($job)) {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_found']);
    }
    $slug = (string) ($job['slug'] ?? '');
    $type = (string) ($job['type'] ?? 'index');

    if ($type === 'search') {
        $raw = file_get_contents('php://input');
        $body = is_string($raw) ? json_decode($raw, true) : null;
        $faces = is_array($body) ? ($body['faces'] ?? null) : null;
        if (!is_array($faces) || $faces === []) {
            $job['status'] = 'failed';
            $job['error'] = 'Seja selfijā nav atrasta';
            efpic_face_save_job($config, $job);
            efpic_json_response(200, ['ok' => true, 'status' => 'failed']);
        }
        $meta = efpic_load_gallery_meta($config, $slug);
        if ($meta === null) {
            efpic_json_response(404, ['ok' => false, 'error' => 'gallery_not_found']);
        }
        $ctx = efpic_viewer_context($config, $meta);
        $allowed = [];
        foreach (efpic_client_navigable_images($meta, $ctx) as $img) {
            if (is_array($img)) {
                $tok = (string) ($img['token'] ?? '');
                if ($tok !== '') {
                    $allowed[$tok] = true;
                }
            }
        }
        $threshold = (float) ($job['threshold'] ?? efpic_face_match_threshold($config));
        $tokens = efpic_face_search_tokens($config, $slug, $faces, $threshold, $allowed);
        $job['status'] = 'complete';
        $job['result'] = ['tokens' => $tokens, 'count' => count($tokens)];
        $job['completed_at'] = gmdate('c');
        efpic_face_save_job($config, $job);
        @unlink(efpic_face_job_selfie_path($config, $jobId));
        efpic_json_response(200, ['ok' => true, 'status' => 'complete', 'count' => count($tokens)]);
    }

    $meta = efpic_load_gallery_meta($config, $slug);
    if ($meta !== null) {
        efpic_face_update_gallery_status($config, $slug, $meta);
        efpic_save_gallery_meta($config, $slug, $meta);
        if (efpic_gallery_face_search_enabled($meta)) {
            $stats = efpic_face_index_stats($config, $slug, $meta);
            if ($stats['pending'] > 0 && !efpic_face_slug_has_queued_job($config, $slug)) {
                efpic_face_enqueue_index_batch($config, $slug, $meta);
            }
        }
    }
    $job['status'] = 'complete';
    $job['completed_at'] = gmdate('c');
    efpic_face_save_job($config, $job);
    efpic_json_response(200, ['ok' => true, 'status' => 'complete']);
}

function efpic_handle_face_job_fail(array $config, string $jobId): void
{
    efpic_require_token($config);
    $job = efpic_face_load_job($config, $jobId);
    if (!is_array($job)) {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_found']);
    }
    $raw = file_get_contents('php://input');
    $body = is_string($raw) ? json_decode($raw, true) : null;
    $message = trim((string) (is_array($body) ? ($body['error'] ?? $body['message'] ?? '') : ''));
    if ($message === '') {
        $message = 'Face worker kļūda';
    }
    $attempts = (int) ($job['attempts'] ?? 0) + 1;
    $job['attempts'] = $attempts;
    $job['error'] = $message;
    if ($attempts < 3 && ($job['type'] ?? '') === 'index') {
        $job['status'] = 'queued';
        efpic_face_save_job($config, $job);
        efpic_json_response(200, ['ok' => true, 'status' => 'queued', 'retried' => true]);
    }
    $job['status'] = 'failed';
    efpic_face_save_job($config, $job);
    if (($job['type'] ?? '') === 'search') {
        @unlink(efpic_face_job_selfie_path($config, $jobId));
    } else {
        $slug = (string) ($job['slug'] ?? '');
        $meta = efpic_load_gallery_meta($config, $slug);
        if ($meta !== null) {
            efpic_face_update_gallery_status($config, $slug, $meta, [
                'status' => 'failed',
                'error' => $message,
            ]);
            efpic_save_gallery_meta($config, $slug, $meta);
        }
    }
    efpic_json_response(200, ['ok' => true, 'status' => 'failed', 'retried' => false]);
}

function efpic_handle_client_face_search_start(array $config, string $galleryToken): void
{
    if (!efpic_csrf_verify((string) ($_POST['csrf_token'] ?? ''))) {
        efpic_json_response(403, ['ok' => false, 'error' => 'csrf']);
    }
    $found = efpic_find_gallery_by_token($config, $galleryToken);
    if ($found === null) {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_found']);
    }
    $meta = $found['meta'];
    $slug = $found['slug'];
    if (!efpic_gallery_face_search_enabled($meta)) {
        efpic_json_response(403, ['ok' => false, 'error' => 'disabled']);
    }
    $fs = efpic_gallery_face_search($meta);
    if (($fs['status'] ?? '') !== 'ready') {
        efpic_json_response(409, [
            'ok' => false,
            'error' => 'not_ready',
            'status' => (string) ($fs['status'] ?? 'none'),
            'message' => 'Seju indekss vēl nav gatavs — mēģini vēlāk.',
        ]);
    }
    if (!isset($_FILES['selfie']) || !is_array($_FILES['selfie'])) {
        efpic_json_response(400, ['ok' => false, 'error' => 'missing_selfie']);
    }
    $file = $_FILES['selfie'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        efpic_json_response(400, ['ok' => false, 'error' => 'upload_error']);
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        efpic_json_response(400, ['ok' => false, 'error' => 'invalid_upload']);
    }
    if ((int) ($file['size'] ?? 0) > 8 * 1024 * 1024) {
        efpic_json_response(413, ['ok' => false, 'error' => 'file_too_large']);
    }
    $info = @getimagesize($tmp);
    if ($info === false) {
        efpic_json_response(400, ['ok' => false, 'error' => 'not_an_image']);
    }

    try {
        $jobId = efpic_face_enqueue_search($config, $slug, $galleryToken, $tmp);
    } catch (Throwable $e) {
        efpic_json_response(500, ['ok' => false, 'error' => $e->getMessage()]);
    }

    $pollUrl = efpic_gallery_view_url($config, $galleryToken) . '/face-search/' . $jobId;
    efpic_json_response(200, [
        'ok' => true,
        'search_id' => $jobId,
        'poll_url' => $pollUrl,
        'poll_ms' => EFPIC_FACE_SEARCH_POLL_MS,
    ]);
}

function efpic_handle_client_face_search_poll(array $config, string $galleryToken, string $searchId): void
{
    $found = efpic_find_gallery_by_token($config, $galleryToken);
    if ($found === null) {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_found']);
    }
    $job = efpic_face_load_job($config, $searchId);
    if (!is_array($job) || ($job['type'] ?? '') !== 'search') {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_found']);
    }
    if ((string) ($job['gallery_token'] ?? '') !== $galleryToken) {
        efpic_json_response(403, ['ok' => false, 'error' => 'forbidden']);
    }
    $state = efpic_face_search_job_public_state($config, $job);
    efpic_json_response(200, array_merge(['ok' => true], $state));
}

function efpic_handle_client_face_status(array $config, string $galleryToken): void
{
    $found = efpic_find_gallery_by_token($config, $galleryToken);
    if ($found === null) {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_found']);
    }
    $meta = $found['meta'];
    $slug = $found['slug'];
    if (!efpic_gallery_face_search_enabled($meta)) {
        efpic_json_response(200, ['ok' => true, 'enabled' => false]);
    }
    if (efpic_gallery_face_search_uses_failiem($meta)) {
        $failiem = efpic_failiem_face_admin_status($config, $slug, $meta);
        efpic_json_response(200, array_merge(['ok' => true, 'enabled' => true], $failiem));

        return;
    }
    $fs = efpic_gallery_face_search($meta);
    $stats = efpic_face_index_stats($config, $slug, $meta);
    efpic_json_response(200, [
        'ok' => true,
        'enabled' => true,
        'provider' => 'local',
        'status' => (string) ($fs['status'] ?? 'none'),
        'indexed_images' => $stats['indexed'],
        'total_faces' => $stats['total_faces'],
        'pending_images' => $stats['pending'],
        'ready' => ($fs['status'] ?? '') === 'ready' && $stats['pending'] <= 0,
    ]);
}

function efpic_handle_client_face_persons(array $config, string $galleryToken): void
{
    $found = efpic_find_gallery_by_token($config, $galleryToken);
    if ($found === null) {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_found']);
    }
    $meta = $found['meta'];
    $slug = $found['slug'];
    if (!efpic_gallery_face_search_enabled($meta) || !efpic_gallery_face_search_uses_failiem($meta)) {
        efpic_json_response(403, ['ok' => false, 'error' => 'disabled']);
    }
    $ctx = efpic_viewer_context($config, $meta);
    $refresh = (($_GET['refresh'] ?? '') === '1');
    $payload = efpic_failiem_face_public_persons($config, $slug, $meta, $ctx, $refresh);
    efpic_json_response(200, $payload);
}

function efpic_handle_client_face_person_tokens(array $config, string $galleryToken): void
{
    $found = efpic_find_gallery_by_token($config, $galleryToken);
    if ($found === null) {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_found']);
    }
    $meta = $found['meta'];
    $slug = $found['slug'];
    if (!efpic_gallery_face_search_enabled($meta) || !efpic_gallery_face_search_uses_failiem($meta)) {
        efpic_json_response(403, ['ok' => false, 'error' => 'disabled']);
    }
    $rawIds = trim((string) ($_GET['ids'] ?? ''));
    if ($rawIds === '') {
        efpic_json_response(400, ['ok' => false, 'error' => 'missing_ids']);
    }
    $personIds = array_values(array_filter(array_map('trim', explode(',', $rawIds)), static fn ($id) => $id !== ''));
    if ($personIds === []) {
        efpic_json_response(400, ['ok' => false, 'error' => 'missing_ids']);
    }
    $ctx = efpic_viewer_context($config, $meta);
    $bundle = efpic_failiem_face_fetch_bundle($config, $slug, $meta);
    if (empty($bundle['ok'])) {
        efpic_json_response(502, ['ok' => false, 'error' => (string) ($bundle['error'] ?? 'failiem_error')]);
    }
    $tokens = efpic_failiem_face_tokens_for_persons(
        $meta,
        $ctx,
        $personIds,
        is_array($bundle['person_images'] ?? null) ? $bundle['person_images'] : [],
        is_array($bundle['person_images_ids_hashes'] ?? null) ? $bundle['person_images_ids_hashes'] : []
    );
    efpic_json_response(200, [
        'ok' => true,
        'count' => count($tokens),
        'tokens' => $tokens,
    ]);
}

/** @return array<string, mixed> */
function efpic_admin_face_failiem_refresh(array $config, string $slug): array
{
    $meta = efpic_load_gallery_meta($config, $slug);
    if ($meta === null) {
        return ['ok' => false, 'error' => 'not_found'];
    }
    if (!efpic_gallery_face_search_uses_failiem($meta)) {
        return ['ok' => false, 'error' => 'not_failiem'];
    }
    @unlink(efpic_failiem_face_cache_path($config, $slug));
    efpic_failiem_face_fetch_bundle($config, $slug, $meta, true);
    $status = efpic_failiem_face_admin_status($config, $slug, $meta);

    return array_merge(['ok' => true], $status);
}

function efpic_admin_face_index_gallery(array $config, string $slug): array
{
    $meta = efpic_load_gallery_meta($config, $slug);
    if ($meta === null) {
        return ['ok' => false, 'error' => 'not_found'];
    }
    if (!efpic_gallery_face_search_enabled($meta)) {
        return ['ok' => false, 'error' => 'disabled'];
    }
    efpic_face_queue_gallery_index($config, $slug, $meta);
    $meta = efpic_load_gallery_meta($config, $slug) ?? $meta;
    $fs = efpic_gallery_face_search($meta);
    $stats = efpic_face_index_stats($config, $slug, $meta);

    return [
        'ok' => true,
        'status' => (string) ($fs['status'] ?? 'none'),
        'stats' => $stats,
        'worker' => efpic_face_worker_status($config),
    ];
}

/** @return array<string, mixed> */
function efpic_admin_face_clear_queue(array $config, string $slug): array
{
    $meta = efpic_load_gallery_meta($config, $slug);
    if ($meta === null) {
        return ['ok' => false, 'error' => 'not_found'];
    }
    $removed = efpic_face_purge_queue_for_slug($config, $slug);
    if (!isset($meta['face_search']) || !is_array($meta['face_search'])) {
        $meta['face_search'] = efpic_gallery_face_search_defaults();
    }
    $stats = efpic_face_index_stats($config, $slug, $meta);
    if (!efpic_gallery_face_search_enabled($meta)) {
        $meta['face_search']['status'] = 'none';
    } elseif ($stats['pending'] <= 0) {
        $meta['face_search']['status'] = 'ready';
        $meta['face_search']['error'] = '';
    } elseif ($stats['indexed'] > 0) {
        $meta['face_search']['status'] = 'queued';
        $meta['face_search']['error'] = '';
    } else {
        $meta['face_search']['status'] = 'none';
        $meta['face_search']['error'] = '';
    }
    efpic_save_gallery_meta($config, $slug, $meta);
    $meta = efpic_load_gallery_meta($config, $slug) ?? $meta;
    $fs = efpic_gallery_face_search($meta);

    return [
        'ok' => true,
        'removed' => $removed,
        'status' => (string) ($fs['status'] ?? 'none'),
        'stats' => $stats,
        'queue' => efpic_face_queue_stats_for_slug($config, $slug),
        'worker' => efpic_face_worker_status($config),
    ];
}

/** @return array<string, mixed> */
function efpic_admin_face_worker_test(array $config, string $slug): array
{
    $meta = efpic_load_gallery_meta($config, $slug);
    if ($meta === null) {
        return ['ok' => false, 'error' => 'not_found'];
    }
    $diag = efpic_face_worker_diagnostic($config, $slug);
    $fs = efpic_gallery_face_search($meta);
    $stats = efpic_face_index_stats($config, $slug, $meta);

    return array_merge(['ok' => true], $diag, [
        'checked_at' => gmdate('c'),
        'index_status' => (string) ($fs['status'] ?? 'none'),
        'index_stats' => $stats,
        'face_enabled' => efpic_gallery_face_search_enabled($meta),
    ]);
}

function efpic_admin_render_face_search_panel(array $config, array $meta, string $slug): string
{
    $fs = efpic_gallery_face_search($meta);
    $enabled = !empty($fs['enabled']);
    $provider = (string) ($fs['provider'] ?? 'local');
    $failiem = $provider === 'failiem';
    $stats = efpic_face_index_stats($config, $slug, $meta);
    $worker = efpic_face_worker_status($config);
    $queue = efpic_face_queue_stats_for_slug($config, $slug);
    $status = (string) ($fs['status'] ?? 'none');
    $failiemStatus = $failiem ? efpic_failiem_face_cached_status($config, $slug, $meta) : null;
    $statusLabel = $failiem
        ? (($failiemStatus['ready'] ?? false)
            ? 'Gatavs (Failiem)'
            : (($failiemStatus['error'] ?? '') !== ''
                ? 'Nav ielādēts'
                : (($failiemStatus['processing_error'] ?? false) ? 'Failiem kļūda' : 'Gaida Failiem')))
        : match ($status) {
            'ready' => 'Gatavs',
            'indexing', 'queued' => 'Indeksē…',
            'failed' => 'Kļūda',
            default => 'Nav indeksēts',
        };

    $html = '<fieldset class="admin-fieldset-full" id="admin-face-search-panel"><legend>Seju meklēšana</legend>';
    $html .= '<input type="hidden" name="face_search_enabled" value="0">';
    $html .= efpic_render_admin_toggle('Ieslēgt seju meklēšanu publiskajā galerijā', $enabled, [
        'name' => 'face_search_enabled',
        'value' => '1',
    ]);
    $html .= '<p class="muted admin-face-provider">';
    $html .= '<label><input type="radio" name="face_search_provider" value="local"'
        . ($provider !== 'failiem' ? ' checked' : '') . '> Synology worker (selfijs)</label> ';
    $html .= '<label><input type="radio" name="face_search_provider" value="failiem"'
        . ($failiem ? ' checked' : '') . '> Failiem (tests — personu saraksts)</label>';
    $html .= '</p>';
    if ($failiem) {
        $html .= '<p class="muted">Izmanto Failiem mapes seju indeksu. Viesi izvēlas seju no saraksta (bez selfija). '
            . 'Web mapes hash: <code>' . efpic_admin_esc(efpic_failiem_face_upload_hash($meta) ?: '—') . '</code></p>';
        $html .= '<p class="muted"><label>Pārrakstīt Failiem mapes hash (tests): '
            . '<input type="text" name="face_search_failiem_upload_hash" class="admin-input-sm" value="'
            . efpic_admin_esc((string) ($fs['failiem_upload_hash'] ?? ''))
            . '" placeholder="piem. 3zucdnkj8a"></label></p>';
    } else {
        $html .= '<p class="muted">Viesi var augšupielādēt selfiju un redzēt bildes, kurās viņi atrodas. '
            . 'Indeksēšana notiek fonā caur Synology face worker (InsightFace).</p>';
    }
    if ($failiem && is_array($failiemStatus) && ($failiemStatus['error'] ?? '') !== '') {
        $html .= '<p class="muted" id="admin-face-failiem-hint">' . efpic_admin_esc((string) $failiemStatus['error']) . '</p>';
    }
    $html .= '<p class="muted admin-face-status" id="admin-face-status">Statuss: <strong id="admin-face-status-label">'
        . efpic_admin_esc($statusLabel) . '</strong>';
    if ($failiem && is_array($failiemStatus)) {
        $html .= ' · personas <strong id="admin-face-person-count">' . (int) ($failiemStatus['person_count'] ?? 0) . '</strong>';
        $html .= ' · hash <strong id="admin-face-failiem-hash">' . efpic_admin_esc((string) ($failiemStatus['upload_hash'] ?? '')) . '</strong>';
    } else {
        $html .= ' · indeksētas <strong id="admin-face-indexed">' . (int) $stats['indexed'] . '</strong>';
        $html .= ' · sejas <strong id="admin-face-count">' . (int) $stats['total_faces'] . '</strong>';
        if ($stats['pending'] > 0) {
            $html .= ' · gaida <strong id="admin-face-pending">' . (int) $stats['pending'] . '</strong> bildes';
        }
        $html .= ' · rindā <strong id="admin-face-queue">' . (int) $queue['total'] . '</strong> jobi';
        $html .= ' · worker: <strong id="admin-face-worker">' . efpic_admin_esc($worker['status_label']) . '</strong>';
    }
    $html .= '</p>';
    if (($fs['error'] ?? '') !== '') {
        $html .= '<p class="err" id="admin-face-error">' . efpic_admin_esc((string) $fs['error']) . '</p>';
    }
    if ($failiem) {
        $html .= '<button type="button" class="btn admin-btn-sm" id="admin-face-failiem-refresh-btn">Atsvaidzināt no Failiem</button>';
    } else {
        $html .= '<button type="button" class="btn admin-btn-sm" id="admin-face-index-btn">Indeksēt / turpināt</button>';
        $html .= ' <button type="button" class="btn admin-btn-sm" id="admin-face-test-btn">Pārbaudīt NAS</button>';
        $html .= ' <button type="button" class="btn admin-btn-sm admin-btn-danger" id="admin-face-clear-btn">Notīrīt rindu</button>';
    }
    $html .= ' <span class="admin-face-index-msg muted" id="admin-face-index-msg" hidden></span>';
    $html .= '<div class="admin-face-test-result muted" id="admin-face-test-result" hidden></div>';
    $html .= '</fieldset>';

    return $html;
}

function efpic_apply_face_search_from_post(array &$meta): void
{
    if (!isset($meta['face_search']) || !is_array($meta['face_search'])) {
        $meta['face_search'] = efpic_gallery_face_search_defaults();
    }
    $wasEnabled = !empty($meta['face_search']['enabled']);
    $meta['face_search']['enabled'] = efpic_post_flag_is_on('face_search_enabled');
    $provider = trim((string) ($_POST['face_search_provider'] ?? 'local'));
    $meta['face_search']['provider'] = $provider === 'failiem' ? 'failiem' : 'local';
    $meta['face_search']['failiem_upload_hash'] = efpic_failiem_parse_folder_hash(
        trim((string) ($_POST['face_search_failiem_upload_hash'] ?? ''))
    );
    if ($meta['face_search']['provider'] === 'failiem') {
        $meta['face_search']['status'] = 'ready';
        $meta['face_search']['error'] = '';
    } elseif ($meta['face_search']['enabled'] && !$wasEnabled && ($meta['face_search']['status'] ?? 'none') === 'none') {
        $meta['face_search']['status'] = 'queued';
    }
    if (!$meta['face_search']['enabled']) {
        $meta['face_search']['status'] = 'none';
    }
}

function efpic_client_render_face_person_modal(): string
{
    return '<div class="face-search-modal" id="facePersonModal" hidden>'
        . '<div class="face-search-dialog face-person-dialog" role="dialog" aria-labelledby="facePersonTitle" aria-modal="true">'
        . '<button type="button" class="face-search-close" data-face-person-close aria-label="Aizvērt">&times;</button>'
        . '<h2 id="facePersonTitle">Meklēt pēc sejas</h2>'
        . '<p class="muted">Izvēlies vienu vai vairākas sejas — parādīsim tikai attiecīgās fotogrāfijas.</p>'
        . '<div class="face-person-grid" id="facePersonGrid" aria-live="polite"></div>'
        . '<p class="face-search-status muted" id="facePersonStatus" hidden></p>'
        . '<div class="face-person-actions">'
        . '<button type="button" class="btn" id="facePersonDeselect" disabled>Noņemt izvēli</button>'
        . '<button type="button" class="btn primary" id="facePersonApply" disabled>Rādīt bildes</button>'
        . '</div></div></div>';
}

function efpic_client_render_face_search_modal(): string
{
    return '<div class="face-search-modal" id="faceSearchModal" hidden>'
        . '<div class="face-search-dialog" role="dialog" aria-labelledby="faceSearchTitle" aria-modal="true">'
        . '<button type="button" class="face-search-close" data-face-search-close aria-label="Aizvērt">&times;</button>'
        . '<h2 id="faceSearchTitle">Atrodi sevi</h2>'
        . '<p class="muted">Augšupielādē selfiju vai uzņem bildi — parādīsim tikai tās fotogrāfijas, kurās tu atrodies.</p>'
        . '<div class="face-search-preview" id="faceSearchPreview" hidden>'
        . '<img id="faceSearchPreviewImg" alt="" width="160" height="160"></div>'
        . '<label class="face-search-upload">'
        . '<input type="file" id="faceSearchInput" accept="image/jpeg,image/png,image/webp" capture="user">'
        . '<span class="btn primary">Izvēlēties selfiju</span></label>'
        . '<button type="button" class="btn primary" id="faceSearchSubmit" disabled>Meklēt</button>'
        . '<p class="face-search-status muted" id="faceSearchStatus" hidden></p>'
        . '<p class="muted face-search-privacy">Selfijs netiek saglabāts galerijā — izmanto tikai meklēšanai.</p>'
        . '</div></div>';
}

function efpic_client_face_search_ready(array $config, string $slug, array $meta): bool
{
    if (!efpic_gallery_face_search_enabled($meta)) {
        return false;
    }
    if (efpic_gallery_face_search_uses_failiem($meta)) {
        return efpic_failiem_face_upload_hash($meta) !== '';
    }
    $fs = efpic_gallery_face_search($meta);
    if (($fs['status'] ?? '') !== 'ready') {
        return false;
    }
    $stats = efpic_face_index_stats($config, $slug, $meta);

    return $stats['pending'] <= 0 && $stats['total_faces'] > 0;
}

function efpic_client_face_search_mode(array $meta): string
{
    return efpic_gallery_face_search_uses_failiem($meta) ? 'failiem' : 'selfie';
}

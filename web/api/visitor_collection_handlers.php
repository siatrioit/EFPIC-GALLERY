<?php

declare(strict_types=1);

require_once __DIR__ . '/visitor_collections.php';
require_once __DIR__ . '/visitor_zip_queue.php';

function efpic_visitor_collection_gallery_context(array $config, string $galleryToken): ?array
{
    $found = efpic_find_gallery_by_token($config, $galleryToken);
    if ($found === null) {
        return null;
    }
    $meta = $found['meta'];
    if (efpic_gallery_expired($meta)) {
        return null;
    }
    if (efpic_gallery_has_password($meta) && !efpic_gallery_session_unlocked($galleryToken)) {
        return null;
    }
    $ctx = efpic_viewer_context($config, $meta);
    if (efpic_viewer_context_access_denied($ctx)) {
        return null;
    }
    if (!efpic_can_use_public_collection($meta)) {
        return null;
    }

    return [
        'found' => $found,
        'meta' => $meta,
        'slug' => $found['slug'],
        'ctx' => $ctx,
    ];
}

function efpic_handle_visitor_identify(array $config, string $galleryToken): void
{
    efpic_csrf_require();
    $ctxPack = efpic_visitor_collection_gallery_context($config, $galleryToken);
    if ($ctxPack === null) {
        efpic_json_response(403, ['ok' => false, 'error' => 'forbidden']);
    }
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $createCollection = false;

    try {
        $result = efpic_visitor_identify(
            $config,
            $ctxPack['slug'],
            $ctxPack['meta'],
            $galleryToken,
            $name,
            $email,
            null,
            $createCollection,
        );
    } catch (InvalidArgumentException $e) {
        efpic_json_response(400, ['ok' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        efpic_json_response(500, ['ok' => false, 'error' => $e->getMessage()]);
    }

    $active = null;
    foreach ($result['collections'] as $collection) {
        if (($collection['id'] ?? '') === $result['active_collection_id']) {
            $active = $collection;
            break;
        }
    }

    efpic_json_response(200, [
        'ok' => true,
        'visitor' => [
            'name' => (string) ($result['visitor']['name'] ?? ''),
            'email' => (string) ($result['visitor']['email'] ?? ''),
        ],
        'collections' => array_map(static function (array $collection): array {
            $tokens = is_array($collection['image_tokens'] ?? null) ? $collection['image_tokens'] : [];

            return [
                'id' => (string) ($collection['id'] ?? ''),
                'name' => (string) ($collection['name'] ?? ''),
                'count' => count($tokens),
            ];
        }, $result['collections']),
        'active_collection' => efpic_visitor_collection_public_summary($active),
        'active_tokens' => efpic_visitor_active_collection_token_map(
            $config,
            $ctxPack['slug'],
            $galleryToken,
            $ctxPack['meta'],
            $ctxPack['ctx'],
        ),
    ]);
}

function efpic_handle_visitor_status(array $config, string $galleryToken): void
{
    $ctxPack = efpic_visitor_collection_gallery_context($config, $galleryToken);
    if ($ctxPack === null) {
        efpic_json_response(403, ['ok' => false, 'error' => 'forbidden']);
    }
    $status = efpic_visitor_public_status(
        $config,
        $ctxPack['slug'],
        $ctxPack['meta'],
        $galleryToken,
        $ctxPack['ctx'],
    );
    if ($status === null) {
        efpic_json_response(200, ['ok' => true, 'authenticated' => false]);
    }

    efpic_json_response(200, [
        'ok' => true,
        'authenticated' => true,
        'visitor' => $status['visitor'],
        'collections' => $status['collections'],
        'active_collection' => efpic_visitor_collection_public_summary($status['active_collection']),
        'active_tokens' => $status['active_tokens'],
    ]);
}

function efpic_handle_visitor_collection_create(array $config, string $galleryToken): void
{
    efpic_csrf_require();
    $ctxPack = efpic_visitor_collection_gallery_context($config, $galleryToken);
    if ($ctxPack === null) {
        efpic_json_response(403, ['ok' => false, 'error' => 'forbidden']);
    }
    $session = efpic_visitor_session_state($galleryToken);
    if ($session === null) {
        efpic_json_response(401, ['ok' => false, 'error' => 'not_authenticated']);
    }
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        efpic_json_response(400, ['ok' => false, 'error' => 'missing_name']);
    }
    $data = efpic_visitor_collections_load($config, $ctxPack['slug']);
    $collectionId = efpic_visitor_create_collection_record($data, $session['visitor_id'], $name);
    efpic_visitor_collections_save($config, $ctxPack['slug'], $data);
    efpic_visitor_set_session($galleryToken, $session['visitor_id'], $collectionId);
    $visitor = efpic_visitor_get_visitor($data, $session['visitor_id']);
    if ($visitor !== null) {
        efpic_gallery_log_activity(
            $config,
            $ctxPack['slug'],
            $ctxPack['meta'],
            'visitor_collection_create',
            ($visitor['name'] ?? '') . ' izveidoja izlasi «' . $name . '»',
            'visitor:' . ($visitor['email'] ?? ''),
            ['visitor_id' => $session['visitor_id'], 'collection_id' => $collectionId],
        );
    }

    efpic_json_response(200, [
        'ok' => true,
        'active_collection' => efpic_visitor_collection_public_summary($data['collections'][$collectionId] ?? null),
        'collections' => array_map(static function (array $collection): array {
            $tokens = is_array($collection['image_tokens'] ?? null) ? $collection['image_tokens'] : [];

            return [
                'id' => (string) ($collection['id'] ?? ''),
                'name' => (string) ($collection['name'] ?? ''),
                'count' => count($tokens),
            ];
        }, efpic_visitor_collections_for_visitor($data, $session['visitor_id'])),
        'active_tokens' => [],
    ]);
}

function efpic_handle_visitor_collection_activate(array $config, string $galleryToken, string $collectionId): void
{
    efpic_csrf_require();
    $ctxPack = efpic_visitor_collection_gallery_context($config, $galleryToken);
    if ($ctxPack === null) {
        efpic_json_response(403, ['ok' => false, 'error' => 'forbidden']);
    }
    $session = efpic_visitor_session_state($galleryToken);
    if ($session === null) {
        efpic_json_response(401, ['ok' => false, 'error' => 'not_authenticated']);
    }
    $data = efpic_visitor_collections_load($config, $ctxPack['slug']);
    $collection = efpic_visitor_get_collection($data, $collectionId);
    if ($collection === null || (string) ($collection['visitor_id'] ?? '') !== $session['visitor_id']) {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_found']);
    }
    efpic_visitor_set_session($galleryToken, $session['visitor_id'], $collectionId);

    efpic_json_response(200, [
        'ok' => true,
        'active_collection' => efpic_visitor_collection_public_summary($collection),
        'active_tokens' => efpic_visitor_active_collection_token_map(
            $config,
            $ctxPack['slug'],
            $galleryToken,
            $ctxPack['meta'],
            $ctxPack['ctx'],
        ),
    ]);
}

function efpic_handle_visitor_collection_toggle(array $config, string $galleryToken, string $collectionId): void
{
    efpic_csrf_require();
    $ctxPack = efpic_visitor_collection_gallery_context($config, $galleryToken);
    if ($ctxPack === null) {
        efpic_json_response(403, ['ok' => false, 'error' => 'forbidden']);
    }
    $session = efpic_visitor_session_state($galleryToken);
    if ($session === null) {
        efpic_json_response(401, ['ok' => false, 'error' => 'not_authenticated']);
    }
    if ($collectionId !== $session['active_collection_id']) {
        efpic_json_response(409, ['ok' => false, 'error' => 'inactive_collection']);
    }
    $imageToken = trim((string) ($_POST['image_token'] ?? ''));
    if ($imageToken === '') {
        efpic_json_response(400, ['ok' => false, 'error' => 'missing_token']);
    }
    $allowed = false;
    foreach (efpic_client_navigable_images($ctxPack['meta'], $ctxPack['ctx']) as $img) {
        if (is_array($img) && ($img['token'] ?? '') === $imageToken) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_visible']);
    }

    try {
        $result = efpic_visitor_collection_toggle(
            $config,
            $ctxPack['slug'],
            $ctxPack['meta'],
            $galleryToken,
            $session['visitor_id'],
            $collectionId,
            $imageToken,
        );
    } catch (Throwable $e) {
        efpic_json_response(500, ['ok' => false, 'error' => $e->getMessage()]);
    }

    efpic_json_response(200, [
        'ok' => true,
        'in_collection' => $result['in_collection'],
        'count' => $result['count'],
        'active_collection' => efpic_visitor_collection_public_summary($result['collection']),
    ]);
}

function efpic_handle_visitor_collection_download_request(array $config, string $galleryToken, string $collectionId): void
{
    unset($collectionId);
    efpic_handle_visitor_all_collections_download_request($config, $galleryToken);
}

function efpic_handle_visitor_all_collections_download_request(array $config, string $galleryToken): void
{
    efpic_csrf_require();
    $ctxPack = efpic_visitor_collection_gallery_context($config, $galleryToken);
    if ($ctxPack === null) {
        efpic_json_response(403, ['ok' => false, 'error' => 'forbidden']);
    }
    $session = efpic_visitor_session_state($galleryToken);
    if ($session === null) {
        efpic_json_response(401, ['ok' => false, 'error' => 'not_authenticated']);
    }
    $size = strtolower(trim((string) ($_POST['size'] ?? 'web')));
    if (!in_array($size, ['web', 'full'], true)) {
        efpic_json_response(400, ['ok' => false, 'error' => 'invalid_size']);
    }

    $result = efpic_visitor_zip_enqueue_collections_job(
        $config,
        $ctxPack['slug'],
        $ctxPack['meta'],
        $ctxPack['ctx'],
        $galleryToken,
        $session['visitor_id'],
        $size,
    );
    if (empty($result['ok'])) {
        $err = (string) ($result['error'] ?? 'error');
        $code = match ($err) {
            'empty_collection' => 400,
            'download_disabled' => 403,
            default => 500,
        };
        efpic_json_response($code, ['ok' => false, 'error' => $err]);
    }

    $already = !empty($result['already_queued']);
    $message = $already
        ? 'Tava izlase jau tiek sagatavota fonā. Saņemsi e-pastu ar lejupielādes saiti, kad ZIP būs gatavs.'
        : 'ZIP faili tiek veidoti fonā. Saņemsi e-pastu ar lejupielādes saiti, kad viss būs gatavs.';

    efpic_json_response_then_process($config, 200, [
        'ok' => true,
        'queued' => true,
        'message' => $message,
    ], (string) ($result['job_id'] ?? ''));
}

function efpic_handle_visitor_share_download_request(array $config, string $galleryToken): void
{
    efpic_csrf_require();
    $ctxPack = efpic_visitor_collection_gallery_context($config, $galleryToken);
    if ($ctxPack === null) {
        efpic_json_response(403, ['ok' => false, 'error' => 'forbidden']);
    }
    if (!efpic_viewer_is_restricted_share($ctxPack['ctx'])) {
        efpic_json_response(400, ['ok' => false, 'error' => 'not_share_link']);
    }
    $session = efpic_visitor_session_state($galleryToken);
    if ($session === null) {
        efpic_json_response(401, ['ok' => false, 'error' => 'not_authenticated']);
    }
    $size = strtolower(trim((string) ($_POST['size'] ?? 'web')));
    if (!in_array($size, ['web', 'full'], true)) {
        efpic_json_response(400, ['ok' => false, 'error' => 'invalid_size']);
    }

    $guestToken = (string) ($ctxPack['ctx']['guest_token'] ?? '');
    $result = efpic_visitor_zip_enqueue_share_all_job(
        $config,
        $ctxPack['slug'],
        $ctxPack['meta'],
        $ctxPack['ctx'],
        $galleryToken,
        $session['visitor_id'],
        $size,
        $guestToken,
    );
    if (empty($result['ok'])) {
        $err = (string) ($result['error'] ?? 'error');
        $code = match ($err) {
            'empty_collection' => 400,
            'download_disabled' => 403,
            default => 500,
        };
        efpic_json_response($code, ['ok' => false, 'error' => $err]);
    }

    $already = !empty($result['already_queued']);
    $message = $already
        ? 'Lejupielāde jau tiek sagatavota fonā. Saņemsi e-pastu, kad ZIP būs gatavs.'
        : 'ZIP ar visām izlases bildēm tiek veidots fonā. Saņemsi e-pastu ar lejupielādes saiti, kad būs gatavs.';

    efpic_json_response_then_process($config, 200, [
        'ok' => true,
        'queued' => true,
        'message' => $message,
    ], (string) ($result['job_id'] ?? ''));
}

function efpic_handle_visitor_collection_add_tokens(array $config, string $galleryToken, string $collectionId): void
{
    efpic_csrf_require();
    $ctxPack = efpic_visitor_collection_gallery_context($config, $galleryToken);
    if ($ctxPack === null) {
        efpic_json_response(403, ['ok' => false, 'error' => 'forbidden']);
    }
    $session = efpic_visitor_session_state($galleryToken);
    if ($session === null) {
        efpic_json_response(401, ['ok' => false, 'error' => 'not_authenticated']);
    }
    if ($collectionId !== $session['active_collection_id']) {
        efpic_json_response(409, ['ok' => false, 'error' => 'inactive_collection']);
    }

    $raw = trim((string) ($_POST['image_tokens'] ?? ''));
    if ($raw === '') {
        efpic_json_response(400, ['ok' => false, 'error' => 'missing_tokens']);
    }
    $requested = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($t) => $t !== ''));
    if ($requested === []) {
        efpic_json_response(400, ['ok' => false, 'error' => 'missing_tokens']);
    }

    $allowed = [];
    foreach (efpic_client_navigable_images($ctxPack['meta'], $ctxPack['ctx']) as $img) {
        if (!is_array($img)) {
            continue;
        }
        $tok = (string) ($img['token'] ?? '');
        if ($tok !== '') {
            $allowed[$tok] = true;
        }
    }
    $tokens = array_values(array_filter($requested, static fn ($t) => isset($allowed[$t])));
    if ($tokens === []) {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_visible']);
    }

    try {
        $result = efpic_visitor_collection_add_tokens(
            $config,
            $ctxPack['slug'],
            $ctxPack['meta'],
            $galleryToken,
            $session['visitor_id'],
            $collectionId,
            $tokens,
        );
    } catch (Throwable $e) {
        efpic_json_response(500, ['ok' => false, 'error' => $e->getMessage()]);
    }

    efpic_json_response(200, [
        'ok' => true,
        'added' => $result['added'],
        'count' => $result['count'],
        'active_collection' => efpic_visitor_collection_public_summary($result['collection']),
        'active_tokens' => efpic_visitor_active_collection_token_map(
            $config,
            $ctxPack['slug'],
            $galleryToken,
            $ctxPack['meta'],
            $ctxPack['ctx'],
        ),
    ]);
}

function efpic_handle_visitor_collection_rename(array $config, string $galleryToken, string $collectionId): void
{
    efpic_csrf_require();
    $ctxPack = efpic_visitor_collection_gallery_context($config, $galleryToken);
    if ($ctxPack === null) {
        efpic_json_response(403, ['ok' => false, 'error' => 'forbidden']);
    }
    $session = efpic_visitor_session_state($galleryToken);
    if ($session === null) {
        efpic_json_response(401, ['ok' => false, 'error' => 'not_authenticated']);
    }
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        efpic_json_response(400, ['ok' => false, 'error' => 'missing_name']);
    }

    try {
        $result = efpic_visitor_collection_rename(
            $config,
            $ctxPack['slug'],
            $ctxPack['meta'],
            $session['visitor_id'],
            $collectionId,
            $name,
        );
    } catch (InvalidArgumentException $e) {
        efpic_json_response(400, ['ok' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        efpic_json_response(500, ['ok' => false, 'error' => $e->getMessage()]);
    }

    $data = efpic_visitor_collections_load($config, $ctxPack['slug']);
    if ($session['active_collection_id'] === $collectionId) {
        efpic_visitor_set_session($galleryToken, $session['visitor_id'], $collectionId);
    }
    $activeCollection = $data['collections'][$session['active_collection_id']] ?? null;

    efpic_json_response(200, [
        'ok' => true,
        'active_collection' => efpic_visitor_collection_public_summary($activeCollection),
        'collections' => array_map(static function (array $collection): array {
            $tokens = is_array($collection['image_tokens'] ?? null) ? $collection['image_tokens'] : [];

            return [
                'id' => (string) ($collection['id'] ?? ''),
                'name' => (string) ($collection['name'] ?? ''),
                'count' => count($tokens),
            ];
        }, efpic_visitor_collections_for_visitor($data, $session['visitor_id'])),
    ]);
}

function efpic_handle_visitor_collection_download(array $config, string $galleryToken, string $downloadToken): void
{
    $found = efpic_find_gallery_by_token($config, $galleryToken);
    if ($found === null) {
        http_response_code(404);
        echo 'Nav atrasts';
        exit;
    }
    $meta = $found['meta'];
    if (efpic_gallery_expired($meta)) {
        http_response_code(403);
        echo 'Galerija vairs nav pieejama';
        exit;
    }
    $data = efpic_visitor_collections_load($config, $found['slug']);
    $job = $data['zip_downloads'][$downloadToken] ?? null;
    if (!is_array($job)) {
        http_response_code(404);
        echo 'Lejupielāde nav atrasta';
        exit;
    }
    $expires = strtotime((string) ($job['expires_at'] ?? ''));
    if ($expires !== false && $expires < time()) {
        http_response_code(410);
        echo 'Saite vairs nav derīga';
        exit;
    }
    $zipPath = efpic_visitor_zips_dir($config, $found['slug']) . DIRECTORY_SEPARATOR . (string) ($job['file'] ?? '');
    if (!is_file($zipPath) || filesize($zipPath) === 0) {
        http_response_code(404);
        echo 'ZIP fails nav atrasts';
        exit;
    }
    $filename = (string) ($job['filename'] ?? 'izlase.zip');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('Content-Length: ' . (string) filesize($zipPath));
    readfile($zipPath);
    exit;
}

function efpic_handle_visitor_logout(array $config, string $galleryToken): void
{
    efpic_csrf_require();
    efpic_client_session_start();
    unset($_SESSION['efpic_visitor'][$galleryToken]);
    efpic_json_response(200, ['ok' => true]);
}

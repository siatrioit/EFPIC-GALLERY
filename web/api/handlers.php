<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/gallery_access.php';
require_once __DIR__ . '/image_dimensions.php';
require_once __DIR__ . '/delivery.php';
require_once __DIR__ . '/gallery_activity.php';
require_once __DIR__ . '/gallery_notifications.php';

function efpic_handle_health(array $config): void
{
    efpic_json_response(200, [
        'ok' => true,
        'app_version' => $config['app_version'] ?? '1.0.0',
    ]);
}

function efpic_handle_create_gallery(array $config): void
{
    efpic_require_token($config);
    $raw = file_get_contents('php://input');
    $body = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($body)) {
        efpic_json_response(400, ['ok' => false, 'error' => 'invalid_json']);
    }

    $type = (string) ($body['type'] ?? 'live');
    $name = trim((string) ($body['name'] ?? 'Galerija'));
    $slug = trim((string) ($body['slug'] ?? ''));
    if ($slug === '') {
        $slug = efpic_slugify($name);
    }

    if (is_dir(efpic_gallery_dir($config, $slug))) {
        efpic_json_response(409, ['ok' => false, 'error' => 'slug_exists', 'slug' => $slug]);
    }

    if ($type === 'delivery') {
        try {
            $created = efpic_create_delivery_gallery($config, $body);
        } catch (Throwable $e) {
            $code = $e->getCode() === 409 ? 409 : 400;
            efpic_json_response($code, ['ok' => false, 'error' => $e->getMessage()]);
        }
        $meta = $created['meta'];
        $slug = $created['slug'];
    } else {
        $meta = efpic_gallery_defaults($type);
        $meta['name'] = $name;
        mkdir(efpic_gallery_dir($config, $slug), 0755, true);
        efpic_save_gallery_meta($config, $slug, $meta);
    }

    efpic_json_response(201, [
        'ok' => true,
        'gallery' => efpic_gallery_api_summary($config, $slug, $meta),
    ]);
}

function efpic_handle_get_gallery(array $config, string $slug): void
{
    efpic_require_token($config);
    $meta = efpic_load_gallery_meta($config, $slug);
    if ($meta === null) {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_found']);
    }

    efpic_json_response(200, [
        'ok' => true,
        'gallery' => efpic_gallery_api_summary($config, $slug, $meta),
    ]);
}

function efpic_handle_upload_image(array $config, string $slug): void
{
    efpic_require_token($config);
    $meta = efpic_load_gallery_meta($config, $slug);
    if ($meta === null) {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_found']);
    }
    if (efpic_is_delivery_gallery($meta)) {
        efpic_json_response(400, [
            'ok' => false,
            'error' => 'delivery_uses_failiem',
            'message' => 'Delivery galerijām bildes augšupielādē Failiem.lv un sinhronizē.',
        ]);
    }

    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        efpic_json_response(400, ['ok' => false, 'error' => 'missing_file']);
    }

    $file = $_FILES['file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        efpic_json_response(400, ['ok' => false, 'error' => 'upload_error']);
    }

    $max = (int) ($config['max_upload_bytes'] ?? 25 * 1024 * 1024);
    if ((int) ($file['size'] ?? 0) > $max) {
        efpic_json_response(413, ['ok' => false, 'error' => 'file_too_large']);
    }

    $orig = (string) ($file['name'] ?? 'image.jpg');
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowed = $config['allowed_extensions'] ?? ['jpg', 'jpeg'];
    if (!in_array($ext, $allowed, true)) {
        efpic_json_response(400, ['ok' => false, 'error' => 'invalid_extension']);
    }

    $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $orig) ?? 'image.jpg';
    $dir = efpic_gallery_dir($config, $slug);
    $dest = $dir . DIRECTORY_SEPARATOR . $safeName;
    if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
        efpic_json_response(500, ['ok' => false, 'error' => 'save_failed']);
    }

    $token = efpic_random_hex(24);
    $images = $meta['images'] ?? [];
    if (!is_array($images)) {
        $images = [];
    }
    $entry = [
        'token' => $token,
        'file' => $safeName,
        'sort' => count($images) + 1,
        'scene_id' => 'main',
    ];
    $dims = efpic_probe_image_dimensions_from_path($dest);
    if ($dims !== null) {
        $entry['width'] = $dims['width'];
        $entry['height'] = $dims['height'];
    }
    $images[] = $entry;
    $meta['images'] = $images;
    efpic_save_gallery_meta($config, $slug, $meta);

    efpic_json_response(201, [
        'ok' => true,
        'image_view_url' => efpic_image_view_url($config, $token),
        'gallery_view_url' => efpic_gallery_view_url($config, (string) $meta['gallery_token']),
    ]);
}

function efpic_handle_delivery_sync(array $config, string $slug): void
{
    efpic_require_token($config);
    try {
        $result = efpic_sync_delivery_gallery($config, $slug);
        $meta = efpic_load_gallery_meta($config, $slug);
        efpic_json_response(200, [
            'ok' => true,
            'sync' => $result,
            'gallery' => $meta !== null ? efpic_gallery_api_summary($config, $slug, $meta) : null,
        ]);
    } catch (Throwable $e) {
        efpic_json_response(400, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

function efpic_gallery_api_summary(array $config, string $slug, array $meta): array
{
    return [
        'slug' => $slug,
        'type' => $meta['type'] ?? 'live',
        'name' => $meta['name'] ?? $slug,
        'gallery_token' => $meta['gallery_token'] ?? '',
        'public_url' => efpic_gallery_view_url($config, (string) ($meta['gallery_token'] ?? '')),
        'portal_url' => efpic_portal_url($config, (string) ($meta['client_access']['portal_token'] ?? '')),
        'image_count' => count($meta['images'] ?? []),
        'failiem' => $meta['failiem'] ?? null,
    ];
}

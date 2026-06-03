<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/api/handlers.php';
require_once dirname(__DIR__) . '/api/booth_handlers.php';
require_once dirname(__DIR__) . '/api/client_handlers.php';
require_once dirname(__DIR__) . '/api/guest_delivery_handlers.php';
require_once dirname(__DIR__) . '/api/portal_handlers.php';
require_once dirname(__DIR__) . '/api/gallery_assets.php';

$config = efpic_load_config();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
if ($base !== '' && str_starts_with($uri, $base)) {
    $uri = substr($uri, strlen($base)) ?: '/';
}
$uri = '/' . trim($uri, '/');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    if ($uri === '/api/health' || $uri === '/health') {
        efpic_handle_health($config);
    }

    if ($uri === '/api/galleries' && $method === 'POST') {
        efpic_handle_create_gallery($config);
    }

    if (preg_match('#^/api/galleries/([a-z0-9-]+)$#', $uri, $m) && $method === 'GET') {
        efpic_handle_get_gallery($config, $m[1]);
    }

    if (preg_match('#^/api/galleries/([a-z0-9-]+)/images$#', $uri, $m) && $method === 'POST') {
        efpic_handle_upload_image($config, $m[1]);
    }

    if (preg_match('#^/api/delivery-galleries/([a-z0-9-]+)/sync$#', $uri, $m) && $method === 'POST') {
        efpic_handle_delivery_sync($config, $m[1]);
    }

    if ($uri === '/api/guest-delivery/status' && $method === 'GET') {
        efpic_handle_guest_delivery_status($config);
    }

    if ($uri === '/api/guest-delivery/send' && $method === 'POST') {
        efpic_handle_guest_delivery_send($config);
    }

    if ($uri === '/api/booth-events' && $method === 'GET') {
        efpic_handle_list_booth_events($config);
    }

    if (preg_match('#^/api/booth-events/sync/([A-Z0-9]{4,8})$#i', $uri, $m) && $method === 'GET') {
        efpic_handle_get_booth_event_sync($config, strtoupper($m[1]));
    }

    if (preg_match('#^/api/booth-events/([a-f0-9]{32})/frame\.png$#i', $uri, $m) && $method === 'GET') {
        efpic_handle_get_booth_frame($config, $m[1]);
    }

    if (preg_match('#^/v/g/([a-f0-9]{48})/asset/([a-zA-Z0-9._-]+)$#i', $uri, $m) && $method === 'GET') {
        efpic_handle_gallery_asset($config, strtolower($m[1]), $m[2]);
    }

    if (preg_match('#^/v/g/([a-f0-9]{48})/download\.zip$#i', $uri, $m) && $method === 'GET') {
        efpic_handle_client_gallery_zip($config, strtolower($m[1]));
    }

    if (preg_match('#^/c/p/([a-f0-9]{48})$#i', $uri, $m) && ($method === 'GET' || $method === 'POST')) {
        efpic_portal_handle($config, strtolower($m[1]), $method);
    }

    if (preg_match('#^/v/g/([a-f0-9]{48})$#i', $uri, $m) && ($method === 'GET' || $method === 'POST')) {
        efpic_handle_client_gallery($config, strtolower($m[1]), $method);
    }

    if (preg_match('#^/v/i/([a-f0-9]{48})/download$#i', $uri, $m) && $method === 'GET') {
        efpic_handle_client_image_download($config, strtolower($m[1]));
    }

    if (preg_match('#^/v/i/([a-f0-9]{48})$#i', $uri, $m) && $method === 'GET') {
        efpic_handle_client_image($config, strtolower($m[1]), $method);
    }

    if (preg_match('#^/v/media/([a-f0-9]{48})$#i', $uri, $m) && $method === 'GET') {
        efpic_handle_client_media($config, strtolower($m[1]));
    }

    if ($uri === '/client/assets/client.css') {
        header('Content-Type: text/css; charset=utf-8');
        readfile(__DIR__ . '/client/assets/client.css');
        exit;
    }

    if ($uri === '/client/assets/client.js') {
        header('Content-Type: application/javascript; charset=utf-8');
        readfile(__DIR__ . '/client/assets/client.js');
        exit;
    }

    if ($uri === '/admin/assets/admin.css') {
        header('Content-Type: text/css; charset=utf-8');
        readfile(__DIR__ . '/admin/assets/admin.css');
        exit;
    }

    if ($uri === '/admin/assets/admin.js') {
        header('Content-Type: application/javascript; charset=utf-8');
        readfile(__DIR__ . '/admin/assets/admin.js');
        exit;
    }

    efpic_json_response(404, ['ok' => false, 'error' => 'not_found', 'path' => $uri]);
} catch (Throwable $e) {
    efpic_json_response(500, [
        'ok' => false,
        'error' => 'server_error',
        'message' => $e->getMessage(),
    ]);
}

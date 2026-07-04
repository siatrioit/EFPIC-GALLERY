<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/api/handlers.php';
require_once dirname(__DIR__) . '/api/booth_handlers.php';
require_once dirname(__DIR__) . '/api/client_handlers.php';
require_once dirname(__DIR__) . '/api/guest_delivery_handlers.php';
require_once dirname(__DIR__) . '/api/portal_handlers.php';
require_once dirname(__DIR__) . '/api/gallery_assets.php';
require_once dirname(__DIR__) . '/api/render_handlers.php';
require_once dirname(__DIR__) . '/api/face_handlers.php';

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

    if ($uri === '/site/logo' && $method === 'GET') {
        efpic_handle_site_asset($config, 'logo');
    }

    if ($uri === '/site/signature' && $method === 'GET') {
        efpic_handle_site_asset($config, 'signature');
    }

    if (preg_match('#^/site/asset/([a-zA-Z0-9_.-]+)$#', $uri, $m) && $method === 'GET') {
        efpic_handle_site_asset_file($config, $m[1]);
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

    if ($uri === '/api/gallery-notifications/run' && $method === 'POST') {
        efpic_handle_gallery_notifications_run($config);
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

    if (preg_match('#^/v/g/([a-f0-9]{48})/collection/zip$#i', $uri, $m) && $method === 'GET') {
        efpic_handle_client_collection_zip($config, strtolower($m[1]));
    }

    if (preg_match('#^/v/g/([a-f0-9]{48})/collection/toggle$#i', $uri, $m) && $method === 'POST') {
        efpic_handle_client_collection_toggle($config, strtolower($m[1]));
    }

    if (preg_match('#^/v/g/([a-f0-9]{48})/collection/clear$#i', $uri, $m) && $method === 'POST') {
        efpic_handle_client_collection_clear($config, strtolower($m[1]));
    }

    if (preg_match('#^/v/g/([a-f0-9]{48})/visitor/identify$#i', $uri, $m) && $method === 'POST') {
        efpic_handle_visitor_identify($config, strtolower($m[1]));
    }

    if (preg_match('#^/v/g/([a-f0-9]{48})/visitor/status$#i', $uri, $m) && $method === 'GET') {
        efpic_handle_visitor_status($config, strtolower($m[1]));
    }

    if (preg_match('#^/v/g/([a-f0-9]{48})/visitor/logout$#i', $uri, $m) && $method === 'POST') {
        efpic_handle_visitor_logout($config, strtolower($m[1]));
    }

    if (preg_match('#^/v/g/([a-f0-9]{48})/visitor/collections$#i', $uri, $m) && $method === 'POST') {
        efpic_handle_visitor_collection_create($config, strtolower($m[1]));
    }

    if (preg_match('#^/v/g/([a-f0-9]{48})/visitor/collections/([a-z0-9_]+)/activate$#i', $uri, $m) && $method === 'POST') {
        efpic_handle_visitor_collection_activate($config, strtolower($m[1]), $m[2]);
    }

    if (preg_match('#^/v/g/([a-f0-9]{48})/visitor/collections/([a-z0-9_]+)/toggle$#i', $uri, $m) && $method === 'POST') {
        efpic_handle_visitor_collection_toggle($config, strtolower($m[1]), $m[2]);
    }

    if (preg_match('#^/v/g/([a-f0-9]{48})/visitor/download-all$#i', $uri, $m) && $method === 'POST') {
        efpic_handle_visitor_all_collections_download_request($config, strtolower($m[1]));
    }

    if (preg_match('#^/v/g/([a-f0-9]{48})/visitor/collections/([a-z0-9_]+)/download$#i', $uri, $m) && $method === 'POST') {
        efpic_handle_visitor_collection_download_request($config, strtolower($m[1]), $m[2]);
    }

    if (preg_match('#^/v/g/([a-f0-9]{48})/visitor/collections/([a-z0-9_]+)/rename$#i', $uri, $m) && $method === 'POST') {
        efpic_handle_visitor_collection_rename($config, strtolower($m[1]), $m[2]);
    }

    if (preg_match('#^/v/g/([a-f0-9]{48})/visitor/download/([a-f0-9]{40})$#i', $uri, $m) && $method === 'GET') {
        efpic_handle_visitor_collection_download($config, strtolower($m[1]), strtolower($m[2]));
    }

    if (preg_match('#^/c/p/([a-f0-9]{48})/download\.zip$#i', $uri, $m) && $method === 'GET') {
        efpic_portal_handle_download_zip($config, strtolower($m[1]));
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

    if (preg_match('#^/v/i/([a-f0-9]{48})/like$#i', $uri, $m) && $method === 'POST') {
        efpic_handle_client_image_like($config, strtolower($m[1]), $method);
    }

    if (preg_match('#^/v/i/([a-f0-9]{48})$#i', $uri, $m) && $method === 'GET') {
        efpic_handle_client_image($config, strtolower($m[1]), $method);
    }

    if (preg_match('#^/v/media/([a-f0-9]{48})$#i', $uri, $m) && $method === 'GET') {
        efpic_handle_client_media($config, strtolower($m[1]));
    }

    if ($uri === '/client/assets/client.css') {
        efpic_stream_versioned_public_asset(__DIR__ . '/client/assets/client.css', 'text/css');
    }

    if ($uri === '/client/assets/client.js') {
        efpic_stream_versioned_public_asset(__DIR__ . '/client/assets/client.js', 'application/javascript');
    }

    if ($uri === '/client/assets/portal.js') {
        efpic_stream_versioned_public_asset(__DIR__ . '/client/assets/portal.js', 'application/javascript');
    }

    if ($uri === '/admin/assets/admin.css') {
        efpic_stream_versioned_public_asset(__DIR__ . '/admin/assets/admin.css', 'text/css');
    }

    if ($uri === '/admin/assets/admin.js') {
        efpic_stream_versioned_public_asset(__DIR__ . '/admin/assets/admin.js', 'application/javascript');
    }

    if ($uri === '/admin/assets/cover-theme.js') {
        efpic_stream_versioned_public_asset(__DIR__ . '/admin/assets/cover-theme.js', 'application/javascript');
    }

    if ($uri === '/api/render/ping' && $method === 'GET') {
        efpic_handle_render_ping($config);
    }

    if ($uri === '/api/render/claim' && $method === 'POST') {
        efpic_handle_render_claim_job($config);
    }

    if (preg_match('#^/api/render/jobs/([a-f0-9]{32})$#', $uri, $m) && $method === 'GET') {
        efpic_handle_render_get_job($config, $m[1]);
    }

    if (preg_match('#^/api/render/jobs/([a-f0-9]{32})/audio/([0-9]+)$#', $uri, $m) && $method === 'GET') {
        efpic_handle_render_job_audio($config, $m[1], (int) $m[2]);
    }

    if (preg_match('#^/api/render/jobs/([a-f0-9]{32})/audio$#', $uri, $m) && $method === 'GET') {
        efpic_handle_render_job_audio($config, $m[1], 0);
    }

    if (preg_match('#^/api/render/jobs/([a-f0-9]{32})/image/([a-f0-9]{48})$#', $uri, $m) && $method === 'GET') {
        efpic_handle_render_job_image($config, $m[1], $m[2]);
    }

    if (preg_match('#^/api/render/jobs/([a-f0-9]{32})/complete$#', $uri, $m) && $method === 'POST') {
        efpic_handle_render_job_complete($config, $m[1]);
    }

    if (preg_match('#^/api/render/jobs/([a-f0-9]{32})/fail$#', $uri, $m) && $method === 'POST') {
        efpic_handle_render_job_fail($config, $m[1]);
    }

    if (preg_match('#^/v/g/([a-f0-9]{48})/face-status$#i', $uri, $m) && $method === 'GET') {
        efpic_handle_client_face_status($config, strtolower($m[1]));
    }

    if (preg_match('#^/v/g/([a-f0-9]{48})/face-persons/tokens$#i', $uri, $m) && $method === 'GET') {
        efpic_handle_client_face_person_tokens($config, strtolower($m[1]));
    }

    if (preg_match('#^/v/g/([a-f0-9]{48})/face-persons$#i', $uri, $m) && $method === 'GET') {
        efpic_handle_client_face_persons($config, strtolower($m[1]));
    }

    efpic_json_response(404, ['ok' => false, 'error' => 'not_found', 'path' => $uri]);
} catch (Throwable $e) {
    efpic_json_response(500, [
        'ok' => false,
        'error' => 'server_error',
        'message' => $e->getMessage(),
    ]);
}

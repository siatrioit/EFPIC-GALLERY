<?php

declare(strict_types=1);

$apiRoot = dirname(__DIR__, 2) . '/api';
$adminUiPath = $apiRoot . '/admin_ui.php';
$serverCheckPath = $apiRoot . '/server_check.php';

if (!is_file($adminUiPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Trūkst api/admin_ui.php\n";
    echo 'Gaidītais ceļš: ' . $adminUiPath . "\n";
    exit;
}

if (!is_file($serverCheckPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Trūkst api/server_check.php\n";
    echo 'Augšupielādē abus: api/server_check.php un public/admin/server_check.php' . "\n";
    exit;
}

require_once $adminUiPath;
require_once $serverCheckPath;

$config = efpic_load_config();
efpic_admin_handle_logout();
efpic_admin_require_login($config);

efpic_server_check_render_page($config);

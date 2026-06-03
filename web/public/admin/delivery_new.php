<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/admin_ui.php';

$config = efpic_load_config();
efpic_admin_handle_logout();
efpic_admin_require_login($config);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $slug = efpic_admin_save_delivery_from_post($config, null);
        header('Location: delivery_edit.php?slug=' . rawurlencode($slug) . '&saved=1');
        exit;
    } catch (Throwable $e) {
        efpic_admin_delivery_form($config, null, null, $e->getMessage(), true);
    }
}

efpic_admin_delivery_form($config, null, null);

<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/admin_ui.php';

$config = efpic_load_config();
efpic_admin_handle_logout();
efpic_admin_require_login($config);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['save'])) {
    try {
        efpic_admin_save_settings_from_post($config);
        header('Location: settings.php?saved=1');
        exit;
    } catch (Throwable $e) {
        header('Location: settings.php?error=' . rawurlencode($e->getMessage()));
        exit;
    }
}

efpic_admin_settings_page($config);

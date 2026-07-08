<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/admin_ui.php';

$config = efpic_load_config();
efpic_admin_handle_logout();
efpic_admin_require_login($config);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['analytics_action'])) {
    try {
        efpic_admin_handle_analytics_actions($config);
    } catch (Throwable $e) {
        $galleryToken = trim((string) ($_POST['gallery_token'] ?? ''));
        $qs = ['error' => $e->getMessage()];
        if ($galleryToken !== '') {
            $qs['gallery'] = $galleryToken;
        }
        header('Location: analytics.php?' . http_build_query($qs));
        exit;
    }
}

efpic_admin_analytics_page($config);

<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/admin_ui.php';

$config = efpic_load_config();
efpic_admin_handle_logout();
efpic_admin_require_login($config);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['gallery_action'])) {
    try {
        $msg = efpic_admin_handle_gallery_list_actions($config);
        $view = ($_POST['list_view'] ?? 'active') === 'deleted' ? 'deleted' : 'active';
        $qs = http_build_query([
            'view' => $view,
            'sort' => (string) ($_POST['list_sort'] ?? 'date'),
            'order' => (string) ($_POST['list_order'] ?? 'desc'),
            'ok' => $msg ?? 'Gatavs.',
        ]);
        header('Location: index.php?' . $qs);
        exit;
    } catch (Throwable $e) {
        $qs = http_build_query([
            'view' => ($_POST['list_view'] ?? 'active') === 'deleted' ? 'deleted' : 'active',
            'sort' => (string) ($_POST['list_sort'] ?? 'date'),
            'order' => (string) ($_POST['list_order'] ?? 'desc'),
            'error' => $e->getMessage(),
        ]);
        header('Location: index.php?' . $qs);
        exit;
    }
}

efpic_admin_list_delivery_galleries($config);

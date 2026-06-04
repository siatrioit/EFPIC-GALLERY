<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/admin_ui.php';

$config = efpic_load_config();
efpic_admin_handle_logout();
efpic_admin_require_login($config);

$slug = trim((string) ($_GET['slug'] ?? ''));
if ($slug === '') {
    header('Location: index.php');
    exit;
}

$meta = efpic_load_gallery_meta($config, $slug);
if ($meta === null || !efpic_is_delivery_gallery($meta)) {
    http_response_code(404);
    echo 'Galerija nav atrasta';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        efpic_admin_save_delivery_from_post($config, $slug);
        if (!empty($_POST['autosave'])) {
            $meta = efpic_load_gallery_meta($config, $slug);
            $payload = [
                'ok' => true,
                'message' => 'Saglabāts automātiski.',
            ];
            if ($meta !== null) {
                $gt = (string) ($meta['gallery_token'] ?? '');
                $payload['videos_html'] = efpic_admin_render_existing_videos_list($config, $meta, $gt);
                $shareIndex = efpic_share_sets_token_index($meta);
                $payload['share_sets_html'] = efpic_admin_render_share_sets_body($config, $meta);
                $payload['share_index'] = array_keys($shareIndex);
                $payload['share_counts'] = efpic_share_sets_count_index($meta);
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!empty($_POST['create_share_set']) && (string) ($_POST['create_share_set'] ?? '') === '1') {
            $flash = 'Kopīgojamā izlase izveidota — saite sadaļā «Kopīgojamās izlases».';
        } else {
            $flash = !empty($_POST['sync_now']) ? 'Saglabāts un sinhronizēts.' : 'Saglabāts.';
        }
        $meta = efpic_load_gallery_meta($config, $slug);
        efpic_admin_delivery_form($config, $meta, $slug, $flash);
    } catch (Throwable $e) {
        if (!empty($_POST['autosave'])) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
        efpic_admin_delivery_form($config, $meta, $slug, $e->getMessage(), true);
    }
}

$flash = isset($_GET['saved']) ? 'Galerija izveidota.' : null;
efpic_admin_delivery_form($config, $meta, $slug, $flash);

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

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['poll'] ?? '') === 'links') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => true], efpic_admin_gallery_links_payload($config, $meta)), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['admin_share_api'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $meta = efpic_load_gallery_meta($config, $slug);
        if ($meta === null) {
            throw new RuntimeException('Nav atrasts');
        }
        if (trim((string) ($_POST['share_action'] ?? '')) !== '') {
            efpic_apply_share_actions_from_post($meta, 'admin');
        }
        if (!empty($_POST['delete_share_token'])) {
            efpic_delete_share_set($meta, (string) $_POST['delete_share_token']);
        }
        efpic_save_gallery_meta($config, $slug, $meta);
        echo json_encode(array_merge(
            ['ok' => true],
            efpic_admin_gallery_links_payload($config, $meta)
        ), JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!empty($_POST['regenerate_public_link'])) {
            if (empty($_POST['confirm_regenerate'])) {
                throw new InvalidArgumentException('Apstiprini jaunas saites izveidi.');
            }
            $meta = efpic_load_gallery_meta($config, $slug);
            if ($meta === null) {
                throw new RuntimeException('Nav atrasts');
            }
            efpic_regenerate_gallery_public_token($meta);
            efpic_save_gallery_meta($config, $slug, $meta);
            if (!empty($_POST['autosave'])) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(array_merge(
                    ['ok' => true, 'message' => 'Jauna publiskā saite izveidota.'],
                    efpic_admin_gallery_links_payload($config, $meta)
                ), JSON_UNESCAPED_UNICODE);
                exit;
            }
            header('Location: delivery_edit.php?slug=' . rawurlencode($slug) . '&link_regenerated=1');
            exit;
        }

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
                $payload = array_merge($payload, efpic_admin_gallery_links_payload($config, $meta));
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
if (isset($_GET['link_regenerated'])) {
    $flash = 'Jauna publiskā saite izveidota. Vecā saite vairs nedarbojas.';
}
efpic_admin_delivery_form($config, $meta, $slug, $flash);

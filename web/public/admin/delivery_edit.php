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
if ($_SERVER['REQUEST_METHOD'] === 'GET' && efpic_gallery_migrate_slideshow_meta_in_place($meta)) {
    efpic_save_gallery_meta($config, $slug, $meta);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['poll'] ?? '') === 'links') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => true], efpic_admin_gallery_links_payload($config, $meta)), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['poll'] ?? '') === 'slideshow') {
    header('Content-Type: application/json; charset=utf-8');
    $meta = efpic_load_gallery_meta($config, $slug);
    if ($meta === null) {
        echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $items = [];
    foreach (efpic_gallery_slideshow_storage($meta)['items'] as $item) {
        $item = efpic_slideshow_slot_with_render($item);
        $id = (string) ($item['id'] ?? '');
        $renderStatus = (string) ($item['render_status'] ?? 'none');
        $items[] = [
            'id' => $id,
            'render_status' => $renderStatus,
            'render_label' => efpic_render_status_label($renderStatus),
            'render_error' => (string) ($item['render_error'] ?? ''),
            'video_ready' => efpic_slideshow_slot_video_ready($item),
        ];
    }
    echo json_encode([
        'ok' => true,
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE);
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
            efpic_apply_share_actions_from_post($meta, 'admin', ['config' => $config, 'slug' => $slug]);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['backfill_dimensions_api'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $info = efpic_admin_backfill_gallery_dimensions($config, $slug);
        echo json_encode(array_merge(['ok' => true], $info), JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!empty($_POST['send_client_email'])) {
            $group = trim((string) $_POST['send_client_email']);
            if (!array_key_exists($group, efpic_message_template_groups())) {
                throw new InvalidArgumentException('Nederīga ziņu grupa');
            }
            $meta = efpic_load_gallery_meta($config, $slug);
            if ($meta === null) {
                throw new RuntimeException('Nav atrasts');
            }
            efpic_apply_gallery_client_messages_from_post($meta);
            efpic_save_gallery_meta($config, $slug, $meta);
            efpic_gallery_send_client_email($config, $meta, $slug, $group);
            if (str_starts_with($group, 'expiry_reminder_')) {
                efpic_gallery_mark_notification_sent($meta, $group);
            } else {
                efpic_gallery_mark_notification_sent($meta, 'gallery_ready');
            }
            efpic_gallery_log_activity(
                $config,
                $slug,
                $meta,
                'gallery_ready_email',
                'Nosūtīts e-pasts («' . efpic_message_template_group_label($group) . '») uz ' . efpic_gallery_client_email($meta),
                'admin',
            );
            header('Location: delivery_edit.php?slug=' . rawurlencode($slug) . '&email_sent=1');
            exit;
        }

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

        $wantedVideo = !empty($_POST['slideshow_draft_generate_video']);
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
                $slideshowItems = [];
                foreach (efpic_gallery_slideshow_storage($meta)['items'] as $item) {
                    $item = efpic_slideshow_slot_with_render($item);
                    $slideshowItems[] = [
                        'id' => (string) ($item['id'] ?? ''),
                        'render_status' => (string) ($item['render_status'] ?? 'none'),
                        'render_label' => efpic_render_status_label((string) ($item['render_status'] ?? 'none')),
                    ];
                }
                $payload['slideshow_items'] = $slideshowItems;
                $payload = array_merge($payload, efpic_admin_favorites_slideshow_panels_payload($config, $meta));
                $payload['ready_slideshow_state'] = efpic_admin_ready_slideshow_autosave_state($meta);
                $payload['slideshow_meta_diag'] = efpic_admin_slideshow_meta_diagnostic($meta);
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
            exit;
        }

        $qs = 'slug=' . rawurlencode($slug);
        if ($wantedVideo) {
            $qs .= '&video_queued=1';
        } elseif (!empty($_POST['create_share_set']) && (string) ($_POST['create_share_set'] ?? '') === '1') {
            $qs .= '&share_created=1';
        } elseif (!empty($_POST['sync_now'])) {
            $qs .= '&saved=1&synced=1';
        } elseif (!empty($_POST['backfill_dimensions'])) {
            $qs .= '&saved=1&dims_backfill=1';
        } else {
            $qs .= '&saved=1';
        }
        header('Location: delivery_edit.php?' . $qs);
        exit;
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

$flash = null;
if (isset($_GET['saved'])) {
    $flash = !empty($_GET['synced']) ? 'Saglabāts un sinhronizēts.' : 'Saglabāts.';
    if (!empty($_GET['synced'])) {
        efpic_admin_session_start();
        if (isset($_SESSION['efpic_admin_sync_dims'])) {
            $syncDims = $_SESSION['efpic_admin_sync_dims'];
            unset($_SESSION['efpic_admin_sync_dims']);
            $dimsN = is_array($syncDims) ? (int) ($syncDims['backfilled'] ?? 0) : (int) $syncDims;
            $dimStats = is_array($syncDims['stats'] ?? null)
                ? $syncDims['stats']
                : ($meta !== null ? efpic_gallery_image_dimensions_stats($meta) : ['with_dims' => 0, 'total' => 0, 'missing' => 0]);
            $flash .= ' Izmēri ievākti sync laikā: ' . $dimsN . ' bildēm.';
            $flash .= ' Kopā meta.json: ' . (int) ($dimStats['with_dims'] ?? 0) . ' / ' . (int) ($dimStats['total'] ?? 0) . '.';
            if ((int) ($dimStats['missing'] ?? 0) > 0) {
                $flash .= ' Palika ' . (int) $dimStats['missing'] . ' — turpinām fonā…';
            }
        }
    }
}
if (isset($_GET['dims_backfill'])) {
    efpic_admin_session_start();
    if (isset($_SESSION['efpic_admin_backfill_dims']) && is_array($_SESSION['efpic_admin_backfill_dims'])) {
        $info = $_SESSION['efpic_admin_backfill_dims'];
        unset($_SESSION['efpic_admin_backfill_dims']);
        $updated = (int) ($info['updated'] ?? 0);
        $dimStats = is_array($info['stats'] ?? null) ? $info['stats'] : efpic_gallery_image_dimensions_stats($meta ?? []);
        $flash = 'Izmēri ievākti: ' . $updated . ' bildēm. Kopā meta.json: '
            . (int) ($dimStats['with_dims'] ?? 0) . ' / ' . (int) ($dimStats['total'] ?? 0) . '.';
        if ((int) ($dimStats['missing'] ?? 0) > 0) {
            $flash .= ' Palika ' . (int) $dimStats['missing'] . ' — nospied «Ievākt izmērus» vēlreiz.';
        } else {
            $flash .= ' Viss gatavs — atjauno galeriju (Ctrl+F5).';
        }
    }
}
if (isset($_GET['video_queued'])) {
    $flash = 'Saglabāts. Slideshow video ģenerēšana ievietota rindā — vari droši atjaunot lapu, lai pārbaudītu statusu.';
}
if (isset($_GET['share_created'])) {
    $flash = 'Kopīgojamā izlase izveidota — saite sadaļā «Kopīgojamās izlases».';
}
if (isset($_GET['link_regenerated'])) {
    $flash = 'Jauna publiskā saite izveidota. Vecā saite vairs nedarbojas.';
}
if (isset($_GET['email_sent'])) {
    $flash = '«Galerija gatava» e-pasts nosūtīts klientam.';
}
efpic_admin_delivery_form($config, $meta, $slug, $flash);

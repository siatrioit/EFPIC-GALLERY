<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/admin_ui.php';

$config = efpic_load_config();
efpic_admin_handle_logout();
efpic_admin_require_login($config);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['upload'] ?? '') === 'signature_image') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (empty($_FILES['signature_image']['tmp_name']) || !is_uploaded_file((string) $_FILES['signature_image']['tmp_name'])) {
            throw new InvalidArgumentException('Nav augšupielādēts attēls');
        }
        $filename = efpic_store_signature_editor_image($config, $_FILES['signature_image']);
        echo json_encode([
            'ok' => true,
            'url' => efpic_site_asset_public_url($config, $filename),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['poll'] ?? '') === 'render_queue') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(efpic_render_admin_monitor_payload($config), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!empty($_POST['send_test_email'])) {
            efpic_admin_send_test_email($config, (string) ($_POST['test_email_to'] ?? ''));
            header('Location: settings.php?email_test=1#admin-fs-email');
            exit;
        }
        $action = trim((string) ($_POST['render_queue_action'] ?? ''));
        if ($action !== '') {
            if (str_starts_with($action, 'retry:')) {
                efpic_render_admin_retry_job($config, substr($action, 6));
            } elseif (str_starts_with($action, 'cancel:')) {
                efpic_render_admin_cancel_job($config, substr($action, 7));
            } else {
                throw new InvalidArgumentException('Nederīga darbība');
            }
            header('Location: settings.php?render_queue=1');
            exit;
        }
        if (!empty($_POST['save'])) {
            efpic_admin_save_settings_from_post($config);
            header('Location: settings.php?saved=1');
            exit;
        }
        if (!empty($_POST['design_template_save_one'])) {
            $tplSaveId = trim((string) ($_POST['design_template_id'] ?? ''));
            efpic_admin_save_design_template_one_from_post($config, $tplSaveId);
            header('Location: settings.php?saved=1&template=' . rawurlencode($tplSaveId) . '#admin-fs-design-templates');
            exit;
        }
        if (!empty($_POST['design_template_delete_one'])) {
            $tplDeleteId = trim((string) ($_POST['design_template_id'] ?? ''));
            efpic_admin_require_delete_confirm();
            if (!efpic_design_template_delete($config, $tplDeleteId)) {
                throw new InvalidArgumentException('Dizaina šablons nav atrasts');
            }
            header('Location: settings.php?saved=1#admin-fs-design-templates');
            exit;
        }
    } catch (Throwable $e) {
        $hash = !empty($_POST['send_test_email']) ? '#admin-fs-email' : '';
        header('Location: settings.php?error=' . rawurlencode($e->getMessage()) . $hash);
        exit;
    }
}

efpic_admin_settings_page($config);

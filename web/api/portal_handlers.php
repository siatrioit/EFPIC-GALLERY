<?php

declare(strict_types=1);

require_once __DIR__ . '/client_handlers.php';
require_once __DIR__ . '/gallery_assets.php';
require_once __DIR__ . '/admin_ui.php';
require_once __DIR__ . '/face_handlers.php';

function efpic_portal_find_by_token(array $config, string $portalToken): ?array
{
    foreach (efpic_list_gallery_slugs($config) as $slug) {
        $meta = efpic_load_gallery_meta($config, $slug);
        if ($meta === null) {
            continue;
        }
        $pt = (string) ($meta['client_access']['portal_token'] ?? '');
        if ($pt === $portalToken) {
            return ['slug' => $slug, 'meta' => $meta];
        }
    }

    return null;
}

function efpic_portal_session_key(string $portalToken): string
{
    return 'efpic_portal_' . $portalToken;
}

function efpic_portal_logged_in(string $portalToken): bool
{
    efpic_client_session_start();

    return !empty($_SESSION[efpic_portal_session_key($portalToken)]);
}

function efpic_portal_require_auth(array $config, string $portalToken, array $found): void
{
    $meta = $found['meta'];
    $hasPassword = efpic_client_portal_has_password($meta);
    if (!$hasPassword && efpic_portal_logged_in($portalToken)) {
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['portal_password'])) {
        if (!efpic_csrf_verify((string) ($_POST['csrf_token'] ?? ''))) {
            efpic_portal_render_login($config, $found, true);
            exit;
        }
        if (!$hasPassword || efpic_verify_client_portal_password($meta, (string) $_POST['portal_password'])) {
            efpic_client_session_start();
            $_SESSION[efpic_portal_session_key($portalToken)] = true;
            header('Location: ' . efpic_portal_url($config, $portalToken));
            exit;
        }
    }

    if (!efpic_portal_logged_in($portalToken) && $hasPassword) {
        $loginFailed = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['portal_password']);
        efpic_portal_render_login($config, $found, $loginFailed);
        exit;
    }

    if (!$hasPassword) {
        efpic_client_session_start();
        $_SESSION[efpic_portal_session_key($portalToken)] = true;
    }
}

function efpic_portal_render_login(array $config, array $found, bool $failed): void
{
    $name = (string) ($found['meta']['name'] ?? '');
    $body = '<main class="page-auth"><div class="auth-card"><h1>' . efpic_client_esc($name) . '</h1>';
    $body .= '<p class="muted">Klienta panelis</p>';
    if ($failed) {
        $body .= '<p class="err">Nepareiza parole.</p>';
    }
    $body .= '<form method="post" class="stack">' . efpic_csrf_input();
    $body .= '<label>Parole<input type="password" name="portal_password" required autofocus></label>';
    $body .= '<button type="submit" class="btn primary">Ieiet</button></form></div></main>';
    efpic_client_html('Klienta panelis', $body, $config, 'page-auth');
}

function efpic_portal_update_image(array $config, string $slug, array &$meta, string $imageToken, array $patch): void
{
    foreach ($meta['images'] as $i => $img) {
        if (!is_array($img) || ($img['token'] ?? '') !== $imageToken) {
            continue;
        }
        foreach ($patch as $k => $v) {
            $meta['images'][$i][$k] = $v;
        }
        efpic_save_gallery_meta($config, $slug, $meta);

        return;
    }
    throw new RuntimeException('Bilde nav atrasta');
}

/**
 * @return array{id: string, title: string}
 */
function efpic_portal_ensure_scene_by_title(array &$meta, string $title): array
{
    $title = trim($title);
    $options = efpic_gallery_scene_options($meta);
    if ($title === '') {
        foreach ($options as $opt) {
            if (($opt['id'] ?? '') === 'main') {
                return $opt;
            }
        }

        return ['id' => 'main', 'title' => 'Galerija'];
    }

    $lower = function_exists('mb_strtolower') ? mb_strtolower($title) : strtolower($title);
    foreach ($options as $opt) {
        $optTitle = trim((string) ($opt['title'] ?? ''));
        $optLower = function_exists('mb_strtolower') ? mb_strtolower($optTitle) : strtolower($optTitle);
        if ($optLower === $lower) {
            return ['id' => (string) $opt['id'], 'title' => $optTitle !== '' ? $optTitle : (string) $opt['id']];
        }
    }

    $id = 'scene_' . bin2hex(random_bytes(4));
    if (!isset($meta['scenes']) || !is_array($meta['scenes'])) {
        $meta['scenes'] = [];
    }
    $maxSort = 0;
    foreach ($meta['scenes'] as $scene) {
        if (is_array($scene)) {
            $maxSort = max($maxSort, (int) ($scene['sort'] ?? 0));
        }
    }
    $meta['scenes'][] = [
        'id' => $id,
        'title' => $title,
        'sort' => $maxSort + 1,
        'hidden_from_guests' => false,
    ];

    return ['id' => $id, 'title' => $title];
}

/**
 * @param list<string> $tokens
 */
function efpic_portal_assign_tokens_to_scene(array &$meta, array $tokens, string $sceneId): int
{
    $sceneIds = [];
    foreach ($meta['scenes'] ?? [] as $scene) {
        if (is_array($scene)) {
            $sceneIds[(string) ($scene['id'] ?? '')] = true;
        }
    }
    if ($sceneId === '' || !isset($sceneIds[$sceneId])) {
        throw new InvalidArgumentException('Sadaļa nav atrasta');
    }

    $tokenSet = [];
    foreach ($tokens as $tok) {
        $tok = trim((string) $tok);
        if ($tok !== '') {
            $tokenSet[$tok] = true;
        }
    }
    if ($tokenSet === []) {
        throw new InvalidArgumentException('Nav izvēlēta neviena bilde');
    }

    $changed = 0;
    foreach ($meta['images'] as $i => $img) {
        if (!is_array($img)) {
            continue;
        }
        $tok = (string) ($img['token'] ?? '');
        if ($tok === '' || !isset($tokenSet[$tok])) {
            continue;
        }
        $oldSid = (string) ($img['scene_id'] ?? 'main');
        if ($oldSid !== $sceneId) {
            $meta['images'][$i]['scene_id'] = $sceneId;
            if (!empty($meta['images'][$i]['sort_manual'])) {
                efpic_assign_image_sort_in_scene_by_basename($meta, $i);
            } else {
                unset($meta['images'][$i]['sort'], $meta['images'][$i]['sort_manual']);
            }
            $changed++;
        } else {
            $meta['images'][$i]['scene_id'] = $sceneId;
        }
    }

    return $changed;
}

/**
 * @return list<array{id: string, title: string}>
 */
function efpic_portal_scenes_payload(array $meta): array
{
    return efpic_gallery_scene_options($meta);
}

function efpic_portal_set_cover_image(array &$meta, string $imageToken): void
{
    $imageToken = trim($imageToken);
    if ($imageToken === '') {
        throw new InvalidArgumentException('Nav norādīta bilde');
    }
    foreach ($meta['images'] ?? [] as $img) {
        if (is_array($img) && ($img['token'] ?? '') === $imageToken) {
            $meta['cover_image_token'] = $imageToken;
            $meta['cover_from_favorites'] = false;

            return;
        }
    }
    throw new RuntimeException('Bilde nav atrasta');
}

function efpic_portal_handle(array $config, string $portalToken, string $method): void
{
    $found = efpic_portal_find_by_token($config, $portalToken);
    if ($found === null) {
        efpic_client_html('Nav atrasts', '<p class="feed-empty err">Panelis nav atrasts.</p>', $config, 'page-auth');
    }
    if (!efpic_client_portal_enabled($found['meta'])) {
        efpic_client_html('Nav pieejams', '<p class="feed-empty err">Klienta panelis šai galerijai nav ieslēgts.</p>', $config, 'page-auth');
    }

    efpic_portal_require_auth($config, $portalToken, $found);
    $slug = $found['slug'];
    $meta = efpic_load_gallery_meta($config, $slug);
    if ($meta === null) {
        efpic_client_html('Kļūda', '<p class="feed-empty err">Galerija nav atrasta.</p>', $config);
    }
    if (!efpic_gallery_is_active($meta)) {
        efpic_client_html(
            (string) ($meta['name'] ?? ''),
            '<p class="feed-empty err">Galerija nav publiski pieejama. Sazinieties ar fotogrāfu.</p>',
            $config
        );
    }
    if (efpic_gallery_expired($meta)) {
        efpic_client_html(
            (string) ($meta['name'] ?? ''),
            '<p class="feed-empty err">Galerijas derīguma termiņš ir beidzies.</p>',
            $config,
            'page-auth',
        );
    }

    if ($method === 'GET' && !isset($_GET['poll'])) {
        efpic_client_session_start();
        $portalViewKey = 'efpic_portal_viewed_' . $portalToken;
        if (empty($_SESSION[$portalViewKey])) {
            $_SESSION[$portalViewKey] = true;
            efpic_gallery_log_activity(
                $config,
                $slug,
                $meta,
                'client_portal_view',
                'Klienta panelis atvērts',
                'client',
            );
        }
    }

    efpic_visitor_zip_run_pending($config, 1);

    if ($method === 'GET' && ($_GET['poll'] ?? '') === 'slideshow') {
        header('Content-Type: application/json; charset=utf-8');
        efpic_render_run_maintenance($config);
        $clientSlot = efpic_slideshow_slot_with_render(efpic_gallery_slideshows_struct($meta)['client']);
        $renderStatus = (string) ($clientSlot['render_status'] ?? 'none');
        echo json_encode([
            'ok' => true,
            'render_status' => $renderStatus,
            'render_label' => efpic_render_status_label($renderStatus),
            'render_error' => (string) ($clientSlot['render_error'] ?? ''),
            'video_ready' => efpic_slideshow_slot_video_ready($clientSlot),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST' && !empty($_POST['portal_share_api'])) {
        header('Content-Type: application/json; charset=utf-8');
        if (!efpic_csrf_verify((string) ($_POST['csrf_token'] ?? ''))) {
            efpic_json_response(403, ['ok' => false, 'error' => 'Sesijas derīgums beidzies — atjauno lapu.']);
        }
        if (!efpic_client_portal_section_enabled($meta, 'share')) {
            efpic_json_response(403, ['ok' => false, 'error' => 'Kopīgošanas sadaļa nav pieejama.']);
        }
        try {
            if (trim((string) ($_POST['share_action'] ?? '')) !== '') {
                efpic_apply_share_actions_from_post($meta, 'client', ['config' => $config, 'slug' => $slug]);
            }
            if (!empty($_POST['delete_share_token'])) {
                efpic_delete_share_set($meta, (string) $_POST['delete_share_token']);
            }
            efpic_save_gallery_meta($config, $slug, $meta);
            $shareIndex = efpic_share_sets_token_index($meta);
            efpic_json_response(200, [
                'ok' => true,
                'share_sets_html' => efpic_admin_render_share_sets_body($config, $meta),
                'share_index' => array_keys($shareIndex),
                'share_counts' => efpic_share_sets_count_index($meta),
            ]);
        } catch (Throwable $e) {
            efpic_json_response(400, ['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    if ($method === 'POST' && !empty($_POST['portal_images_api'])) {
        header('Content-Type: application/json; charset=utf-8');
        if (!efpic_csrf_verify((string) ($_POST['csrf_token'] ?? ''))) {
            efpic_json_response(403, ['ok' => false, 'error' => 'Sesijas derīgums beidzies — atjauno lapu.']);
        }
        if (!efpic_client_portal_section_enabled($meta, 'images')) {
            efpic_json_response(403, ['ok' => false, 'error' => 'Bilžu sadaļa nav pieejama.']);
        }
        $action = (string) ($_POST['portal_action'] ?? '');
        try {
            match ($action) {
                'set_cover' => (function () use ($config, $slug, &$meta) {
                    $tok = trim((string) ($_POST['image_token'] ?? $_POST['cover_image_token'] ?? ''));
                    efpic_portal_set_cover_image($meta, $tok);
                    efpic_save_gallery_meta($config, $slug, $meta);
                    efpic_json_response(200, [
                        'ok' => true,
                        'cover_image_token' => $tok,
                    ]);
                })(),
                'set_image_scene' => (function () use ($config, $slug, &$meta) {
                    $tokens = $_POST['image_tokens'] ?? [];
                    if (is_string($tokens)) {
                        $tokens = [$tokens];
                    }
                    if (!is_array($tokens)) {
                        $tokens = [];
                    }
                    $single = trim((string) ($_POST['image_token'] ?? ''));
                    if ($single !== '') {
                        $tokens[] = $single;
                    }
                    $tokens = array_values(array_unique(array_filter(array_map('strval', $tokens))));
                    $sceneTitle = trim((string) ($_POST['scene_title'] ?? ''));
                    $sceneId = trim((string) ($_POST['scene_id'] ?? ''));
                    if ($sceneId !== '') {
                        $scene = null;
                        foreach (efpic_gallery_scene_options($meta) as $opt) {
                            if ($opt['id'] === $sceneId) {
                                $scene = $opt;
                                break;
                            }
                        }
                        if ($scene === null) {
                            throw new InvalidArgumentException('Sadaļa nav atrasta');
                        }
                    } else {
                        $scene = efpic_portal_ensure_scene_by_title($meta, $sceneTitle);
                    }
                    efpic_portal_assign_tokens_to_scene($meta, $tokens, (string) $scene['id']);
                    efpic_save_gallery_meta($config, $slug, $meta);
                    efpic_json_response(200, [
                        'ok' => true,
                        'scene' => $scene,
                        'tokens' => $tokens,
                        'scenes' => efpic_portal_scenes_payload($meta),
                    ]);
                })(),
                default => throw new InvalidArgumentException('Nezināma darbība'),
            };
        } catch (Throwable $e) {
            efpic_json_response(400, ['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    if ($method === 'POST' && isset($_POST['portal_action'])) {
        if (!efpic_csrf_verify((string) ($_POST['csrf_token'] ?? ''))) {
            $_SESSION['efpic_portal_flash'] = 'Sesijas derīgums beidzies — atjauno lapu.';
            header('Location: ' . efpic_portal_url($config, $portalToken));
            exit;
        }
        $action = (string) $_POST['portal_action'];
        $imageToken = (string) ($_POST['image_token'] ?? '');
        $actionSection = efpic_portal_action_section($action);
        if ($actionSection !== null && !efpic_client_portal_section_enabled($meta, $actionSection)) {
            $_SESSION['efpic_portal_flash'] = 'Šī sadaļa nav pieejama.';
            header('Location: ' . efpic_portal_url($config, $portalToken));
            exit;
        }

        try {
            match ($action) {
                'toggle_hidden' => (function () use ($config, $slug, &$meta, $imageToken) {
                    foreach ($meta['images'] as $i => $img) {
                        if (!is_array($img) || ($img['token'] ?? '') !== $imageToken) {
                            continue;
                        }
                        $nowHidden = empty($img['client_hidden']);
                        $imageLabel = efpic_gallery_image_label($img);
                        $meta['images'][$i]['client_hidden'] = $nowHidden;
                        efpic_save_gallery_meta($config, $slug, $meta);
                        efpic_gallery_log_activity(
                            $config,
                            $slug,
                            $meta,
                            $nowHidden ? 'image_hidden' : 'image_shown',
                            ($nowHidden ? 'Klients paslēpa bildi' : 'Klients atkal rāda bildi') . ': ' . $imageLabel,
                            'client',
                            ['image_token' => $imageToken, 'image_label' => $imageLabel],
                        );

                        return;
                    }
                    throw new RuntimeException('Bilde nav atrasta');
                })(),
                'toggle_favorite' => (function () use ($config, $slug, &$meta, $imageToken) {
                    foreach ($meta['images'] as $i => $img) {
                        if (!is_array($img) || ($img['token'] ?? '') !== $imageToken) {
                            continue;
                        }
                        $meta['images'][$i]['favorited_client'] = !efpic_image_favorited_client($img);
                        unset($meta['images'][$i]['favorited']);
                        efpic_save_gallery_meta($config, $slug, $meta);

                        return;
                    }
                    throw new RuntimeException('Bilde nav atrasta');
                })(),
                'save_gallery_colors' => (function () use ($config, $slug, &$meta) {
                    $accent = trim((string) ($_POST['hero_accent_color'] ?? ''));
                    if (preg_match('/^#[0-9a-fA-F]{6}$/', $accent) === 1) {
                        $meta['hero_accent_color'] = strtolower($accent);
                    }
                    $pageBg = trim((string) ($_POST['page_bg_color'] ?? ''));
                    if (preg_match('/^#[0-9a-fA-F]{6}$/', $pageBg) === 1) {
                        $meta['page_bg_color'] = strtolower($pageBg);
                    }
                    efpic_save_gallery_meta($config, $slug, $meta);
                    $_SESSION['efpic_portal_flash'] = 'Krāsas saglabātas.';
                })(),
                'save_cover_theme' => (function () use ($config, $slug, &$meta) {
                    $accent = trim((string) ($_POST['hero_accent_color'] ?? ''));
                    if (preg_match('/^#[0-9a-fA-F]{6}$/', $accent) === 1) {
                        $meta['hero_accent_color'] = strtolower($accent);
                    }
                    $pageBg = trim((string) ($_POST['page_bg_color'] ?? ''));
                    if (preg_match('/^#[0-9a-fA-F]{6}$/', $pageBg) === 1) {
                        $meta['page_bg_color'] = strtolower($pageBg);
                    }
                    efpic_apply_cover_theme_from_post($meta);
                    efpic_apply_cover_media_from_post($meta);
                    efpic_apply_mood_theme_from_post($meta);
                    $meta['cover_from_favorites'] = !empty($_POST['cover_from_favorites']);
                    efpic_save_gallery_meta($config, $slug, $meta);
                    $_SESSION['efpic_portal_flash'] = 'Dizains saglabāts.';
                })(),
                'save_download_settings' => (function () use ($config, $slug, &$meta) {
                    if (!isset($meta['settings']) || !is_array($meta['settings'])) {
                        $meta['settings'] = [];
                    }
                    $meta['settings']['disable_public_download_all_web'] = isset($_POST['disable_public_download_all_web']);
                    $meta['settings']['disable_public_download_all_full'] = isset($_POST['disable_public_download_all_full']);
                    efpic_save_gallery_meta($config, $slug, $meta);
                    $_SESSION['efpic_portal_flash'] = 'Lejupielādes iestatījumi saglabāti.';
                })(),
                'regenerate_public_link' => (function () use ($config, $slug, &$meta) {
                    if (empty($_POST['confirm_regenerate'])) {
                        throw new InvalidArgumentException('Apstiprini jaunas saites izveidi.');
                    }
                    efpic_regenerate_gallery_public_token($meta);
                    efpic_save_gallery_meta($config, $slug, $meta);
                    $_SESSION['efpic_portal_flash'] = 'Jauna publiskā saite ir gatava. Vecā saite un ar to saistītās kopīgošanas saites vairs nedarbojas.';
                })(),
                'save_passwords' => (function () use ($config, $slug, &$meta) {
                    efpic_apply_gallery_passwords_from_post($meta, true);
                    efpic_save_gallery_meta($config, $slug, $meta);
                    $_SESSION['efpic_portal_flash'] = 'Paroles saglabātas.';
                })(),
                'add_comment' => (function () use ($config, $slug, &$meta, $imageToken) {
                    if (!efpic_client_comments_enabled($meta)) {
                        throw new InvalidArgumentException('Komentāri ir atslēgti.');
                    }
                    $text = trim((string) ($_POST['comment'] ?? ''));
                    if ($text === '') {
                        throw new InvalidArgumentException('Tukšs komentārs');
                    }
                    $comments = $meta['comments'] ?? [];
                    if (!is_array($comments)) {
                        $comments = [];
                    }
                    $comments[] = [
                        'image_token' => $imageToken,
                        'author' => 'client',
                        'text' => $text,
                        'at' => gmdate('c'),
                    ];
                    $meta['comments'] = $comments;
                    efpic_save_gallery_meta($config, $slug, $meta);
                })(),
                'save_slideshow' => (function () use ($config, $slug, &$meta) {
                    efpic_apply_client_favorites_from_post($meta);
                    efpic_apply_slideshow_from_post($config, $slug, $meta, 'client');
                    if (!empty($_POST['slideshow_client_generate_video'])) {
                        efpic_slideshow_enqueue_render($config, $slug, $meta, 'client');
                    }
                    efpic_save_gallery_meta($config, $slug, $meta);
                    $_SESSION['efpic_portal_flash'] = !empty($_POST['slideshow_client_generate_video'])
                        ? 'Slideshow saglabāts — video ģenerēšana sākta.'
                        : 'Slideshow saglabāts.';
                })(),
                'save_scenes' => (function () use ($config, $slug, &$meta) {
                    $before = [];
                    foreach ($meta['scenes'] ?? [] as $scene) {
                        if (!is_array($scene)) {
                            continue;
                        }
                        $sid = (string) ($scene['id'] ?? '');
                        if ($sid !== '') {
                            $before[$sid] = !empty($scene['hidden_from_guests']);
                        }
                    }
                    $meta['scenes'] = efpic_parse_portal_scenes_from_post($meta);
                    efpic_reassign_orphan_scene_images($meta);
                    efpic_save_gallery_meta($config, $slug, $meta);
                    foreach ($meta['scenes'] ?? [] as $scene) {
                        if (!is_array($scene)) {
                            continue;
                        }
                        $sid = (string) ($scene['id'] ?? '');
                        if ($sid === '' || !array_key_exists($sid, $before)) {
                            continue;
                        }
                        $nowHidden = !empty($scene['hidden_from_guests']);
                        if ($nowHidden === $before[$sid]) {
                            continue;
                        }
                        $title = (string) ($scene['title'] ?? $sid);
                        efpic_gallery_log_activity(
                            $config,
                            $slug,
                            $meta,
                            $nowHidden ? 'section_hidden' : 'section_shown',
                            ($nowHidden ? 'Paslēpta' : 'Rāda') . ' sadaļa «' . $title . '»',
                            'client',
                            ['scene_id' => $sid],
                        );
                    }
                    $_SESSION['efpic_portal_flash'] = 'Sadaļas saglabātas.';
                })(),
                'save_videos' => (function () use ($config, $slug, &$meta) {
                    efpic_apply_videos_from_post($config, $slug, $meta);
                    efpic_save_gallery_meta($config, $slug, $meta);
                    $_SESSION['efpic_portal_flash'] = 'Video saglabāti.';
                })(),
                'upload_video' => (function () use ($config, $slug, &$meta) {
                    efpic_apply_videos_from_post($config, $slug, $meta);
                    efpic_save_gallery_meta($config, $slug, $meta);
                })(),
                'add_video_embed' => (function () use ($config, $slug, &$meta) {
                    efpic_apply_videos_from_post($config, $slug, $meta);
                    efpic_save_gallery_meta($config, $slug, $meta);
                })(),
                default => throw new InvalidArgumentException('Unknown action'),
            };
        } catch (Throwable $e) {
            $_SESSION['efpic_portal_flash'] = $e->getMessage();
        }

        header('Location: ' . efpic_portal_url($config, $portalToken));
        exit;
    }

    efpic_client_session_start();
    $flash = (string) ($_SESSION['efpic_portal_flash'] ?? '');
    unset($_SESSION['efpic_portal_flash']);

    $ctx = efpic_portal_viewer_context();
    $images = [];
    foreach (efpic_sort_images_for_display($meta) as $img) {
        if (is_array($img)) {
            $images[] = $img;
        }
    }

    $gt = (string) ($meta['gallery_token'] ?? '');
    $name = (string) ($meta['name'] ?? '');
    $theme = efpic_gallery_effective_theme($meta);
    $slots = efpic_gallery_slideshows_struct($meta);
    $slideshow = efpic_slideshow_slot_with_render($slots['client']);
    $favCount = efpic_count_favorites($meta, 'client');
    $settings = efpic_gallery_settings($meta);
    $disableAllWeb = !empty($settings['disable_public_download_all_web']);
    $disableAllFull = !empty($settings['disable_public_download_all_full']);
    $commentsEnabled = efpic_client_comments_enabled($meta);
    $portalSections = efpic_client_portal_sections($meta);
    $firstPanelId = null;
    foreach (efpic_client_portal_nav_items() as $navItem) {
        if (!empty($portalSections[$navItem['section']])) {
            $firstPanelId = $navItem['id'];
            break;
        }
    }
    if ($firstPanelId === null) {
        $firstPanelId = 'admin-tab-settings';
    }
    $faceSearchReady = efpic_gallery_face_search_enabled($meta) && efpic_failiem_face_upload_hash($meta) !== '';
    $portalBaseUrl = efpic_portal_url($config, $portalToken);

    $body = '<div class="admin-shell admin-shell--portal">';
    $body .= efpic_portal_render_sidebar($name, $config, $gt, $meta);
    $body .= '<div class="admin-workspace">';
    $body .= '<header class="admin-page-head admin-page-head--portal">';
    $body .= '<h1>' . efpic_client_esc($name) . '</h1>';
    $body .= '<p class="admin-lead">Klienta panelis — pārvaldi bildes, izlases un publisko galeriju.</p>';
    if (empty($portalSections['images'])) {
        $body .= efpic_portal_render_download_actions($config, $portalToken, $meta);
    }
    $body .= '</header>';
    $body .= '<main class="admin-main">';
    if ($flash !== '') {
        $body .= '<p class="admin-flash">' . efpic_client_esc($flash) . '</p>';
    }

    if (!empty($portalSections['images'])) {
        $body .= efpic_admin_tab_panel_open('admin-tab-images', $firstPanelId === 'admin-tab-images');
        $body .= '<div class="admin-share-edit-bar" id="admin-share-edit-bar" hidden>';
        $body .= '<span class="admin-share-edit-bar__label" id="admin-share-edit-bar-label"></span>';
        $body .= '<button type="button" class="btn admin-btn-sm primary" id="admin-share-edit-save">Saglabāt izlasi</button>';
        $body .= '<button type="button" class="btn admin-btn-sm" id="admin-share-edit-cancel">Atcelt</button>';
        $body .= '</div>';
        $body .= efpic_portal_render_images_action_bar($config, $portalToken, $meta, $faceSearchReady);
        $body .= efpic_portal_render_image_grid($config, $images, $commentsEnabled, $meta);
        $body .= efpic_admin_tab_panel_close();
    }

    if (!empty($portalSections['scenes'])) {
        $body .= efpic_admin_tab_panel_open('admin-tab-scenes', $firstPanelId === 'admin-tab-scenes');
        $body .= efpic_portal_render_scenes_panel($meta);
        $body .= efpic_admin_tab_panel_close();
    }

    if (!empty($portalSections['theme'])) {
        $body .= efpic_admin_tab_panel_open('admin-tab-theme', $firstPanelId === 'admin-tab-theme');
        $body .= efpic_portal_render_theme_panel($config, $meta);
        $body .= efpic_admin_tab_panel_close();
    }

    if (!empty($portalSections['share'])) {
        $body .= efpic_admin_tab_panel_open('admin-tab-share', $firstPanelId === 'admin-tab-share');
        $body .= efpic_admin_render_share_sets($config, $meta);
        $body .= efpic_admin_tab_panel_close();
    }

    if (!empty($portalSections['media'])) {
        $body .= efpic_admin_tab_panel_open('admin-tab-media', $firstPanelId === 'admin-tab-media');
        $body .= efpic_portal_render_section_info(
            'Slideshow & video',
            'portalMediaInfoModal',
            'Slideshow un video',
            [
                [
                    'title' => 'Slideshow',
                    'text' => 'Ieslēdz slideshow un ģenerē MP4 no favorītiem — tas parādīsies publiskajā galerijā kā atsevišķa sadaļa. Vari iestatīt intervālu, audio, intro virsrakstu un fona krāsu.',
                ],
                [
                    'title' => 'Video',
                    'text' => 'Pievieno MP4 failu vai YouTube/Vimeo saiti. Katram video vari norādīt sadaļu, kurā tas rādās publiskajā galerijā.',
                ],
            ],
        );
        $body .= efpic_portal_render_favorites_and_slideshow($config, $meta, $gt, $slideshow, $favCount, $slug);
        $body .= efpic_portal_render_videos_fieldset($config, $meta, $gt);
        $body .= efpic_admin_tab_panel_close();
    }

    $body .= efpic_admin_tab_panel_open('admin-tab-settings', $firstPanelId === 'admin-tab-settings');
    $publicUrl = efpic_gallery_view_url($config, $gt);
    $body .= efpic_portal_render_section_info(
        'Iestatījumi',
        'portalSettingsInfoModal',
        'Iestatījumu skaidrojumi',
        [
            [
                'title' => 'Publiskā galerijas saite',
                'text' => 'Šo saiti vari kopīgot ar viesiem. Ja tā nonāk pie nepareiziem cilvēkiem, vari izveidot jaunu — vecā saite vairs nedarbosies (tostarp kopīgošanas izlases, kas izmantoja veco saiti).',
            ],
            [
                'title' => 'Lejupielādes publiskajā galerijā',
                'text' => 'Atzīmē, lai apmeklētājiem vairs nerādītos «lejupielādēt visas bildes» attiecīgajā izmērā. Izvēlētās bildes (izlase) joprojām var lejupielādēt, ja izmērs ir atļauts.',
            ],
            [
                'title' => 'Paroles',
                'text' => 'Ieslēdz slēdzi un ievadi paroli katram laukam atsevišķi. Izslēdzot slēdzi, attiecīgā parole tiek noņemta.',
            ],
        ],
    );
    $body .= '<section class="admin-fieldset-full"><h2 class="admin-share-block-title">Publiskā galerijas saite</h2>';
    $body .= '<p class="admin-links-row">' . efpic_admin_render_link_row($publicUrl) . '</p>';
    $body .= '<form method="post" class="portal-stack portal-regenerate-link-form" data-confirm="'
        . efpic_client_esc('Izveidot jaunu publisko saiti? Vecā saite un visas ar to saistītās kopīgošanas saites pārtraks darboties.')
        . '">';
    $body .= '<input type="hidden" name="portal_action" value="regenerate_public_link">';
    $body .= '<input type="hidden" name="confirm_regenerate" value="1">';
    $body .= '<button type="submit" class="btn">Ģenerēt jaunu publisko saiti</button></form></section>';

    $body .= '<section class="admin-fieldset-full"><h2 class="admin-share-block-title">Lejupielādes publiskajā galerijā</h2>';
    $body .= '<form method="post" class="portal-stack">';
    $body .= '<input type="hidden" name="portal_action" value="save_download_settings">';
    $body .= efpic_render_admin_toggle('Atslēgt «visas bildes» — WEB', $disableAllWeb, [
        'name' => 'disable_public_download_all_web',
    ]);
    $body .= efpic_render_admin_toggle('Atslēgt «visas bildes» — PRINT', $disableAllFull, [
        'name' => 'disable_public_download_all_full',
    ]);
    $body .= '<button type="submit" class="btn primary">Saglabāt</button></form></section>';

    $body .= '<section class="admin-fieldset-full"><h2 class="admin-share-block-title">Paroles</h2>';
    $body .= '<form method="post" class="portal-stack">';
    $body .= '<input type="hidden" name="portal_action" value="save_passwords">';
    $body .= efpic_admin_render_password_field(
        'Galerijas parole',
        'gallery_password',
        '',
        '',
        efpic_gallery_has_password($meta),
    );
    $body .= efpic_admin_render_password_field(
        'Klienta paneļa parole',
        'client_password',
        '',
        '',
        efpic_client_portal_has_password($meta),
    );
    $body .= '<button type="submit" class="btn primary">Saglabāt paroles</button></form></section>';
    $body .= efpic_admin_tab_panel_close();

    $body .= '</main></div></div>';
    if (!empty($portalSections['images']) || !empty($portalSections['share'])) {
        $body .= efpic_admin_render_media_lightbox('portal-lightbox');
    }
    $body .= efpic_client_zip_progress_modal();
    if ($faceSearchReady) {
        $body .= efpic_client_render_face_person_modal();
    }

    efpic_portal_html($name . ' — panelis', $body, $config, 'page-portal theme-efpic-base', $meta, [
        'EFPIC_PORTAL_DL_URL' => efpic_portal_url($config, $portalToken),
        'EFPIC_PORTAL_FAILIEM_FOLDER_ZIP' => efpic_can_failiem_folder_zip($meta, $ctx),
        'EFPIC_CSRF_TOKEN' => efpic_csrf_token(),
        'EFPIC_FACE_SEARCH_ENABLED' => $faceSearchReady,
        'EFPIC_FACE_PERSONS_URL' => $faceSearchReady ? $portalBaseUrl . '/face-persons' : '',
        'EFPIC_FACE_PERSON_TOKENS_URL' => $faceSearchReady ? $portalBaseUrl . '/face-persons/tokens' : '',
        'EFPIC_FACE_NO_FACE_TOKENS_URL' => $faceSearchReady ? $portalBaseUrl . '/face-persons/no-face-tokens' : '',
    ]);
}

function efpic_portal_render_theme_panel(array $config, array $meta): string
{
    $html = efpic_portal_render_section_info(
        'Tēma',
        'portalThemeInfoModal',
        'Kā pielāgot galerijas izskatu',
        [
            [
                'title' => 'Dizaina šabloni',
                'text' => 'Izvēlies oficiālo tēmu (piem. Modern). Pēc tam vari pielāgot krāsas, vāku un tekstus. Mozaīkas kolonnas nosaka, cik blīvs ir bilžu režģis lielos ekrānos.',
            ],
            [
                'title' => 'Krāsu palete',
                'text' => 'Palete aizpilda vāka, fona un teksta krāsas. Pēc tam vari tās pielāgot manuāli ar krāsu laukiem.',
            ],
            [
                'title' => 'Vāka medijs',
                'text' => 'Vāks var būt bilde (izvēlies «Vāks» cilnē Bildes) vai video (ja galerijā ir MP4). Video vāks atskaņojas bez skaņas. Animācija darbojas arī ar bildi. Vari arī rādīt nejaušu favorītu kā vāku.',
            ],
            [
                'title' => 'Novietojums un tipogrāfija',
                'text' => 'Izvēlies vāka stilu un bildes novietojumu. Tipogrāfijā iestati šriftu, krāsu un izmērus. Priekšskatījumā velc tekstus un pārkadrē bildi; pēc izmaiņām spied «Saglabāt dizainu».',
            ],
        ],
    );
    $html .= '<form method="post" class="admin-form admin-cover-theme-form" id="admin-cover-theme-form"'
        . ' data-admin-edit-slug="portal-theme">';
    $html .= '<input type="hidden" name="portal_action" value="save_cover_theme">';
    $html .= '<fieldset class="admin-fieldset-full" id="admin-fs-theme"><legend>Dizains</legend>';
    $html .= '<input type="hidden" name="theme" value="efpic-base">';
    $html .= efpic_render_design_template_controls($config, $meta, false);
    $html .= efpic_render_design_palette_picker($config, $meta);
    $html .= efpic_render_cover_theme_controls($config, $meta, false);
    $html .= '<div class="admin-sticky-bar"><button type="submit" class="btn primary">Saglabāt dizainu</button></div>';
    $html .= '</fieldset></form>';

    return $html;
}

function efpic_portal_render_image_grid(array $config, array $images, bool $commentsEnabled, array $meta = []): string
{
    $coverTok = trim((string) ($meta['cover_image_token'] ?? ''));
    $sceneOptions = efpic_gallery_scene_options($meta !== [] ? $meta : ['scenes' => [['id' => 'main', 'title' => 'Galerija', 'sort' => 1]]]);
    $scenesJson = json_encode($sceneOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($scenesJson === false) {
        $scenesJson = '[]';
    }

    $html = '<datalist id="admin-scene-datalist">';
    foreach ($sceneOptions as $scene) {
        $html .= '<option value="' . efpic_client_esc($scene['title']) . '"></option>';
    }
    $html .= '</datalist>';

    $html .= '<ul id="portal-image-grid" class="admin-media-grid" data-scenes="'
        . efpic_client_esc($scenesJson) . '">';
    foreach ($images as $img) {
        if (!is_array($img)) {
            continue;
        }
        $tok = (string) ($img['token'] ?? '');
        if ($tok === '') {
            continue;
        }
        $hidden = !empty($img['client_hidden']);
        $fav = efpic_image_favorited_client($img);
        $isCover = $coverTok !== '' && $tok === $coverTok;
        $imgScene = (string) ($img['scene_id'] ?? 'main');
        $imgSceneTitle = 'Galerija';
        foreach ($sceneOptions as $sceneOpt) {
            if ($sceneOpt['id'] === $imgScene) {
                $imgSceneTitle = (string) $sceneOpt['title'];
                break;
            }
        }
        $thumb = efpic_admin_media_thumb_url($config, $img);
        $preview = efpic_client_media_url($config, $img, 'web', 1200);
        $html .= '<li class="admin-media-card' . ($hidden ? ' is-hidden' : '') . '" data-token="'
            . efpic_client_esc($tok) . '" data-scene-id="' . efpic_client_esc($imgScene) . '">';
        $html .= '<label class="admin-bulk-pick portal-share-pick-label"><input type="checkbox" class="admin-image-pick portal-share-pick" value="'
            . efpic_client_esc($tok) . '" aria-label="Izlasei"></label>';
        $html .= '<button type="button" class="admin-media-thumb" data-preview="' . efpic_client_esc($preview) . '" aria-label="Priekšskatījums">';
        $html .= '<img src="' . efpic_client_esc($thumb) . '" alt="" width="320" height="240" loading="lazy" decoding="async"></button>';
        $html .= '<div class="admin-media-card__actions">';
        $html .= '<div class="admin-media-card__row admin-media-card__row--toggles">';
        $html .= '<label class="admin-media-toggle admin-cover-pick"><input type="radio" name="cover_image_token" class="portal-cover-pick" value="'
            . efpic_client_esc($tok) . '"' . ($isCover ? ' checked' : '') . '><span class="admin-media-toggle__label">Vāks</span></label>';
        $html .= '<form method="post" class="portal-card-toggle-form"><input type="hidden" name="portal_action" value="toggle_favorite">';
        $html .= '<input type="hidden" name="image_token" value="' . efpic_client_esc($tok) . '">';
        $html .= '<button type="submit" class="admin-media-toggle' . ($fav ? ' is-active' : '') . '"><span class="admin-media-toggle__label">Favorīts</span></button></form>';
        $html .= '<form method="post" class="portal-card-toggle-form"><input type="hidden" name="portal_action" value="toggle_hidden">';
        $html .= '<input type="hidden" name="image_token" value="' . efpic_client_esc($tok) . '">';
        $html .= '<button type="submit" class="admin-media-toggle' . ($hidden ? ' is-active' : '') . '"><span class="admin-media-toggle__label">'
            . ($hidden ? 'Slēpts' : 'Slēpt') . '</span></button></form>';
        $html .= '</div>';
        if ($commentsEnabled) {
            $html .= '<form method="post" class="comment-form portal-card-comment"><input type="hidden" name="portal_action" value="add_comment">';
            $html .= '<input type="hidden" name="image_token" value="' . efpic_client_esc($tok) . '">';
            $html .= '<input name="comment" placeholder="Komentārs"><button type="submit" class="btn admin-btn-sm">+</button></form>';
        }
        $html .= '</div>';
        $html .= '<div class="admin-scene-pick"><span class="admin-scene-pick-label">Sadaļa</span>';
        $html .= '<input type="hidden" class="admin-scene-id" value="' . efpic_client_esc($imgScene) . '">';
        $html .= '<span class="admin-scene-input-wrap">';
        $html .= '<input type="text" class="admin-scene-input" value="' . efpic_client_esc($imgSceneTitle)
            . '" placeholder="Sadaļa…" autocomplete="off" aria-label="Galerijas sadaļa" aria-autocomplete="list">';
        $html .= '<button type="button" class="admin-scene-open-btn" aria-label="Izvēlēties sadaļu" title="Esošās sadaļas">▾</button>';
        $html .= '</span></div>';
        $html .= '</li>';
    }
    $html .= '</ul>';
    $html .= '<div id="admin-scene-float-bar" class="admin-scene-float-bar" hidden>';
    $html .= '<span class="admin-scene-float-count" id="admin-scene-float-count" aria-live="polite"></span>';
    $html .= '<label class="admin-scene-float-label">Sadaļa<input type="text" id="admin-float-scene-input" placeholder="Visām atlasītajām…" autocomplete="off"></label>';
    $html .= '<button type="button" class="btn primary admin-btn-inline" id="admin-float-apply-scene">Pielietot</button>';
    $html .= '<button type="button" class="btn admin-btn-inline" id="admin-float-clear-picks">Noņemt atlasi</button>';
    $html .= '</div>';

    return $html;
}

function efpic_portal_render_scenes_panel(array $meta): string
{
    $fullScenes = [];
    foreach ($meta['scenes'] ?? [] as $scene) {
        if (!is_array($scene)) {
            continue;
        }
        $fullScenes[] = [
            'id' => (string) ($scene['id'] ?? 'main'),
            'title' => (string) ($scene['title'] ?? 'Galerija'),
            'sort' => (int) ($scene['sort'] ?? 0),
            'hidden_from_guests' => !empty($scene['hidden_from_guests']),
        ];
    }
    if ($fullScenes === []) {
        $fullScenes[] = ['id' => 'main', 'title' => 'Galerija', 'sort' => 1, 'hidden_from_guests' => false];
    }
    usort($fullScenes, static fn ($a, $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0));
    $scenesJson = json_encode($fullScenes, JSON_UNESCAPED_UNICODE);
    if ($scenesJson === false) {
        $scenesJson = '[]';
    }

    $html = efpic_portal_render_section_info(
        'Galerijas sadaļas',
        'portalScenesInfoModal',
        'Kā strādā sadaļas',
        [
            [
                'title' => 'Nosaukumi un secība',
                'text' => 'Maini sadaļu nosaukumus un velc rindas, lai mainītu secību. Secība nosaka, kā sadaļas rādās publiskajā galerijā.',
            ],
            [
                'title' => 'Rādīt publiskajā saitē',
                'text' => 'Slēdzis «Rādīt publiskajā saitē» nosaka, vai sadaļa ir redzama apmeklētājiem. Pēc noklusējuma tas ir ieslēgts.',
            ],
            [
                'title' => 'Bildes sadaļās',
                'text' => 'Pašas bildes pievieno sadaļām cilnē Bildes (lauks «Sadaļa» pie kartītes). Šeit pārvaldi tikai sadaļu sarakstu.',
            ],
        ],
    );
    $html .= '<section class="admin-fieldset-full admin-scenes-panel portal-scenes-panel">';
    $html .= '<h2 class="admin-share-block-title">Galerijas sadaļas</h2>';
    $html .= '<form method="post" class="portal-stack" id="portal-scenes-form">';
    $html .= '<input type="hidden" name="portal_action" value="save_scenes">';
    $html .= '<input type="hidden" name="scenes_json" id="portal_scenes_json" value="' . efpic_client_esc($scenesJson) . '">';
    $html .= '<div id="portal-scenes-editor" class="admin-scenes-editor portal-scenes-editor" data-scenes="'
        . efpic_client_esc($scenesJson) . '" data-mode="portal"></div>';
    $html .= '<button type="submit" class="btn primary">Saglabāt sadaļas</button>';
    $html .= '</form></section>';

    return $html;
}

/**
 * @param list<array{title: string, text: string}> $items
 */
function efpic_portal_render_section_info(
    string $title,
    string $modalId,
    string $modalHeading,
    array $items,
): string {
    $html = '<div class="portal-images-action-bar">';
    $html .= '<div class="portal-images-action-bar__btns"></div>';
    $html .= '<button type="button" class="portal-images-action-bar__info" data-portal-info-open'
        . ' aria-haspopup="dialog" aria-controls="' . efpic_client_esc($modalId) . '"'
        . ' aria-label="Palīdzība: ' . efpic_client_esc($title) . '">';
    $html .= '<span aria-hidden="true">i</span></button></div>';
    $html .= '<div class="portal-images-info-modal" id="' . efpic_client_esc($modalId) . '" hidden>';
    $html .= '<div class="portal-images-info-dialog" role="dialog" aria-modal="true" aria-labelledby="'
        . efpic_client_esc($modalId) . 'Title">';
    $html .= '<button type="button" class="portal-images-info-close" data-portal-info-close aria-label="Aizvērt">&times;</button>';
    $html .= '<h2 id="' . efpic_client_esc($modalId) . 'Title">' . efpic_client_esc($modalHeading) . '</h2>';
    $html .= '<ul class="portal-images-info-list">';
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $itemTitle = (string) ($item['title'] ?? '');
        $itemText = (string) ($item['text'] ?? '');
        if ($itemTitle === '' || $itemText === '') {
            continue;
        }
        $html .= '<li><strong>' . efpic_client_esc($itemTitle) . '</strong>';
        $html .= '<p>' . efpic_client_esc($itemText) . '</p></li>';
    }
    $html .= '</ul></div></div>';

    return $html;
}

function efpic_portal_render_favorites_and_slideshow(
    array $config,
    array $meta,
    string $galleryToken,
    array $slideshow,
    int $favCount,
    string $slug = '',
): string {
    $clientSlot = efpic_slideshow_slot_with_render($slideshow);
    $adminSlot = efpic_slideshow_slot_with_render(efpic_gallery_slideshows_struct($meta)['admin']);
    $adminVideoReady = efpic_slideshow_slot_video_ready($adminSlot);
    $clientVideoReady = efpic_slideshow_slot_video_ready($clientSlot);
    $renderStatus = (string) ($clientSlot['render_status'] ?? 'none');

    $html = '<fieldset class="admin-fieldset-full"><legend>Favorīti un slideshow</legend>';

    $html .= '<div class="admin-slideshow-columns">';
    $html .= '<div class="admin-fav-col"><h3 class="admin-fav-heading">Tava slideshow</h3>';
    if ($clientSlot['enabled'] && $clientVideoReady && $adminSlot['enabled'] && $adminVideoReady) {
        $html .= '<p class="muted">Publiski redzamas abas MP4 sadaļas (fotogrāfs, tad klients).</p>';
    } elseif ($adminVideoReady && !$clientVideoReady) {
        $html .= '<p class="muted">Fotogrāfa slideshow video jau ir publiskajā galerijā. Ģenerē savu, lai rādītu abas.</p>';
    } else {
        $html .= '<p class="muted">Ieslēdz slideshow un ģenerē MP4 — tas parādīsies publiskajā galerijā kā atsevišķa sadaļa.</p>';
    }
    $html .= '<form method="post" enctype="multipart/form-data" class="portal-stack" id="portal-slideshow-form">';
    $html .= '<input type="hidden" name="portal_action" value="save_slideshow">';
    $html .= efpic_render_admin_toggle('Ieslēgt slideshow publiskajā galerijā', !empty($clientSlot['enabled']), [
        'name' => 'slideshow_client_enabled',
    ]);
    $html .= '<label>Intervāls (sek.)<input type="number" name="slideshow_client_interval" min="2" max="60" value="'
        . (int) ($clientSlot['interval_sec'] ?? 5) . '"></label>';
    $html .= efpic_admin_render_slideshow_audio_list($config, $galleryToken, $clientSlot, 'client');
    $html .= '<label>Intro virsraksts<input type="text" name="slideshow_client_intro_title" maxlength="120" value="'
        . efpic_client_esc($clientSlot['intro_title']) . '" placeholder="piem. Jānis + Ieva"></label>';
    $html .= '<p class="muted">Intro video: lielie burti. «+» starp vārdiem — jauna rinda.</p>';
    $html .= efpic_admin_render_slideshow_section_settings($meta, $clientSlot, 'client');
    $html .= '<label>Fona krāsa<select name="slideshow_client_bg_mode">';
    $html .= '<option value="white"' . ($clientSlot['bg_mode'] === 'white' ? ' selected' : '') . '>Balts</option>';
    $html .= '<option value="gallery"' . ($clientSlot['bg_mode'] === 'gallery' ? ' selected' : '') . '>Galerijas fons</option>';
    $html .= '</select></label>';
    $html .= efpic_admin_render_slideshow_image_grid($config, $meta, $clientSlot, 'client');
    $html .= '<p class="muted" id="slideshow-client-render-status">Video statuss: <strong data-render-status="'
        . efpic_client_esc($renderStatus) . '">' . efpic_client_esc(efpic_render_status_label($renderStatus)) . '</strong></p>';
    if ($renderStatus === 'failed' && ($clientSlot['render_error'] ?? '') !== '') {
        $html .= '<p class="admin-warn">' . efpic_client_esc((string) $clientSlot['render_error']) . '</p>';
    }
    if (efpic_slideshow_video_is_stale($clientSlot)) {
        $html .= '<p class="admin-warn">Iestatījumi mainīti kopš pēdējā MP4 — ģenerē video no jauna.</p>';
    }
    if (($clientSlot['video_file'] ?? '') !== '') {
        $videoFile = (string) $clientSlot['video_file'];
        $videoUrl = efpic_gallery_asset_url($config, $galleryToken, $videoFile);
        $html .= '<p class="admin-ok">MP4: <a href="' . efpic_client_esc($videoUrl) . '" target="_blank" rel="noopener">'
            . efpic_client_esc($videoFile) . '</a></p>';
        if ($slug !== '') {
            $videoPath = efpic_gallery_assets_dir($config, $slug) . DIRECTORY_SEPARATOR . $videoFile;
            $sizeLabel = is_file($videoPath) ? efpic_format_bytes((int) filesize($videoPath)) : 'nav atrasts';
            $html .= '<p class="muted">Izmērs serverī: ' . efpic_client_esc($sizeLabel) . '</p>';
        }
        $html .= '<button type="submit" class="btn admin-btn-danger" name="slideshow_client_remove_video" value="1"'
            . ' onclick="return confirm(\'Dzēst slideshow MP4?\');">Dzēst MP4</button>';
    }
    $html .= '<div class="admin-media-action-row">';
    $html .= '<button type="submit" class="btn primary">Saglabāt slideshow</button>';
    $html .= '<button type="submit" class="btn" name="slideshow_client_generate_video" value="1"'
        . ' onclick="return confirm(\'Ģenerēt jaunu slideshow video? Esošais MP4 tiks aizstāts, kad render pabeigts.\');">Ģenerēt video</button>';
    $html .= '</div>';
    $html .= '</form></div></div></fieldset>';

    return $html;
}

function efpic_portal_render_videos_fieldset(array $config, array $meta, string $galleryToken): string
{
    $scenes = efpic_gallery_scene_options($meta);
    $html = '<fieldset class="admin-fieldset-full" id="admin-videos-panel"><legend>Video</legend>';
    $html .= '<p class="muted">Video tiek rādīti publiskajā galerijā <strong>pirms</strong> izvēlētās sadaļas bildēm.</p>';
    $html .= '<form method="post" enctype="multipart/form-data" id="portal-videos-form">';
    $html .= '<input type="hidden" name="portal_action" value="save_videos">';
    $html .= '<div id="admin-videos-list" class="admin-videos-list">';
    $html .= efpic_admin_render_existing_videos_list($config, $meta, $galleryToken, false);
    $html .= '</div>';
    $html .= '<div class="admin-video-add">';
    $html .= '<h3 class="admin-video-add-title">Pievienot video failu</h3>';
    $html .= '<div class="admin-form-split">';
    $html .= '<label>Video fails (MP4)<input type="file" name="gallery_video" accept="video/mp4,video/quicktime,video/webm,.mp4,.mov,.webm"></label>';
    $html .= '<label>Virsraksts<input name="video_upload_title" placeholder="piem. Laulību ceremonija"></label>';
    $html .= '<label>Sadaļa<select name="video_upload_scene" class="admin-video-scene-select">';
    foreach ($scenes as $scene) {
        $html .= '<option value="' . efpic_client_esc($scene['id']) . '">' . efpic_client_esc($scene['title']) . '</option>';
    }
    $html .= '</select></label></div>';
    $html .= '<h3 class="admin-video-add-title">Pievienot YouTube / Vimeo</h3>';
    $html .= '<div class="admin-form-split admin-video-embed-add">';
    $html .= '<label>YouTube / Vimeo saite<input name="video_embed_url" placeholder="https://youtube.com/watch?v=..."></label>';
    $html .= '<label>Virsraksts<input name="video_embed_title" placeholder="Ievietots video"></label>';
    $html .= '<label>Sadaļa<select name="video_embed_scene" class="admin-video-scene-select">';
    foreach ($scenes as $scene) {
        $html .= '<option value="' . efpic_client_esc($scene['id']) . '">' . efpic_client_esc($scene['title']) . '</option>';
    }
    $html .= '</select></label></div></div>';
    $html .= '<div class="admin-video-submit-row">';
    $html .= '<button type="submit" class="btn primary admin-btn-inline">Pievienot video</button>';
    $html .= '</div></form></fieldset>';

    return $html;
}

function efpic_portal_handle_download_zip(array $config, string $portalToken): void
{
    @set_time_limit(0);
    $found = efpic_portal_find_by_token($config, $portalToken);
    if ($found === null) {
        http_response_code(404);
        exit;
    }
    if (!efpic_client_portal_enabled($found['meta'])) {
        http_response_code(404);
        exit;
    }

    $slug = $found['slug'];
    $meta = efpic_load_gallery_meta($config, $slug);
    if ($meta === null || !efpic_gallery_is_active($meta)) {
        http_response_code(404);
        exit;
    }
    if (efpic_gallery_expired($meta)) {
        http_response_code(403);
        exit;
    }

    efpic_client_session_start();
    if (!efpic_portal_logged_in($portalToken) && efpic_client_portal_has_password($meta)) {
        http_response_code(403);
        exit;
    }
    if (!efpic_portal_logged_in($portalToken)) {
        $_SESSION[efpic_portal_session_key($portalToken)] = true;
    }

    $ctx = efpic_portal_viewer_context();
    $size = strtolower((string) ($_GET['size'] ?? 'web'));
    if (!in_array($size, ['web', 'full'], true)) {
        $size = 'web';
    }
    if (!efpic_can_portal_download_all_gallery_zip($meta, $size)) {
        http_response_code(403);
        exit;
    }

    $images = efpic_portal_all_gallery_images($meta);
    if ($images === []) {
        http_response_code(404);
        exit;
    }

    $galleryToken = (string) ($meta['gallery_token'] ?? '');
    $foundZip = [
        'slug' => $slug,
        'meta' => $meta,
        'dir' => efpic_gallery_dir($config, $slug),
    ];
    $filename = efpic_client_zip_filename($slug, $size, 'all');

    if (isset($_GET['prepare']) && (string) $_GET['prepare'] === '1') {
        efpic_gallery_log_activity(
            $config,
            $slug,
            $meta,
            'download_zip',
            'Klienta panelis: visas bildes (' . efpic_gallery_download_size_label($size) . ')',
            'client',
        );
        efpic_client_zip_prepare_response($config, $foundZip, $meta, $ctx, $size, 'portal', $galleryToken, 'portal');
    }

    if (isset($_GET['dl']) && (string) $_GET['dl'] === '1') {
        @ignore_user_abort(true);
        if (efpic_client_stream_prepared_failiem_zip($config, $galleryToken, 'portal', $size, 'portal')) {
            exit;
        }
        if (!efpic_is_delivery_gallery($meta) || count($images) <= 25) {
            efpic_client_build_delivery_zip($config, $foundZip, $meta, $images, $size, $filename);
            exit;
        }
        http_response_code(410);
        echo 'ZIP sagatavojums nav derīgs. Atver lejupielādi vēlreiz.';
        exit;
    }

    if (efpic_can_failiem_folder_zip($meta, $ctx)) {
        $folderHash = efpic_failiem_delivery_folder_hash($meta, $size);
        if ($folderHash !== '') {
            efpic_gallery_log_activity(
                $config,
                $slug,
                $meta,
                'download_zip',
                'Klienta panelis: visas bildes (' . efpic_gallery_download_size_label($size) . ')',
                'client',
            );
            header('Location: ' . efpic_failiem_folder_zip_url($config, $folderHash), true, 302);
            exit;
        }
    }

    if (efpic_client_stream_failiem_image_zip($config, $meta, $images, $size, $filename)) {
        efpic_gallery_log_activity(
            $config,
            $slug,
            $meta,
            'download_zip',
            'Klienta panelis: visas bildes (' . efpic_gallery_download_size_label($size) . ')',
            'client',
        );
        exit;
    }

    efpic_gallery_log_activity(
        $config,
        $slug,
        $meta,
        'download_zip',
        'Klienta panelis: visas bildes (' . efpic_gallery_download_size_label($size) . ')',
        'client',
    );
    efpic_client_build_delivery_zip($config, $foundZip, $meta, $images, $size, $filename);
}

function efpic_portal_download_action_flags(array $meta): array
{
    if (!efpic_is_delivery_gallery($meta) || efpic_portal_all_gallery_images($meta) === []) {
        return ['web' => false, 'full' => false];
    }

    return [
        'web' => efpic_can_portal_download_all_gallery_zip($meta, 'web'),
        'full' => efpic_can_portal_download_all_gallery_zip($meta, 'full'),
    ];
}

function efpic_portal_render_download_actions(array $config, string $portalToken, array $meta): string
{
    $flags = efpic_portal_download_action_flags($meta);
    if (!$flags['web'] && !$flags['full']) {
        return '';
    }

    $html = '<div class="portal-download-actions">';
    if ($flags['web']) {
        $html .= '<button type="button" class="btn primary" data-portal-dl-size="web">Lejupielādēt WEB</button>';
    }
    if ($flags['full']) {
        $html .= '<button type="button" class="btn" data-portal-dl-size="full">Lejupielādēt PRINT</button>';
    }
    $html .= '</div>';

    return $html;
}

function efpic_portal_render_images_action_bar(
    array $config,
    string $portalToken,
    array $meta,
    bool $faceSearchReady,
): string {
    $flags = efpic_portal_download_action_flags($meta);

    $infoItems = [
        [
            'title' => 'Vāks',
            'text' => 'Atzīmē, kura bilde būs galerijas vāka bilde publiskajā saitē. Vienlaikus var būt tikai viena vāka bilde.',
        ],
        [
            'title' => 'Favorīts',
            'text' => 'Pievieno bildi saviem favorītiem — tos izmanto slideshow un citām izlasēm klienta panelī.',
        ],
        [
            'title' => 'Slēpt',
            'text' => 'Paslēpj bildi no publiskās galerijas. Paslēptā bilde paliek redzama tev klienta panelī (atzīmēta kā «Slēpts»).',
        ],
        [
            'title' => 'Sadaļa',
            'text' => 'Rāda, kurā galerijas sadaļā bilde atrodas. Maini nosaukumu laukā vai spied ▾, lai izvēlētos esošu sadaļu. '
                . 'Ja ievadi jaunu nosaukumu, tiek izveidota jauna sadaļa. Atlasot vairākas bildes, apakšā var pielietot sadaļu visām uzreiz.',
        ],
    ];
    $html = '<div class="portal-images-action-bar">';
    $html .= '<div class="portal-images-action-bar__btns">';
    if ($flags['web']) {
        $html .= '<button type="button" class="btn primary portal-images-action-bar__btn" data-portal-dl-size="web">Lejupielādēt WEB</button>';
        $infoItems[] = [
            'title' => 'Lejupielādēt WEB',
            'text' => 'Lejupielādē visu galeriju ZIP arhīvā mazākā (WEB) izmērā — ērtāk skatīšanai ekrānā, sūtīšanai un ātrai lejupielādei.',
        ];
    }
    if ($flags['full']) {
        $html .= '<button type="button" class="btn portal-images-action-bar__btn" data-portal-dl-size="full">Lejupielādēt PRINT</button>';
        $infoItems[] = [
            'title' => 'Lejupielādēt PRINT',
            'text' => 'Lejupielādē visu galeriju ZIP arhīvā pilnā (PRINT) izmērā — drukai, arhivēšanai un maksimālai kvalitātei.',
        ];
    }
    if ($faceSearchReady) {
        $html .= '<button type="button" class="btn portal-images-action-bar__btn" data-face-search-open>Meklēt pēc sejas</button>';
        $infoItems[] = [
            'title' => 'Meklēt pēc sejas',
            'text' => 'Atver seju izvēli un filtrē galeriju, rādot tikai fotogrāfijas, kurās redzama izvēlētā seja (vai vairākas).',
        ];
    }
    $html .= '</div>';
    $html .= '<button type="button" class="portal-images-action-bar__info" data-portal-images-info-open '
        . 'aria-haspopup="dialog" aria-controls="portalImagesInfoModal" aria-label="Kas ir šīs pogas?">';
    $html .= '<span aria-hidden="true">i</span></button>';
    $html .= '</div>';
    if ($faceSearchReady) {
        $html .= efpic_client_render_face_filter_toolbar_panel();
    }

    $html .= '<div class="portal-images-info-modal" id="portalImagesInfoModal" hidden>';
    $html .= '<div class="portal-images-info-dialog" role="dialog" aria-modal="true" aria-labelledby="portalImagesInfoTitle">';
    $html .= '<button type="button" class="portal-images-info-close" data-portal-images-info-close aria-label="Aizvērt">&times;</button>';
    $html .= '<h2 id="portalImagesInfoTitle">Bildes — pogu un lauku nozīme</h2>';
    $html .= '<ul class="portal-images-info-list">';
    foreach ($infoItems as $item) {
        $html .= '<li><strong>' . efpic_client_esc($item['title']) . '</strong>';
        $html .= '<p>' . efpic_client_esc($item['text']) . '</p></li>';
    }
    $html .= '</ul></div></div>';

    return $html;
}

function efpic_portal_render_sidebar(string $name, array $config, string $galleryToken, array $meta): string
{
    $sections = efpic_client_portal_sections($meta);
    $nav = [];
    foreach (efpic_client_portal_nav_items() as $item) {
        if (!empty($sections[$item['section']])) {
            $nav[] = $item;
        }
    }

    $html = '<button type="button" class="admin-sidebar-reopen" id="adminSidebarReopen" hidden aria-label="Atvērt izvēlni">';
    $html .= '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z"/></svg>';
    $html .= '</button>';
    $html .= '<aside class="admin-sidebar" id="adminSidebar" aria-label="Klienta panelis">';
    $html .= '<div class="admin-sidebar-head">';
    $html .= '<span class="admin-brand admin-brand--portal" title="' . efpic_client_esc($name) . '">' . efpic_client_esc($name) . '</span>';
    $html .= '<button type="button" class="admin-sidebar-hide" id="adminSidebarHide" aria-label="Paslēpt izvēlni">';
    $html .= '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M15.41 7.41 14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>';
    $html .= '</button></div>';
    $html .= '<nav class="admin-nav admin-nav--portal" role="tablist" aria-label="Panelis">';
    foreach ($nav as $i => $item) {
        $active = $i === 0 && $nav !== [] ? ' active' : '';
        $selected = $i === 0 && $nav !== [] ? 'true' : 'false';
        $html .= '<button type="button" class="admin-nav-tab' . $active . '" role="tab" id="'
            . efpic_client_esc($item['id']) . '-tab" aria-selected="' . $selected . '" aria-controls="'
            . efpic_client_esc($item['id']) . '" data-admin-tab="' . efpic_client_esc($item['id']) . '">'
            . efpic_client_esc($item['label']) . '</button>';
    }
    $html .= '</nav>';
    $html .= '<div class="admin-sidebar-foot">';
    $html .= '<button type="button" class="admin-sidebar-foot-link admin-sidebar-foot-tab" data-admin-tab="admin-tab-settings" role="tab" id="admin-tab-settings-tab" aria-controls="admin-tab-settings" aria-selected="false">';
    $html .= efpic_admin_icon_settings() . '<span>Iestatījumi</span></button>';
    $html .= '<a class="admin-sidebar-foot-link" href="' . efpic_client_esc(efpic_gallery_view_url($config, $galleryToken)) . '" target="_blank" rel="noopener">';
    $html .= '<span>Publiskā galerija</span></a>';
    $html .= '<span class="admin-app-version admin-app-version--portal" title="EFPIC Gallery">' . efpic_client_esc(efpic_app_version_label()) . '</span>';
    $html .= '</div></aside>';

    return $html;
}

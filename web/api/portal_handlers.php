<?php

declare(strict_types=1);

require_once __DIR__ . '/client_handlers.php';
require_once __DIR__ . '/gallery_assets.php';
require_once __DIR__ . '/admin_ui.php';

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
    $hash = (string) ($meta['client_access']['password_hash'] ?? '');
    if ($hash === '' && efpic_portal_logged_in($portalToken)) {
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['portal_password'])) {
        if ($hash === '' || efpic_verify_password_hash((string) $_POST['portal_password'], $hash)) {
            efpic_client_session_start();
            $_SESSION[efpic_portal_session_key($portalToken)] = true;
            header('Location: ' . efpic_portal_url($config, $portalToken));
            exit;
        }
    }

    if (!efpic_portal_logged_in($portalToken) && $hash !== '') {
        efpic_portal_render_login($config, $found, true);
        exit;
    }

    if ($hash === '') {
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
    $body .= '<form method="post" class="stack"><label>Parole<input type="password" name="portal_password" required autofocus></label>';
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

function efpic_portal_handle(array $config, string $portalToken, string $method): void
{
    $found = efpic_portal_find_by_token($config, $portalToken);
    if ($found === null) {
        efpic_client_html('Nav atrasts', '<p class="feed-empty err">Panelis nav atrasts.</p>', $config, 'page-auth');
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

    if ($method === 'POST' && !empty($_POST['portal_share_api'])) {
        header('Content-Type: application/json; charset=utf-8');
        try {
            if (trim((string) ($_POST['share_action'] ?? '')) !== '') {
                efpic_apply_share_actions_from_post($meta, 'client');
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

    if ($method === 'POST' && isset($_POST['portal_action'])) {
        $action = (string) $_POST['portal_action'];
        $imageToken = (string) ($_POST['image_token'] ?? '');

        try {
            match ($action) {
                'toggle_hidden' => (function () use ($config, $slug, &$meta, $imageToken) {
                    foreach ($meta['images'] as $i => $img) {
                        if (!is_array($img) || ($img['token'] ?? '') !== $imageToken) {
                            continue;
                        }
                        $meta['images'][$i]['client_hidden'] = empty($img['client_hidden']);
                        efpic_save_gallery_meta($config, $slug, $meta);

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
                'set_theme' => (function () use ($config, $slug, &$meta) {
                    $theme = (string) ($_POST['theme'] ?? 'pic-time');
                    if (!efpic_is_valid_gallery_theme($theme)) {
                        throw new InvalidArgumentException('Nederīga tēma');
                    }
                    $meta['client_theme'] = $theme;
                    efpic_save_gallery_meta($config, $slug, $meta);
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
                'save_download_settings' => (function () use ($config, $slug, &$meta) {
                    if (!isset($meta['settings']) || !is_array($meta['settings'])) {
                        $meta['settings'] = [];
                    }
                    $meta['settings']['disable_public_download_all_web'] = isset($_POST['disable_public_download_all_web']);
                    $meta['settings']['disable_public_download_all_full'] = isset($_POST['disable_public_download_all_full']);
                    efpic_save_gallery_meta($config, $slug, $meta);
                    $_SESSION['efpic_portal_flash'] = 'Lejupielādes iestatījumi saglabāti.';
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
                    efpic_apply_slideshow_from_post($config, $slug, $meta, 'client');
                    efpic_save_gallery_meta($config, $slug, $meta);
                })(),
                'save_scenes' => (function () use ($config, $slug, &$meta) {
                    $meta['scenes'] = efpic_parse_portal_scenes_from_post($meta);
                    efpic_reassign_orphan_scene_images($meta);
                    efpic_save_gallery_meta($config, $slug, $meta);
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

    $ctx = [
        'role' => 'main_client',
        'guest_token' => '',
        'hide_client_hidden' => false,
        'share_image_tokens' => null,
        'share_label' => '',
    ];
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
    $slideshow = $slots['client'];
    $favCount = efpic_count_favorites($meta, 'client');
    $settings = efpic_gallery_settings($meta);
    $disableAllWeb = !empty($settings['disable_public_download_all_web']);
    $disableAllFull = !empty($settings['disable_public_download_all_full']);
    $commentsEnabled = efpic_client_comments_enabled($meta);
    $heroAccent = efpic_client_hero_accent_color($meta);
    $pageBg = efpic_client_page_bg_color($config, $meta);

    $body = '<div class="admin-shell admin-shell--portal">';
    $body .= efpic_portal_render_sidebar($name, $config, $gt);
    $body .= '<div class="admin-workspace">';
    $body .= '<header class="admin-page-head admin-page-head--portal">';
    $body .= '<h1>' . efpic_client_esc($name) . '</h1>';
    $body .= '<p class="admin-lead">Klienta panelis — pārvaldi bildes, izlases un publisko galeriju.</p>';
    $body .= '</header>';
    $body .= '<main class="admin-main">';
    if ($flash !== '') {
        $body .= '<p class="admin-flash">' . efpic_client_esc($flash) . '</p>';
    }

    $body .= efpic_admin_tab_panel_open('admin-tab-images', true);
    $body .= '<div class="admin-share-edit-bar" id="admin-share-edit-bar" hidden>';
    $body .= '<span class="admin-share-edit-bar__label" id="admin-share-edit-bar-label"></span>';
    $body .= '<button type="button" class="btn admin-btn-sm primary" id="admin-share-edit-save">Saglabāt izlasi</button>';
    $body .= '<button type="button" class="btn admin-btn-sm" id="admin-share-edit-cancel">Atcelt</button>';
    $body .= '</div>';
    $body .= efpic_portal_render_image_grid($config, $images, $commentsEnabled);
    $body .= '<div id="portal-lightbox" class="admin-lightbox" hidden role="dialog" aria-modal="true" aria-label="Bildes priekšskatījums">';
    $body .= '<button type="button" class="admin-lightbox-close" aria-label="Aizvērt">&times;</button>';
    $body .= '<img src="" alt=""></div>';
    $body .= efpic_admin_tab_panel_close();

    $body .= efpic_admin_tab_panel_open('admin-tab-scenes');
    $body .= efpic_portal_render_scenes_panel($meta);
    $body .= efpic_admin_tab_panel_close();

    $body .= efpic_admin_tab_panel_open('admin-tab-share');
    $body .= '<div id="admin-share-sets-body">' . efpic_admin_render_share_sets_body($config, $meta) . '</div>';
    $body .= efpic_admin_tab_panel_close();

    $body .= efpic_admin_tab_panel_open('admin-tab-media');
    $body .= efpic_portal_render_favorites_and_slideshow($config, $meta, $gt, $slideshow, $favCount);
    $body .= efpic_portal_render_videos_fieldset($config, $meta, $gt);
    $body .= efpic_admin_tab_panel_close();

    $body .= efpic_admin_tab_panel_open('admin-tab-settings');
    $body .= '<section class="admin-fieldset-full"><h2 class="admin-share-block-title">Izskats</h2>';
    $body .= '<form method="post" class="admin-form-split portal-theme-form">';
    $body .= '<input type="hidden" name="portal_action" value="set_theme"><label>Tēma<select name="theme" onchange="this.form.submit()">';
    foreach (efpic_gallery_theme_options() as $themeKey => $themeLabel) {
        $sel = $themeKey === $theme ? ' selected' : '';
        $body .= '<option value="' . efpic_client_esc($themeKey) . '"' . $sel . '>' . efpic_client_esc($themeLabel) . '</option>';
    }
    $body .= '</select></label></form>';
    $body .= '<form method="post" class="admin-color-form">';
    $body .= '<input type="hidden" name="portal_action" value="save_gallery_colors">';
    $body .= efpic_client_color_field('hero_accent_color', 'Vāka krāsa', $heroAccent);
    $body .= efpic_client_color_field('page_bg_color', 'Galerijas pamatkrāsa', $pageBg);
    $body .= '<button type="submit" class="btn primary">Saglabāt krāsas</button></form></section>';

    $body .= '<section class="admin-fieldset-full"><h2 class="admin-share-block-title">Lejupielādes publiskajā galerijā</h2>';
    $body .= '<p class="muted">Atzīmē, lai apmeklētājiem vairs nerādītos «lejupielādēt visas bildes» attiecīgajā izmērā. Izvēlētās bildes (izlase) joprojām var lejupielādēt, ja izmērs ir atļauts.</p>';
    $body .= '<form method="post" class="portal-stack">';
    $body .= '<input type="hidden" name="portal_action" value="save_download_settings">';
    $body .= '<label class="admin-check"><input type="checkbox" name="disable_public_download_all_web" value="1"'
        . ($disableAllWeb ? ' checked' : '') . '> Atslēgt «visas bildes» — WEB</label>';
    $body .= '<label class="admin-check"><input type="checkbox" name="disable_public_download_all_full" value="1"'
        . ($disableAllFull ? ' checked' : '') . '> Atslēgt «visas bildes» — PRINT</label>';
    $body .= '<button type="submit" class="btn primary">Saglabāt</button></form></section>';
    $body .= efpic_admin_tab_panel_close();

    $body .= '</main></div></div>';

    efpic_portal_html($name . ' — panelis', $body, $config, 'page-portal theme-' . preg_replace('/[^a-z0-9-]/', '', $theme), $meta);
}

function efpic_portal_render_image_grid(array $config, array $images, bool $commentsEnabled): string
{
    $html = '<ul id="portal-image-grid" class="admin-media-grid">';
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
        $thumb = efpic_admin_media_thumb_url($config, $img);
        $preview = efpic_client_media_url($config, $img, 'web', 1200);
        $html .= '<li class="admin-media-card' . ($hidden ? ' is-hidden' : '') . '" data-token="' . efpic_client_esc($tok) . '">';
        $html .= '<label class="admin-bulk-pick portal-share-pick-label"><input type="checkbox" class="admin-image-pick portal-share-pick" value="'
            . efpic_client_esc($tok) . '" aria-label="Izlasei"></label>';
        $html .= '<button type="button" class="admin-media-thumb" data-preview="' . efpic_client_esc($preview) . '" aria-label="Priekšskatījums">';
        $html .= '<img src="' . efpic_client_esc($thumb) . '" alt="" width="320" height="240" loading="lazy" decoding="async"></button>';
        $html .= '<div class="admin-media-card__actions">';
        $html .= '<div class="admin-media-card__row admin-media-card__row--toggles">';
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
        $html .= '</div></li>';
    }
    $html .= '</ul>';

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

    $html = '<section class="admin-fieldset-full admin-scenes-panel portal-scenes-panel">';
    $html .= '<h2 class="admin-share-block-title">Galerijas sadaļas</h2>';
    $html .= '<p class="muted">Maini nosaukumus un secību. «Rādīt publiskajā saitē» — sadaļa redzama apmeklētājiem (pēc noklusējuma ieslēgts).</p>';
    $html .= '<form method="post" class="portal-stack" id="portal-scenes-form">';
    $html .= '<input type="hidden" name="portal_action" value="save_scenes">';
    $html .= '<input type="hidden" name="scenes_json" id="portal_scenes_json" value="' . efpic_client_esc($scenesJson) . '">';
    $html .= '<div id="portal-scenes-editor" class="admin-scenes-editor portal-scenes-editor" data-scenes="'
        . efpic_client_esc($scenesJson) . '" data-mode="portal"></div>';
    $html .= '<button type="submit" class="btn primary">Saglabāt sadaļas</button>';
    $html .= '</form></section>';

    return $html;
}

function efpic_portal_render_favorites_and_slideshow(
    array $config,
    array $meta,
    string $galleryToken,
    array $slideshow,
    int $favCount,
): string {
    $html = '<fieldset class="admin-fieldset-full"><legend>Favorīti un slideshow</legend>';

    $html .= '<div class="admin-fav-columns">';
    $html .= '<div class="admin-fav-col"><h3 class="admin-fav-heading">Tavi favorīti</h3>';
    $html .= '<p class="muted">Atzīmē ★ pie bildēm cilnē Bildes vai noņem šeit.</p>';
    $html .= efpic_admin_render_favorite_thumb_grid($config, $meta, 'client', true);
    $html .= '</div></div>';

    $html .= '<div class="admin-slideshow-columns">';
    $html .= '<div class="admin-fav-col"><h3 class="admin-fav-heading">Tava slideshow</h3>';
    $html .= '<p class="muted">Augšupielādē MP3. Kad slideshow ir gatava, tā kļūst par galveno publiskajā galerijā.</p>';
    $html .= '<form method="post" enctype="multipart/form-data" class="admin-form-split portal-stack">';
    $html .= '<input type="hidden" name="portal_action" value="save_slideshow">';
    $html .= '<label class="admin-check"><input type="checkbox" name="slideshow_enabled" value="1"' . (!empty($slideshow['enabled']) ? ' checked' : '') . '> Ieslēgt slideshow</label>';
    $html .= '<label>Intervāls (sek.)<input type="number" name="slideshow_interval" min="2" max="60" value="' . (int) ($slideshow['interval_sec'] ?? 5) . '"></label>';
    $html .= '<p class="muted">Tavi favorīti: <strong>' . $favCount . '</strong></p>';
    if (($slideshow['audio_file'] ?? '') !== '') {
        $url = efpic_gallery_asset_url($config, $galleryToken, (string) $slideshow['audio_file']);
        $html .= '<p class="admin-ok">MP3: <a href="' . efpic_client_esc($url) . '" target="_blank" rel="noopener">'
            . efpic_client_esc((string) $slideshow['audio_file']) . '</a></p>';
        $html .= '<label class="admin-check"><input type="checkbox" name="remove_slideshow_audio" value="1"> Dzēst MP3</label>';
    }
    $html .= '<label>Augšupielādēt MP3<input type="file" name="slideshow_mp3" accept="audio/mpeg,.mp3"></label>';
    $html .= '<button type="submit" class="btn primary">Saglabāt slideshow</button>';
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
    $html .= '</select></label>';
    $html .= '<button type="button" class="btn primary admin-btn-inline" id="portal-add-embed-video">Pievienot video</button>';
    $html .= '</div></div>';
    $html .= '<button type="submit" class="btn primary">Saglabāt video</button>';
    $html .= '</form></fieldset>';

    return $html;
}

function efpic_portal_render_sidebar(string $name, array $config, string $galleryToken): string
{
    $nav = [
        ['id' => 'admin-tab-images', 'label' => 'Bildes'],
        ['id' => 'admin-tab-scenes', 'label' => 'Sadaļas'],
        ['id' => 'admin-tab-share', 'label' => 'Kopīgošana'],
        ['id' => 'admin-tab-media', 'label' => 'Slideshow & video'],
    ];

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
        $active = $i === 0 ? ' active' : '';
        $selected = $i === 0 ? 'true' : 'false';
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

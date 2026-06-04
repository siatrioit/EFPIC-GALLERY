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

    $body = '<div class="admin-shell admin-shell--portal">';
    $body .= '<div class="admin-workspace">';
    $body .= efpic_client_topbar($name, '<a class="btn" href="' . efpic_client_esc(efpic_gallery_view_url($config, $gt)) . '">Publiskā galerija</a>', 'admin-portal-topbar');
    $body .= '<main class="admin-main">';
    if ($flash !== '') {
        $body .= '<p class="admin-flash">' . efpic_client_esc($flash) . '</p>';
    }

    $body .= efpic_portal_render_tabs_nav();
    $body .= efpic_admin_tab_panel_open('admin-tab-images', true);
    $body .= '<div class="admin-share-edit-bar" id="admin-share-edit-bar" hidden>';
    $body .= '<span class="admin-share-edit-bar__label" id="admin-share-edit-bar-label"></span>';
    $body .= '<button type="button" class="btn admin-btn-sm primary" id="admin-share-edit-save">Saglabāt izlasi</button>';
    $body .= '<button type="button" class="btn admin-btn-sm" id="admin-share-edit-cancel">Atcelt</button>';
    $body .= '</div>';
    $body .= efpic_portal_render_image_grid($config, $images, $commentsEnabled);
    $body .= efpic_admin_tab_panel_close();

    $body .= efpic_admin_tab_panel_open('admin-tab-share');
    $body .= '<div id="admin-share-sets-body">' . efpic_admin_render_share_sets_body($config, $meta) . '</div>';
    $body .= efpic_admin_tab_panel_close();

    $body .= efpic_admin_tab_panel_open('admin-tab-media');
    $body .= '<section class="admin-fieldset-full"><h2 class="admin-share-block-title">Tava slideshow (favorīti + mūzika)</h2>';
    $body .= '<p class="muted">Atzīmē favorītus cilnē Bildes un augšupielādē MP3. Kad slideshow ir gatava, tā kļūst par galveno publiskajā galerijā.</p>';
    $body .= '<form method="post" enctype="multipart/form-data" class="admin-form-split portal-stack">';
    $body .= '<input type="hidden" name="portal_action" value="save_slideshow">';
    $body .= '<label class="admin-check"><input type="checkbox" name="slideshow_enabled" value="1"' . ($slideshow['enabled'] ? ' checked' : '') . '> Ieslēgt slideshow</label>';
    $body .= '<label>Intervāls (sek.)<input type="number" name="slideshow_interval" min="2" max="60" value="' . (int) $slideshow['interval_sec'] . '"></label>';
    $body .= '<p class="muted">Tavi favorīti: <strong>' . $favCount . '</strong></p>';
    if ($slideshow['audio_file'] !== '') {
        $body .= '<p><a href="' . efpic_client_esc(efpic_gallery_asset_url($config, $gt, $slideshow['audio_file'])) . '" target="_blank" rel="noopener">Pašreizējais MP3</a></p>';
        $body .= '<label class="admin-check"><input type="checkbox" name="remove_slideshow_audio" value="1"> Dzēst MP3</label>';
    }
    $body .= '<label>MP3 fails<input type="file" name="slideshow_mp3" accept="audio/mpeg,.mp3"></label>';
    $body .= '<button type="submit" class="btn primary">Saglabāt slideshow</button></form></section>';

    $body .= '<section class="admin-fieldset-full"><h2 class="admin-share-block-title">Video galerijā</h2>';
    $body .= '<div id="admin-videos-list" class="admin-videos-list">';
    $body .= efpic_admin_render_existing_videos_list($config, $meta, $gt, true);
    $body .= '</div>';
    $body .= '<form method="post" enctype="multipart/form-data" class="admin-form-split portal-stack">';
    $body .= '<input type="hidden" name="portal_action" value="upload_video">';
    $body .= '<label>Video fails (MP4)<input type="file" name="gallery_video" accept="video/mp4,video/quicktime,video/webm"></label>';
    $body .= '<label>Virsraksts<input name="video_upload_title"></label>';
    $body .= '<label>Sadaļa<select name="video_upload_scene">';
    foreach ($scenes as $scene) {
        $body .= '<option value="' . efpic_client_esc($scene['id']) . '">' . efpic_client_esc($scene['title']) . '</option>';
    }
    $body .= '</select></label><button type="submit" class="btn">Augšupielādēt video</button></form>';
    $body .= '<form method="post" class="admin-form-split portal-stack">';
    $body .= '<input type="hidden" name="portal_action" value="add_video_embed">';
    $body .= '<label>YouTube / Vimeo<input name="video_embed_url" placeholder="https://..."></label>';
    $body .= '<label>Virsraksts<input name="video_embed_title"></label>';
    $body .= '<label>Sadaļa<select name="video_embed_scene">';
    foreach ($scenes as $scene) {
        $body .= '<option value="' . efpic_client_esc($scene['id']) . '">' . efpic_client_esc($scene['title']) . '</option>';
    }
    $body .= '</select></label><button type="submit" class="btn">Pievienot embed</button></form></section>';
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
        $html .= '<li class="admin-media-card' . ($hidden ? ' is-hidden' : '') . '" data-token="' . efpic_client_esc($tok) . '">';
        $html .= '<label class="admin-bulk-pick portal-share-pick-label"><input type="checkbox" class="admin-image-pick portal-share-pick" value="'
            . efpic_client_esc($tok) . '" aria-label="Izlasei"></label>';
        $html .= '<div class="admin-media-thumb"><img src="' . efpic_client_esc($thumb) . '" alt="" width="320" height="240" loading="lazy" decoding="async"></div>';
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

function efpic_portal_render_tabs_nav(): string
{
    $tabs = [
        ['id' => 'admin-tab-images', 'label' => 'Bildes'],
        ['id' => 'admin-tab-share', 'label' => 'Kopīgošana'],
        ['id' => 'admin-tab-media', 'label' => 'Slideshow & video'],
        ['id' => 'admin-tab-settings', 'label' => 'Iestatījumi'],
    ];
    $out = '<nav class="admin-edit-tabs" role="tablist" aria-label="Klienta panelis">';
    foreach ($tabs as $i => $tab) {
        $active = $i === 0 ? ' is-active' : '';
        $selected = $i === 0 ? 'true' : 'false';
        $out .= '<button type="button" class="admin-edit-tab' . $active . '" role="tab" id="'
            . efpic_client_esc($tab['id']) . '-tab" aria-selected="' . $selected . '" aria-controls="'
            . efpic_client_esc($tab['id']) . '" data-admin-tab="' . efpic_client_esc($tab['id']) . '">'
            . efpic_client_esc($tab['label']) . '</button>';
    }
    $out .= '</nav>';

    return $out;
}

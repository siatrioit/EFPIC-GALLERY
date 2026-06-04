<?php

declare(strict_types=1);

require_once __DIR__ . '/client_handlers.php';
require_once __DIR__ . '/gallery_assets.php';

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
                'invite_guest' => (function () use ($config, $slug, &$meta) {
                    $label = trim((string) ($_POST['guest_label'] ?? 'Viesis'));
                    $guests = $meta['guests'] ?? [];
                    if (!is_array($guests)) {
                        $guests = [];
                    }
                    $guests[] = [
                        'guest_token' => efpic_random_hex(16),
                        'label' => $label !== '' ? $label : 'Viesis',
                        'created_at' => gmdate('c'),
                        'created_by' => 'client',
                    ];
                    $meta['guests'] = $guests;
                    efpic_save_gallery_meta($config, $slug, $meta);
                })(),
                'create_share_set' => (function () use ($config, $slug, &$meta) {
                    $label = trim((string) ($_POST['share_set_label'] ?? ''));
                    $posted = $_POST['share_image_tokens'] ?? [];
                    if (!is_array($posted)) {
                        $posted = [];
                    }
                    $entry = efpic_create_share_set($meta, $label, $posted, 'client');
                    efpic_save_gallery_meta($config, $slug, $meta);
                    $gt = (string) ($meta['gallery_token'] ?? '');
                    $url = efpic_gallery_view_url($config, $gt, $entry['guest_token']);
                    $_SESSION['efpic_portal_flash'] = 'Izlase «' . $entry['label'] . '» izveidota: ' . $url;
                })(),
                'delete_share_set' => (function () use ($config, $slug, &$meta) {
                    efpic_delete_share_set($meta, (string) ($_POST['share_token'] ?? ''));
                    efpic_save_gallery_meta($config, $slug, $meta);
                    $_SESSION['efpic_portal_flash'] = 'Izlase dzēsta.';
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

    $body = efpic_client_topbar($name, '<a class="btn" href="' . efpic_client_esc(efpic_gallery_view_url($config, $gt)) . '">Publiskā galerija</a>');
    if ($flash !== '') {
        $body .= '<p class="feed-empty">' . efpic_client_esc($flash) . '</p>';
    }

    $gt = (string) ($meta['gallery_token'] ?? '');
    $slots = efpic_gallery_slideshows_struct($meta);
    $slideshow = $slots['client'];
    $favCount = efpic_count_favorites($meta, 'client');
    $scenes = efpic_gallery_scene_options($meta);
    $settings = efpic_gallery_settings($meta);
    $disableAllWeb = !empty($settings['disable_public_download_all_web']);
    $disableAllFull = !empty($settings['disable_public_download_all_full']);
    $commentsEnabled = efpic_client_comments_enabled($meta);
    $heroAccent = efpic_client_hero_accent_color($meta);
    $pageBg = efpic_client_page_bg_color($config, $meta);

    $body .= '<div class="portal-main">';
    $body .= efpic_portal_render_tabs_nav();
    $body .= '<div class="portal-tab-panel" id="portal-tab-images" data-portal-tab-panel>';

    $body .= '<div class="grid portal-grid">';
    foreach ($images as $img) {
        $tok = (string) ($img['token'] ?? '');
        $hidden = !empty($img['client_hidden']);
        $fav = efpic_image_favorited_client($img);
        $body .= '<div class="portal-card' . ($hidden ? ' is-hidden' : '') . '">';
        $body .= '<label class="portal-share-pick-label"><input type="checkbox" class="portal-share-pick" value="' . efpic_client_esc($tok) . '"> Izlasei</label>';
        $body .= '<img src="' . efpic_client_esc(efpic_client_media_url($config, $img, 'web')) . '" alt="">';
        $body .= '<div class="portal-card-actions">';
        $body .= '<div class="portal-card-actions__row portal-card-actions__row--toggles">';
        $body .= '<form method="post" class="portal-card-toggle-form"><input type="hidden" name="portal_action" value="toggle_favorite">';
        $body .= '<input type="hidden" name="image_token" value="' . efpic_client_esc($tok) . '">';
        $body .= '<button type="submit" class="portal-media-toggle' . ($fav ? ' is-active' : '') . '">Favorīts</button></form>';
        $body .= '<form method="post" class="portal-card-toggle-form"><input type="hidden" name="portal_action" value="toggle_hidden">';
        $body .= '<input type="hidden" name="image_token" value="' . efpic_client_esc($tok) . '">';
        $body .= '<button type="submit" class="portal-media-toggle' . ($hidden ? ' is-active' : '') . '">'
            . ($hidden ? 'Slēpts' : 'Slēpt') . '</button></form>';
        $body .= '</div>';
        if ($commentsEnabled) {
            $body .= '<form method="post" class="comment-form portal-card-comment"><input type="hidden" name="portal_action" value="add_comment">';
            $body .= '<input type="hidden" name="image_token" value="' . efpic_client_esc($tok) . '">';
            $body .= '<input name="comment" placeholder="Komentārs"><button type="submit" class="btn">+</button></form>';
        }
        $body .= '</div></div>';
    }
    $body .= '</div>';
    $body .= '</div>';

    $body .= '<div class="portal-tab-panel" id="portal-tab-share" data-portal-tab-panel hidden>';
    $body .= '<section class="portal-panel portal-share-sets"><h2>Kopīgojamās izlases</h2>';
    $body .= '<p class="muted">Atzīmē bildes cilnē <strong>Bildes</strong>, ievadi nosaukumu (piem. «Dekorators») un izveido saiti — saņēmējs redzēs <strong>tikai</strong> tās bildes.</p>';
    $body .= '<form method="post" class="portal-share-set-form">';
    $body .= '<input type="hidden" name="portal_action" value="create_share_set">';
    $body .= '<label>Nosaukums<input name="share_set_label" placeholder="Dekorators Anna" required></label>';
    $body .= '<button type="submit" class="btn primary" id="portal-create-share-set">Izveidot izlasi no atzīmētajām</button>';
    $body .= '<p class="muted portal-pick-hint">Atzīmē bildes ar ☑ cilnē Bildes pirms izveides.</p></form>';

    if (($meta['guests'] ?? []) !== []) {
        $body .= '<ul class="portal-share-set-list">';
        foreach ($meta['guests'] as $g) {
            if (!is_array($g)) {
                continue;
            }
            $gtok = (string) ($g['guest_token'] ?? '');
            $url = efpic_gallery_view_url($config, $gt, $gtok);
            $n = efpic_share_set_image_count($g);
            $body .= '<li class="portal-share-set-item">';
            $body .= '<strong>' . efpic_client_esc((string) ($g['label'] ?? '')) . '</strong> ';
            $body .= '<span class="muted">(' . ($n > 0 ? $n . ' bildes' : 'visa galerija') . ')</span><br>';
            $body .= '<a href="' . efpic_client_esc($url) . '">' . efpic_client_esc($url) . '</a> ';
            $body .= '<form method="post" class="inline-form" onsubmit="return confirm(\'Dzēst izlasi?\')">';
            $body .= '<input type="hidden" name="portal_action" value="delete_share_set">';
            $body .= '<input type="hidden" name="share_token" value="' . efpic_client_esc($gtok) . '">';
            $body .= '<button type="submit" class="btn">Dzēst</button></form></li>';
        }
        $body .= '</ul>';
    }
    $body .= '</section>';
    $body .= '<section class="portal-toolbar">';
    $body .= '<form method="post" class="inline-form"><input type="hidden" name="portal_action" value="invite_guest">';
    $body .= '<input name="guest_label" placeholder="Pilna saite (visa galerija)"><button type="submit" class="btn">Jauna pilna saite</button></form></section>';
    $body .= '</div>';

    $body .= '<div class="portal-tab-panel" id="portal-tab-media" data-portal-tab-panel hidden>';
    $body .= '<section class="portal-panel"><h2>Tava slideshow (favorīti + mūzika)</h2>';
    $body .= '<p class="muted">Atzīmē favorītus cilnē Bildes un augšupielādē MP3. Kad slideshow ir gatava, tā kļūst par galveno publiskajā galerijā.</p>';
    $body .= '<form method="post" enctype="multipart/form-data" class="portal-stack">';
    $body .= '<input type="hidden" name="portal_action" value="save_slideshow">';
    $body .= '<label class="portal-check"><input type="checkbox" name="slideshow_enabled" value="1"' . ($slideshow['enabled'] ? ' checked' : '') . '> Ieslēgt slideshow</label>';
    $body .= '<label>Intervāls (sek.)<input type="number" name="slideshow_interval" min="2" max="60" value="' . (int) $slideshow['interval_sec'] . '"></label>';
    $body .= '<p class="muted">Tavi favorīti: <strong>' . $favCount . '</strong></p>';
    if ($slideshow['audio_file'] !== '') {
        $body .= '<p><a href="' . efpic_client_esc(efpic_gallery_asset_url($config, $gt, $slideshow['audio_file'])) . '" target="_blank" rel="noopener">Pašreizējais MP3</a></p>';
        $body .= '<label class="portal-check"><input type="checkbox" name="remove_slideshow_audio" value="1"> Dzēst MP3</label>';
    }
    $body .= '<label>MP3 fails<input type="file" name="slideshow_mp3" accept="audio/mpeg,.mp3"></label>';
    $body .= '<button type="submit" class="btn primary">Saglabāt slideshow</button></form></section>';

    $body .= '<section class="portal-panel"><h2>Video galerijā</h2>';
    if (($meta['videos'] ?? []) !== []) {
        $body .= '<ul class="portal-video-list">';
        foreach ($meta['videos'] as $video) {
            if (!is_array($video)) {
                continue;
            }
            $label = (string) ($video['title'] ?? '');
            if ($label === '') {
                $label = ($video['kind'] ?? '') === 'embed' ? (string) ($video['provider'] ?? 'video') : (string) ($video['file'] ?? '');
            }
            $body .= '<li>' . efpic_client_esc($label) . '</li>';
        }
        $body .= '</ul>';
    }
    $body .= '<form method="post" enctype="multipart/form-data" class="portal-stack">';
    $body .= '<input type="hidden" name="portal_action" value="upload_video">';
    $body .= '<label>Video fails (MP4)<input type="file" name="gallery_video" accept="video/mp4,video/quicktime,video/webm"></label>';
    $body .= '<label>Virsraksts<input name="video_upload_title"></label>';
    $body .= '<label>Sadaļa<select name="video_upload_scene">';
    foreach ($scenes as $scene) {
        $body .= '<option value="' . efpic_client_esc($scene['id']) . '">' . efpic_client_esc($scene['title']) . '</option>';
    }
    $body .= '</select></label><button type="submit" class="btn">Augšupielādēt video</button></form>';
    $body .= '<form method="post" class="portal-stack">';
    $body .= '<input type="hidden" name="portal_action" value="add_video_embed">';
    $body .= '<label>YouTube / Vimeo<input name="video_embed_url" placeholder="https://..."></label>';
    $body .= '<label>Virsraksts<input name="video_embed_title"></label>';
    $body .= '<label>Sadaļa<select name="video_embed_scene">';
    foreach ($scenes as $scene) {
        $body .= '<option value="' . efpic_client_esc($scene['id']) . '">' . efpic_client_esc($scene['title']) . '</option>';
    }
    $body .= '</select></label><button type="submit" class="btn">Pievienot embed</button></form></section>';
    $body .= '</div>';

    $body .= '<div class="portal-tab-panel" id="portal-tab-settings" data-portal-tab-panel hidden>';
    $body .= '<section class="portal-panel portal-appearance"><h2>Izskats</h2>';
    $body .= '<form method="post" class="inline-form portal-theme-form">';
    $body .= '<input type="hidden" name="portal_action" value="set_theme"><label>Tēma<select name="theme" onchange="this.form.submit()">';
    foreach (efpic_gallery_theme_options() as $themeKey => $themeLabel) {
        $sel = $themeKey === $theme ? ' selected' : '';
        $body .= '<option value="' . efpic_client_esc($themeKey) . '"' . $sel . '>' . efpic_client_esc($themeLabel) . '</option>';
    }
    $body .= '</select></label></form>';
    $body .= '<form method="post" class="portal-color-form">';
    $body .= '<input type="hidden" name="portal_action" value="save_gallery_colors">';
    $body .= efpic_client_color_field('hero_accent_color', 'Vāka krāsa', $heroAccent);
    $body .= efpic_client_color_field('page_bg_color', 'Galerijas pamatkrāsa', $pageBg);
    $body .= '<button type="submit" class="btn primary">Saglabāt krāsas</button></form></section>';

    $body .= '<section class="portal-panel"><h2>Lejupielādes publiskajā galerijā</h2>';
    $body .= '<p class="muted">Atzīmē, lai apmeklētājiem vairs nerādītos «lejupielādēt visas bildes» attiecīgajā izmērā. Izvēlētās bildes (izlase) joprojām var lejupielādēt, ja izmērs ir atļauts.</p>';
    $body .= '<form method="post" class="portal-stack">';
    $body .= '<input type="hidden" name="portal_action" value="save_download_settings">';
    $body .= '<label class="portal-check"><input type="checkbox" name="disable_public_download_all_web" value="1"'
        . ($disableAllWeb ? ' checked' : '') . '> Atslēgt «visas bildes» — WEB</label>';
    $body .= '<label class="portal-check"><input type="checkbox" name="disable_public_download_all_full" value="1"'
        . ($disableAllFull ? ' checked' : '') . '> Atslēgt «visas bildes» — PRINT</label>';
    $body .= '<button type="submit" class="btn primary">Saglabāt</button></form></section>';
    $body .= '</div></div>';

    $body .= '<script>(function(){function bindColorInputs(root){root.querySelectorAll(".portal-color-input, .admin-color-input").forEach(function(input){var wrap=input.closest(".portal-color-control, .admin-color-control");if(!wrap)return;var swatch=wrap.querySelector(".portal-color-swatch, .admin-color-swatch");var code=wrap.querySelector(".portal-color-value, .admin-color-value");var sync=function(){if(swatch)swatch.style.backgroundColor=input.value;if(code)code.textContent=input.value;};input.addEventListener("input",sync);sync();});}bindColorInputs(document);})();</script>';

    $body .= '<script>(function(){var form=document.querySelector(".portal-share-set-form");if(!form)return;form.addEventListener("submit",function(e){var picks=document.querySelectorAll(".portal-share-pick:checked");if(!picks.length){e.preventDefault();window.alert("Atzīmē vismaz vienu bildi izlasei.");return;}form.querySelectorAll("input[name=\\"share_image_tokens[]\\"]").forEach(function(n){n.remove();});picks.forEach(function(cb){var inp=document.createElement("input");inp.type="hidden";inp.name="share_image_tokens[]";inp.value=cb.value;form.appendChild(inp);});});})();</script>';

    efpic_client_html($name . ' — panelis', $body, $config, 'page-portal theme-' . preg_replace('/[^a-z0-9-]/', '', $theme));
}

function efpic_portal_render_tabs_nav(): string
{
    $tabs = [
        ['id' => 'portal-tab-images', 'label' => 'Bildes'],
        ['id' => 'portal-tab-share', 'label' => 'Kopīgošana'],
        ['id' => 'portal-tab-media', 'label' => 'Slideshow & video'],
        ['id' => 'portal-tab-settings', 'label' => 'Iestatījumi'],
    ];
    $out = '<nav class="portal-edit-tabs" role="tablist" aria-label="Klienta panelis">';
    foreach ($tabs as $i => $tab) {
        $active = $i === 0 ? ' is-active' : '';
        $selected = $i === 0 ? 'true' : 'false';
        $out .= '<button type="button" class="portal-edit-tab' . $active . '" role="tab" id="'
            . efpic_client_esc($tab['id']) . '-tab" aria-selected="' . $selected . '" aria-controls="'
            . efpic_client_esc($tab['id']) . '" data-portal-tab="' . efpic_client_esc($tab['id']) . '">'
            . efpic_client_esc($tab['label']) . '</button>';
    }
    $out .= '</nav>';

    return $out;
}

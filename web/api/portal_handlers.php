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
                    $theme = (string) ($_POST['theme'] ?? 'classic');
                    if (!in_array($theme, ['classic', 'masonry', 'dark'], true)) {
                        $theme = 'classic';
                    }
                    $meta['client_theme'] = $theme;
                    efpic_save_gallery_meta($config, $slug, $meta);
                })(),
                'add_comment' => (function () use ($config, $slug, &$meta, $imageToken) {
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
                    ];
                    $meta['guests'] = $guests;
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

    $ctx = ['role' => 'main_client', 'guest_token' => '', 'hide_client_hidden' => false];
    $images = [];
    foreach (efpic_sort_images_for_display($meta) as $img) {
        if (is_array($img)) {
            $images[] = $img;
        }
    }

    $gt = (string) ($meta['gallery_token'] ?? '');
    $name = (string) ($meta['name'] ?? '');
    $theme = (string) ($meta['client_theme'] ?? $meta['theme'] ?? 'classic');

    $body = efpic_client_topbar($name, '<a class="btn" href="' . efpic_client_esc(efpic_gallery_view_url($config, $gt)) . '">Publiskā galerija</a>');
    if ($flash !== '') {
        $body .= '<p class="feed-empty">' . efpic_client_esc($flash) . '</p>';
    }

    $gt = (string) ($meta['gallery_token'] ?? '');
    $slots = efpic_gallery_slideshows_struct($meta);
    $slideshow = $slots['client'];
    $favCount = efpic_count_favorites($meta, 'client');

    $body .= '<section class="portal-panel"><h2>Tava slideshow (favorīti + mūzika)</h2>';
    $body .= '<p class="muted">Atzīmē ★ favorītus zem bildēm un augšupielādē MP3. Kad slideshow ir gatava, tā kļūst par galveno publiskajā galerijā.</p>';
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

    $scenes = efpic_gallery_scene_options($meta);
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

    $body .= '<section class="portal-toolbar"><form method="post" class="inline-form">';
    $body .= '<input type="hidden" name="portal_action" value="set_theme"><label>Tēma<select name="theme" onchange="this.form.submit()">';
    foreach (['classic', 'masonry', 'dark'] as $t) {
        $sel = $t === $theme ? ' selected' : '';
        $body .= '<option value="' . $t . '"' . $sel . '>' . efpic_client_esc($t) . '</option>';
    }
    $body .= '</select></label></form>';
    $body .= '<form method="post" class="inline-form"><input type="hidden" name="portal_action" value="invite_guest">';
    $body .= '<input name="guest_label" placeholder="Viesa nosaukums"><button type="submit" class="btn">Jauns viesis</button></form></section>';

    if (($meta['guests'] ?? []) !== []) {
        $body .= '<section class="portal-guests"><h2>Viesu saites</h2><ul>';
        foreach ($meta['guests'] as $g) {
            if (!is_array($g)) {
                continue;
            }
            $gtok = (string) ($g['guest_token'] ?? '');
            $url = efpic_gallery_view_url($config, $gt, $gtok);
            $body .= '<li><strong>' . efpic_client_esc((string) ($g['label'] ?? '')) . '</strong> ';
            $body .= '<a href="' . efpic_client_esc($url) . '">' . efpic_client_esc($url) . '</a></li>';
        }
        $body .= '</ul></section>';
    }

    $body .= '<div class="grid portal-grid">';
    foreach ($images as $img) {
        $tok = (string) ($img['token'] ?? '');
        $hidden = !empty($img['client_hidden']);
        $fav = efpic_image_favorited_client($img);
        $body .= '<div class="portal-card' . ($hidden ? ' is-hidden' : '') . '">';
        $body .= '<img src="' . efpic_client_esc(efpic_client_media_url($config, $img, 'web')) . '" alt="">';
        $body .= '<div class="portal-card-actions">';
        $body .= '<form method="post"><input type="hidden" name="portal_action" value="toggle_hidden">';
        $body .= '<input type="hidden" name="image_token" value="' . efpic_client_esc($tok) . '">';
        $body .= '<button type="submit" class="btn">' . ($hidden ? 'Rādīt viesiem' : 'Slēpt viesiem') . '</button></form>';
        $body .= '<form method="post"><input type="hidden" name="portal_action" value="toggle_favorite">';
        $body .= '<input type="hidden" name="image_token" value="' . efpic_client_esc($tok) . '">';
        $body .= '<button type="submit" class="btn">' . ($fav ? '★ Favorīts' : '☆ Favorīts') . '</button></form>';
        $body .= '<form method="post" class="comment-form"><input type="hidden" name="portal_action" value="add_comment">';
        $body .= '<input type="hidden" name="image_token" value="' . efpic_client_esc($tok) . '">';
        $body .= '<input name="comment" placeholder="Komentārs"><button type="submit" class="btn">+</button></form>';
        $body .= '</div></div>';
    }
    $body .= '</div>';

    efpic_client_html($name . ' — panelis', $body, $config, 'page-portal theme-' . preg_replace('/[^a-z0-9-]/', '', $theme));
}

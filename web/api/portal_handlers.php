<?php

declare(strict_types=1);

require_once __DIR__ . '/client_handlers.php';

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
                        $meta['images'][$i]['favorited'] = empty($img['favorited']);
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
        $fav = !empty($img['favorited']);
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

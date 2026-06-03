<?php

declare(strict_types=1);

function efpic_image_favorited_admin(array $img): bool
{
    return !empty($img['favorited_admin']);
}

function efpic_image_favorited_client(array $img): bool
{
    if (!empty($img['favorited_client'])) {
        return true;
    }

    return !empty($img['favorited']);
}

function efpic_count_favorites(array $meta, string $who): int
{
    $n = 0;
    foreach ($meta['images'] ?? [] as $img) {
        if (!is_array($img)) {
            continue;
        }
        if ($who === 'admin' && efpic_image_favorited_admin($img)) {
            $n++;
        } elseif ($who === 'client' && efpic_image_favorited_client($img)) {
            $n++;
        }
    }

    return $n;
}

function efpic_client_session_start(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function efpic_gallery_has_password(array $meta): bool
{
    return ($meta['password_hash'] ?? '') !== '';
}

function efpic_verify_gallery_password(array $meta, string $password): bool
{
    return efpic_verify_password_hash($password, (string) ($meta['password_hash'] ?? ''));
}

function efpic_gallery_session_unlocked(string $galleryToken): bool
{
    efpic_client_session_start();
    $unlocked = $_SESSION['efpic_gallery_unlocked'] ?? [];

    return is_array($unlocked) && !empty($unlocked[$galleryToken]);
}

function efpic_set_gallery_session_unlocked(string $galleryToken): void
{
    efpic_client_session_start();
    if (!isset($_SESSION['efpic_gallery_unlocked']) || !is_array($_SESSION['efpic_gallery_unlocked'])) {
        $_SESSION['efpic_gallery_unlocked'] = [];
    }
    $_SESSION['efpic_gallery_unlocked'][$galleryToken] = true;
}

function efpic_gallery_view_url(array $config, string $galleryToken, ?string $guestToken = null): string
{
    $url = efpic_base_url($config) . '/v/g/' . rawurlencode($galleryToken);
    if ($guestToken !== null && $guestToken !== '') {
        $url .= '?g=' . rawurlencode($guestToken);
    }

    return $url;
}

function efpic_image_view_url(array $config, string $imageToken): string
{
    return efpic_base_url($config) . '/v/i/' . rawurlencode($imageToken);
}

function efpic_portal_url(array $config, string $portalToken): string
{
    return efpic_base_url($config) . '/c/p/' . rawurlencode($portalToken);
}

function efpic_find_gallery_by_token(array $config, string $galleryToken): ?array
{
    $index = efpic_load_access_index($config);
    $slug = $index['galleries'][$galleryToken] ?? null;
    if ($slug === null) {
        foreach (efpic_list_gallery_slugs($config) as $candidate) {
            $m = efpic_load_gallery_meta($config, $candidate);
            if ($m !== null && hash_equals((string) ($m['gallery_token'] ?? ''), $galleryToken)) {
                $slug = $candidate;
                efpic_rebuild_access_index($config);
                break;
            }
        }
    }
    if ($slug === null) {
        return null;
    }
    $meta = efpic_load_gallery_meta($config, $slug);
    if ($meta === null) {
        return null;
    }

    return [
        'slug' => $slug,
        'meta' => $meta,
        'dir' => efpic_gallery_dir($config, $slug),
    ];
}

function efpic_find_image_by_token(array $config, string $imageToken): ?array
{
    $index = efpic_load_access_index($config);
    $ref = $index['images'][$imageToken] ?? null;
    $slug = is_array($ref) ? (string) ($ref['slug'] ?? '') : '';
    if ($slug === '') {
        foreach (efpic_list_gallery_slugs($config) as $candidate) {
            $m = efpic_load_gallery_meta($config, $candidate);
            if ($m === null) {
                continue;
            }
            foreach ($m['images'] ?? [] as $img) {
                if (is_array($img) && hash_equals((string) ($img['token'] ?? ''), $imageToken)) {
                    $slug = $candidate;
                    efpic_rebuild_access_index($config);
                    break 2;
                }
            }
        }
    }
    if ($slug === '') {
        return null;
    }
    $meta = efpic_load_gallery_meta($config, $slug);
    if ($meta === null) {
        return null;
    }

    $file = '';
    $path = '';
    $imgRow = null;
    foreach ($meta['images'] ?? [] as $img) {
        if (!is_array($img)) {
            continue;
        }
        if (($img['token'] ?? '') === $imageToken) {
            $imgRow = $img;
            $file = (string) ($img['file'] ?? '');
            break;
        }
    }

    if ($imgRow === null) {
        return null;
    }

    if (!efpic_is_delivery_gallery($meta) && $file !== '') {
        $path = efpic_gallery_dir($config, $slug) . DIRECTORY_SEPARATOR . $file;
    }

    return [
        'slug' => $slug,
        'meta' => $meta,
        'dir' => efpic_gallery_dir($config, $slug),
        'file' => $file,
        'path' => $path,
        'image' => $imgRow,
    ];
}

/** @return array{role: string, guest_token: string, hide_client_hidden: bool} */
function efpic_viewer_context(array $config, array $meta): array
{
    $guestToken = trim((string) ($_GET['g'] ?? ''));
    $hideHidden = true;

    if ($guestToken !== '') {
        foreach ($meta['guests'] ?? [] as $g) {
            if (is_array($g) && ($g['guest_token'] ?? '') === $guestToken) {
                return ['role' => 'guest', 'guest_token' => $guestToken, 'hide_client_hidden' => true];
            }
        }
    }

    $settings = efpic_gallery_settings($meta);
    if (empty($settings['hide_client_hidden_from_public'])) {
        $hideHidden = false;
    }

    return ['role' => 'public', 'guest_token' => '', 'hide_client_hidden' => $hideHidden];
}

function efpic_scene_visible_to_viewer(array $scene, array $ctx): bool
{
    if (($ctx['role'] ?? '') !== 'guest') {
        return true;
    }

    return empty($scene['hidden_from_guests']);
}

function efpic_image_visible_to_viewer(array $img, array $meta, array $ctx): bool
{
    if (!empty($ctx['hide_client_hidden']) && !empty($img['client_hidden'])) {
        return false;
    }

    $sceneId = (string) ($img['scene_id'] ?? 'main');
    foreach ($meta['scenes'] ?? [] as $scene) {
        if (!is_array($scene)) {
            continue;
        }
        if (($scene['id'] ?? '') === $sceneId) {
            return efpic_scene_visible_to_viewer($scene, $ctx);
        }
    }

    return true;
}

function efpic_sort_images_for_display(array $meta): array
{
    $images = $meta['images'] ?? [];
    if (!is_array($images)) {
        return [];
    }

    $sceneOrder = [];
    foreach ($meta['scenes'] ?? [] as $scene) {
        if (is_array($scene)) {
            $sceneOrder[(string) ($scene['id'] ?? '')] = (int) ($scene['sort'] ?? 0);
        }
    }

    usort($images, static function ($a, $b) use ($sceneOrder) {
        if (!is_array($a) || !is_array($b)) {
            return 0;
        }
        $sa = $sceneOrder[(string) ($a['scene_id'] ?? 'main')] ?? 0;
        $sb = $sceneOrder[(string) ($b['scene_id'] ?? 'main')] ?? 0;
        if ($sa !== $sb) {
            return $sa <=> $sb;
        }

        $bySort = ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0));
        if ($bySort !== 0) {
            return $bySort;
        }

        return efpic_compare_image_basenames($a, $b);
    });

    return $images;
}

function efpic_client_hero_accent_color(array $meta): string
{
    $color = trim((string) ($meta['hero_accent_color'] ?? ''));
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1) {
        return strtolower($color);
    }

    return '#9a9578';
}

function efpic_client_hero_text_color(string $hex): string
{
    $r = hexdec(substr($hex, 1, 2));
    $g = hexdec(substr($hex, 3, 2));
    $b = hexdec(substr($hex, 5, 2));
    $lum = 0.299 * $r + 0.587 * $g + 0.114 * $b;

    return $lum > 145 ? '#1a1a1a' : '#ffffff';
}

function efpic_client_brand_name(array $config): string
{
    $name = trim((string) ($config['guest_delivery']['email']['from_name'] ?? ''));

    return $name !== '' ? $name : 'EdgarsFoto';
}

function efpic_client_gallery_byline(array $config): string
{
    $settings = efpic_load_app_settings($config);
    $line = trim((string) ($settings['gallery_byline'] ?? ''));
    if ($line === '') {
        return 'Gallery by ' . efpic_client_brand_name($config);
    }

    return $line;
}

function efpic_client_page_bg_color(array $config, array $meta): string
{
    $perGallery = trim((string) ($meta['page_bg_color'] ?? ''));
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $perGallery) === 1) {
        return strtolower($perGallery);
    }
    $settings = efpic_load_app_settings($config);
    $bg = trim((string) ($settings['gallery_page_bg'] ?? '#ffffff'));
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $bg) === 1) {
        return strtolower($bg);
    }

    return '#ffffff';
}

/** @return array{mobile: int, tablet: int, desktop: int} */
function efpic_client_gallery_feed_gaps(array $config): array
{
    $defaults = efpic_app_settings_defaults();
    $settings = efpic_load_app_settings($config);
    $mobile = efpic_sanitize_gallery_feed_gap($settings['gallery_feed_gap'] ?? null, (int) $defaults['gallery_feed_gap']);
    $tablet = efpic_sanitize_gallery_feed_gap(
        $settings['gallery_feed_gap_tablet'] ?? null,
        (int) $defaults['gallery_feed_gap_tablet']
    );
    $desktop = efpic_sanitize_gallery_feed_gap(
        $settings['gallery_feed_gap_desktop'] ?? null,
        (int) $defaults['gallery_feed_gap_desktop']
    );

    return [
        'mobile' => $mobile,
        'tablet' => $tablet,
        'desktop' => $desktop,
    ];
}

function efpic_gallery_image_focus_hash(string $imageToken): string
{
    return '#pic-' . $imageToken;
}

function efpic_client_format_event_date(string $date): string
{
    $date = trim($date);
    if ($date === '') {
        return '';
    }
    $ts = strtotime(substr($date, 0, 10));
    if ($ts === false) {
        return $date;
    }
    $months = [
        1 => 'janvāris', 2 => 'februāris', 3 => 'marts', 4 => 'aprīlis',
        5 => 'maijs', 6 => 'jūnijs', 7 => 'jūlijs', 8 => 'augusts',
        9 => 'septembris', 10 => 'oktobris', 11 => 'novembris', 12 => 'decembris',
    ];
    $m = (int) date('n', $ts);

    return (int) date('j', $ts) . '. ' . ($months[$m] ?? date('F', $ts)) . ' ' . date('Y', $ts);
}

/** Izvēlas vāka bildes tokenu: favorīti (nejauši) > admin vāks > nākamā redzamā. */
function efpic_resolve_gallery_cover_token(array $meta, array $visibleImages): string
{
    if ($visibleImages === []) {
        return '';
    }

    $visibleTokens = [];
    foreach ($visibleImages as $img) {
        if (!is_array($img)) {
            continue;
        }
        $tok = (string) ($img['token'] ?? '');
        if ($tok !== '') {
            $visibleTokens[] = $tok;
        }
    }
    if ($visibleTokens === []) {
        return '';
    }

    $favorites = [];
    foreach ($visibleImages as $img) {
        if (!is_array($img)) {
            continue;
        }
        if (efpic_image_favorited_admin($img)) {
            $tok = (string) ($img['token'] ?? '');
            if ($tok !== '') {
                $favorites[] = $tok;
            }
        }
    }
    if ($favorites === []) {
        foreach ($visibleImages as $img) {
            if (!is_array($img)) {
                continue;
            }
            if (efpic_image_favorited_client($img)) {
                $tok = (string) ($img['token'] ?? '');
                if ($tok !== '') {
                    $favorites[] = $tok;
                }
            }
        }
    }
    if ($favorites !== []) {
        return $favorites[array_rand($favorites)];
    }

    $adminCover = trim((string) ($meta['cover_image_token'] ?? ''));
    if ($adminCover === '') {
        return $visibleTokens[0];
    }

    if (in_array($adminCover, $visibleTokens, true)) {
        return $adminCover;
    }

    $foundCover = false;
    foreach (efpic_sort_images_for_display($meta) as $img) {
        if (!is_array($img)) {
            continue;
        }
        $tok = (string) ($img['token'] ?? '');
        if ($tok === $adminCover) {
            $foundCover = true;
            continue;
        }
        if ($foundCover && in_array($tok, $visibleTokens, true)) {
            return $tok;
        }
    }

    return $visibleTokens[0];
}

function efpic_delivery_file_hash(array $img, string $size): string
{
    if ($size === 'full') {
        return (string) ($img['failiem_full']['file_hash'] ?? '');
    }

    return (string) ($img['failiem_web']['file_hash'] ?? $img['failiem_full']['file_hash'] ?? '');
}

/** Publiskais attēla URL — tieši no Failiem API (api.files.fm), ne /v/media/. */
function efpic_client_media_url(array $config, array $img, string $size = 'web', int $width = 720): string
{
    $hash = efpic_delivery_file_hash($img, $size === 'full' ? 'full' : 'web');
    if ($hash !== '') {
        if ($size === 'full') {
            return efpic_failiem_download_url($config, $hash);
        }

        return efpic_failiem_thumb_url($config, $hash, $width);
    }
    $token = (string) ($img['token'] ?? '');

    return efpic_base_url($config) . '/v/media/' . rawurlencode($token) . '?size=' . rawurlencode($size);
}

function efpic_client_media_url_for_token(array $config, array $meta, string $token, string $size = 'web', int $width = 720): string
{
    foreach ($meta['images'] ?? [] as $img) {
        if (is_array($img) && ($img['token'] ?? '') === $token) {
            return efpic_client_media_url($config, $img, $size, $width);
        }
    }

    return efpic_base_url($config) . '/v/media/' . rawurlencode($token) . '?size=' . rawurlencode($size);
}

function efpic_can_download_size(array $meta, array $ctx, string $size): bool
{
    $settings = efpic_gallery_settings($meta);
    $downloads = $settings['downloads'] ?? [];
    $role = (string) ($ctx['role'] ?? 'public');
    $allowed = $downloads[$role] ?? $downloads['public'] ?? ['web', 'zip'];
    if (!is_array($allowed)) {
        $allowed = ['web', 'zip'];
    }

    return in_array($size, $allowed, true) || ($size === 'full' && in_array('full', $allowed, true));
}

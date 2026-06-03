<?php

declare(strict_types=1);

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

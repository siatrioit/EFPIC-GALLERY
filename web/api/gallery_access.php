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

function efpic_image_likes_count(array $img): int
{
    return max(0, (int) ($img['likes_count'] ?? 0));
}

function efpic_gallery_total_likes(array $meta): int
{
    $n = 0;
    foreach ($meta['images'] ?? [] as $img) {
        if (is_array($img)) {
            $n += efpic_image_likes_count($img);
        }
    }

    return $n;
}

function efpic_viewer_like_key(): string
{
    efpic_client_session_start();
    if (empty($_SESSION['efpic_viewer_id'])) {
        $_SESSION['efpic_viewer_id'] = bin2hex(random_bytes(16));
    }

    return (string) $_SESSION['efpic_viewer_id'];
}

function efpic_image_liked_by_viewer(array $img, string $viewerKey): bool
{
    $voters = $img['like_voters'] ?? [];
    if (!is_array($voters)) {
        return false;
    }

    return in_array($viewerKey, $voters, true);
}

/** @return array{liked: bool, count: int} */
function efpic_toggle_image_like(array &$meta, int $imageIndex, string $viewerKey): array
{
    if (!isset($meta['images'][$imageIndex]) || !is_array($meta['images'][$imageIndex])) {
        throw new InvalidArgumentException('Bilde nav atrasta');
    }
    $img = &$meta['images'][$imageIndex];
    $voters = $img['like_voters'] ?? [];
    if (!is_array($voters)) {
        $voters = [];
    }
    $count = efpic_image_likes_count($img);
    $liked = in_array($viewerKey, $voters, true);
    if ($liked) {
        $voters = array_values(array_filter($voters, static fn ($v) => $v !== $viewerKey));
        $count = max(0, $count - 1);
    } else {
        $voters[] = $viewerKey;
        $count++;
    }
    $img['like_voters'] = $voters;
    $img['likes_count'] = $count;

    return ['liked' => !$liked, 'count' => $count];
}

/** @return list<string> */
function efpic_client_collection_tokens(string $galleryToken): array
{
    efpic_client_session_start();
    $all = $_SESSION['efpic_collection'] ?? [];
    if (!is_array($all)) {
        return [];
    }
    $list = $all[$galleryToken] ?? [];
    if (!is_array($list)) {
        return [];
    }
    $out = [];
    foreach ($list as $tok) {
        $tok = (string) $tok;
        if ($tok !== '') {
            $out[$tok] = true;
        }
    }

    return array_keys($out);
}

function efpic_client_collection_has(string $galleryToken, string $imageToken): bool
{
    return in_array($imageToken, efpic_client_collection_tokens($galleryToken), true);
}

/** @return array{in_collection: bool, count: int} */
function efpic_client_collection_toggle(string $galleryToken, string $imageToken): array
{
    efpic_client_session_start();
    if (!isset($_SESSION['efpic_collection']) || !is_array($_SESSION['efpic_collection'])) {
        $_SESSION['efpic_collection'] = [];
    }
    $list = efpic_client_collection_tokens($galleryToken);
    $idx = array_search($imageToken, $list, true);
    if ($idx !== false) {
        array_splice($list, $idx, 1);
        $in = false;
    } else {
        $list[] = $imageToken;
        $in = true;
    }
    $_SESSION['efpic_collection'][$galleryToken] = $list;

    return ['in_collection' => $in, 'count' => count($list)];
}

function efpic_client_collection_clear(string $galleryToken): void
{
    efpic_client_session_start();
    if (isset($_SESSION['efpic_collection'][$galleryToken])) {
        unset($_SESSION['efpic_collection'][$galleryToken]);
    }
}

function efpic_compare_images_in_scene(array $a, array $b): int
{
    if (!is_array($a) || !is_array($b)) {
        return 0;
    }
    $manualA = !empty($a['sort_manual']);
    $manualB = !empty($b['sort_manual']);
    if ($manualA && $manualB) {
        $cmp = ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0));
        if ($cmp !== 0) {
            return $cmp;
        }

        return efpic_compare_image_basenames($a, $b);
    }
    if ($manualA !== $manualB) {
        return $manualA ? -1 : 1;
    }

    return efpic_compare_image_basenames($a, $b);
}

function efpic_assign_image_sort_in_scene_by_basename(array &$meta, int $imageIndex): void
{
    if (!isset($meta['images'][$imageIndex]) || !is_array($meta['images'][$imageIndex])) {
        return;
    }
    $img = &$meta['images'][$imageIndex];
    unset($img['sort_manual']);
    $sid = (string) ($img['scene_id'] ?? 'main');
    $peers = [];
    foreach ($meta['images'] as $j => $other) {
        if ($j === $imageIndex || !is_array($other)) {
            continue;
        }
        if ((string) ($other['scene_id'] ?? 'main') === $sid) {
            $peers[] = $other;
        }
    }
    usort($peers, 'efpic_compare_images_in_scene');

    $insertSort = 10;
    foreach ($peers as $peer) {
        if (efpic_compare_image_basenames($img, $peer) > 0) {
            $insertSort = max($insertSort, (int) ($peer['sort'] ?? 0) + 10);
        }
    }
    $used = [];
    foreach ($peers as $peer) {
        $used[(int) ($peer['sort'] ?? 0)] = true;
    }
    while (isset($used[$insertSort])) {
        $insertSort++;
    }
    $img['sort'] = $insertSort;
}

function efpic_reconcile_auto_scene_sorts(array &$meta): void
{
    $byScene = [];
    foreach ($meta['images'] ?? [] as $i => $img) {
        if (!is_array($img)) {
            continue;
        }
        $sid = (string) ($img['scene_id'] ?? 'main');
        $byScene[$sid][] = $i;
    }
    foreach ($byScene as $indices) {
        $auto = [];
        foreach ($indices as $i) {
            if (!empty($meta['images'][$i]['sort_manual'])) {
                continue;
            }
            if ((int) ($meta['images'][$i]['sort'] ?? 0) === 0) {
                $auto[] = $i;
            }
        }
        usort($auto, static function ($ia, $ib) use ($meta) {
            return efpic_compare_image_basenames($meta['images'][$ia], $meta['images'][$ib]);
        });
        $sort = 10;
        foreach ($auto as $i) {
            $meta['images'][$i]['sort'] = $sort;
            unset($meta['images'][$i]['sort_manual']);
            $sort += 10;
        }
    }
}

function efpic_rebaseline_auto_scene_sorts(array &$meta): void
{
    $byScene = [];
    foreach ($meta['images'] ?? [] as $i => $img) {
        if (!is_array($img)) {
            continue;
        }
        $sid = (string) ($img['scene_id'] ?? 'main');
        $byScene[$sid][] = $i;
    }
    foreach ($byScene as $indices) {
        usort($indices, static function (int $ia, int $ib) use ($meta): int {
            return efpic_compare_image_basenames($meta['images'][$ia], $meta['images'][$ib]);
        });
        $sort = 10;
        foreach ($indices as $i) {
            unset($meta['images'][$i]['sort_manual']);
            $meta['images'][$i]['sort'] = $sort;
            $sort += 10;
        }
    }
}

/** Noņem vecos globālos sort laukus, ja nav manuālas kārtības. */
function efpic_normalize_gallery_image_sorts(array &$meta): void
{
    $hasManual = false;
    foreach ($meta['images'] ?? [] as $img) {
        if (is_array($img) && !empty($img['sort_manual'])) {
            $hasManual = true;
            break;
        }
    }
    if ($hasManual) {
        return;
    }
    foreach ($meta['images'] ?? [] as $i => $img) {
        if (!is_array($img)) {
            continue;
        }
        unset($meta['images'][$i]['sort'], $meta['images'][$i]['sort_manual']);
    }
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

function efpic_image_view_url(array $config, string $imageToken, ?string $guestToken = null): string
{
    $url = efpic_base_url($config) . '/v/i/' . rawurlencode($imageToken);
    if ($guestToken !== null && $guestToken !== '') {
        $url .= '?g=' . rawurlencode($guestToken);
    }

    return $url;
}

function efpic_image_download_url(array $config, string $imageToken, ?string $guestToken = null): string
{
    $url = efpic_base_url($config) . '/v/i/' . rawurlencode($imageToken) . '/download';
    if ($guestToken !== null && $guestToken !== '') {
        $url .= '?g=' . rawurlencode($guestToken);
    }

    return $url;
}

function efpic_media_proxy_url(array $config, string $imageToken, string $size = 'web', ?string $guestToken = null, int $width = 0): string
{
    $url = efpic_base_url($config) . '/v/media/' . rawurlencode($imageToken) . '?size=' . rawurlencode($size);
    if ($guestToken !== null && $guestToken !== '') {
        $url .= '&g=' . rawurlencode($guestToken);
    }
    if ($width > 0) {
        $url .= '&w=' . $width;
    }

    return $url;
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
    if (!efpic_gallery_is_active($meta)) {
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
    if ($meta === null || !efpic_gallery_is_active($meta)) {
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

/** @return array{role: string, guest_token: string, hide_client_hidden: bool, share_image_tokens: ?array<string, true>, share_label: string, share_include_videos: bool, access_denied?: bool} */
function efpic_viewer_context(array $config, array $meta): array
{
    $guestToken = trim((string) ($_GET['g'] ?? ''));
    $hideHidden = true;

    if ($guestToken !== '') {
        foreach ($meta['guests'] ?? [] as $g) {
            if (!is_array($g)) {
                continue;
            }
            $stored = (string) ($g['guest_token'] ?? '');
            if ($stored === '' || !hash_equals($stored, $guestToken)) {
                continue;
            }
            $whitelist = null;
            $rawTokens = $g['image_tokens'] ?? null;
            if (is_array($rawTokens) && $rawTokens !== []) {
                $whitelist = [];
                foreach ($rawTokens as $tok) {
                    $tok = (string) $tok;
                    if ($tok !== '') {
                        $whitelist[$tok] = true;
                    }
                }
                if ($whitelist === []) {
                    $whitelist = null;
                }
            }

            return [
                'role' => 'guest',
                'guest_token' => $guestToken,
                'hide_client_hidden' => true,
                'share_image_tokens' => $whitelist,
                'share_label' => (string) ($g['label'] ?? ''),
                'share_include_videos' => !empty($g['include_videos']),
            ];
        }

        return [
            'role' => 'denied',
            'guest_token' => $guestToken,
            'hide_client_hidden' => true,
            'share_image_tokens' => null,
            'share_label' => '',
            'share_include_videos' => false,
            'access_denied' => true,
        ];
    }

    $settings = efpic_gallery_settings($meta);
    if (empty($settings['hide_client_hidden_from_public'])) {
        $hideHidden = false;
    }

    return [
        'role' => 'public',
        'guest_token' => '',
        'hide_client_hidden' => $hideHidden,
        'share_image_tokens' => null,
        'share_label' => '',
        'share_include_videos' => false,
    ];
}

function efpic_viewer_context_access_denied(array $ctx): bool
{
    return !empty($ctx['access_denied']) || (($ctx['role'] ?? '') === 'denied');
}

function efpic_viewer_is_restricted_share(array $ctx): bool
{
    return is_array($ctx['share_image_tokens'] ?? null);
}

function efpic_viewer_include_videos_in_scenes(array $ctx): bool
{
    if (!efpic_viewer_is_restricted_share($ctx)) {
        return true;
    }

    return !empty($ctx['share_include_videos']);
}

function efpic_viewer_guest_token(array $ctx): string
{
    return (string) ($ctx['guest_token'] ?? '');
}

function efpic_viewer_zip_scope(array $ctx): string
{
    if (efpic_viewer_context_access_denied($ctx)) {
        return 'denied';
    }
    $guest = efpic_viewer_guest_token($ctx);
    if ($guest !== '') {
        return 'guest:' . $guest;
    }
    $whitelist = $ctx['share_image_tokens'] ?? null;
    if (is_array($whitelist)) {
        $keys = array_keys($whitelist);
        sort($keys);

        return 'list:' . hash('sha256', implode(',', $keys));
    }

    return 'public';
}

function efpic_gallery_password_satisfied(array $meta, string $galleryToken, ?string $singleEntryToken = null): bool
{
    if (efpic_admin_session_active()) {
        return true;
    }
    if (!efpic_gallery_has_password($meta)) {
        return true;
    }
    if (efpic_gallery_session_unlocked($galleryToken)) {
        return true;
    }
    efpic_client_session_start();
    $single = (string) ($_SESSION['efpic_single_entry'] ?? '');
    if ($single === '') {
        return false;
    }
    if ($singleEntryToken !== null && $singleEntryToken !== '') {
        return hash_equals($single, $singleEntryToken);
    }

    return true;
}

function efpic_find_image_row_by_token(array $meta, string $imageToken): ?array
{
    foreach ($meta['images'] ?? [] as $img) {
        if (is_array($img) && hash_equals((string) ($img['token'] ?? ''), $imageToken)) {
            return $img;
        }
    }

    return null;
}

function efpic_can_view_image_in_context(array $config, array $meta, string $imageToken, ?array $ctx = null): bool
{
    if ($ctx === null) {
        $ctx = efpic_viewer_context($config, $meta);
    }
    if (efpic_viewer_context_access_denied($ctx)) {
        return false;
    }
    $galleryToken = (string) ($meta['gallery_token'] ?? '');
    if (!efpic_gallery_password_satisfied($meta, $galleryToken, $imageToken)) {
        return false;
    }
    $img = efpic_find_image_row_by_token($meta, $imageToken);
    if ($img === null) {
        return false;
    }

    return efpic_image_visible_to_viewer($img, $meta, $ctx);
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
    $whitelist = $ctx['share_image_tokens'] ?? null;
    if (is_array($whitelist)) {
        $tok = (string) ($img['token'] ?? '');
        if ($tok === '' || !isset($whitelist[$tok])) {
            return false;
        }
    }

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

        $bySort = efpic_compare_images_in_scene($a, $b);
        if ($bySort !== 0) {
            return $bySort;
        }

        return 0;
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

    return efpic_theme_default_page_bg(efpic_gallery_effective_theme($meta));
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

/**
 * Publiskais attēla URL.
 * Ierobežotām kopīgošanas izlasēm — caur /v/media/ ar ?g= (servera whitelist).
 */
function efpic_client_media_url(array $config, array $img, string $size = 'web', int $width = 720, ?array $ctx = null): string
{
    $token = (string) ($img['token'] ?? '');
    $guest = efpic_viewer_guest_token($ctx ?? []);
    if (efpic_viewer_is_restricted_share($ctx ?? [])) {
        return efpic_media_proxy_url($config, $token, $size, $guest !== '' ? $guest : null, $width > 0 ? $width : 0);
    }

    $hash = efpic_delivery_file_hash($img, $size === 'full' ? 'full' : 'web');
    if ($hash !== '') {
        if ($size === 'full') {
            return efpic_failiem_download_url($config, $hash);
        }

        return efpic_failiem_thumb_url($config, $hash, $width);
    }

    return efpic_media_proxy_url($config, $token, $size, $guest !== '' ? $guest : null, $width > 0 ? $width : 0);
}

function efpic_client_media_url_for_token(
    array $config,
    array $meta,
    string $token,
    string $size = 'web',
    int $width = 720,
    ?array $ctx = null
): string {
    $img = efpic_find_image_row_by_token($meta, $token);
    if ($img !== null) {
        return efpic_client_media_url($config, $img, $size, $width, $ctx);
    }
    $guest = efpic_viewer_guest_token($ctx ?? []);

    return efpic_media_proxy_url($config, $token, $size, $guest !== '' ? $guest : null, $width > 0 ? $width : 0);
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

    if (in_array($size, $allowed, true)) {
        return true;
    }
    // «zip» nozīmē arī pilna izmēra (PRINT) lejupielādi delivery galerijās.
    if ($size === 'full' && (in_array('full', $allowed, true) || in_array('zip', $allowed, true))) {
        return true;
    }

    return false;
}

/** Vai drīkst lejupielādēt visas galerijas bildes ZIP (nevis tikai izlasi). */
function efpic_can_download_all_gallery_zip(array $meta, array $ctx, string $size): bool
{
    $size = strtolower($size);
    if ($size === 'both') {
        return efpic_can_download_all_gallery_zip($meta, $ctx, 'web')
            && efpic_can_download_all_gallery_zip($meta, $ctx, 'full');
    }
    if (!in_array($size, ['web', 'full'], true)) {
        return false;
    }
    if (!efpic_can_download_size($meta, $ctx, $size)) {
        return false;
    }
    $settings = efpic_gallery_settings($meta);
    if ($size === 'web' && !empty($settings['disable_public_download_all_web'])) {
        return false;
    }
    if ($size === 'full' && !empty($settings['disable_public_download_all_full'])) {
        return false;
    }

    return true;
}

function efpic_can_download_collection_zip(array $meta, array $ctx, string $size): bool
{
    $size = strtolower($size);
    if ($size === 'both') {
        return efpic_can_download_collection_zip($meta, $ctx, 'web')
            && efpic_can_download_collection_zip($meta, $ctx, 'full');
    }

    return in_array($size, ['web', 'full'], true) && efpic_can_download_size($meta, $ctx, $size);
}

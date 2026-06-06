<?php

declare(strict_types=1);

function efpic_gallery_assets_dir(array $config, string $slug): string
{
    return efpic_gallery_dir($config, $slug) . DIRECTORY_SEPARATOR . 'assets';
}

function efpic_ensure_gallery_assets_dir(array $config, string $slug): string
{
    $dir = efpic_gallery_assets_dir($config, $slug);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    return $dir;
}

function efpic_scene_element_id(string $sceneId): string
{
    $safe = preg_replace('/[^a-zA-Z0-9_-]+/', '', $sceneId);

    return 'scene-' . ($safe !== '' ? $safe : 'main');
}

/** @return list<array<string, mixed>> */
function efpic_gallery_sorted_scenes(array $meta): array
{
    $scenes = $meta['scenes'] ?? [];
    if (!is_array($scenes) || $scenes === []) {
        return [['id' => 'main', 'title' => 'Galerija', 'sort' => 1]];
    }
    usort($scenes, static fn ($a, $b) => ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0)));
    $out = [];
    foreach ($scenes as $scene) {
        if (!is_array($scene)) {
            continue;
        }
        $out[] = $scene;
    }

    return $out !== [] ? $out : [['id' => 'main', 'title' => 'Galerija', 'sort' => 1]];
}

/** @return array<string, list<array<string, mixed>>> */
function efpic_videos_grouped_by_scene(array $meta): array
{
    $byScene = [];
    $videos = $meta['videos'] ?? [];
    if (!is_array($videos)) {
        return $byScene;
    }
    usort($videos, static fn ($a, $b) => ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0)));
    foreach ($videos as $video) {
        if (!is_array($video)) {
            continue;
        }
        $sid = (string) ($video['scene_id'] ?? 'main');
        $byScene[$sid][] = $video;
    }

    return $byScene;
}

/**
 * Sadaļas, kurām ir vismaz viena bilde vai video (kārtotas pēc sort).
 *
 * @return list<array{id: string, title: string, sort: int}>
 */
function efpic_gallery_scenes_with_content(array $meta, array $images, bool $countVideos = true): array
{
    $bySceneImages = [];
    foreach ($images as $img) {
        if (!is_array($img)) {
            continue;
        }
        $sid = (string) ($img['scene_id'] ?? 'main');
        $bySceneImages[$sid] = true;
    }
    $bySceneVideos = [];
    if ($countVideos) {
        foreach ($meta['videos'] ?? [] as $video) {
            if (!is_array($video)) {
                continue;
            }
            $sid = (string) ($video['scene_id'] ?? 'main');
            $bySceneVideos[$sid] = true;
        }
    }

    $out = [];
    foreach (efpic_gallery_sorted_scenes($meta) as $scene) {
        $sid = (string) ($scene['id'] ?? 'main');
        if (!isset($bySceneImages[$sid]) && !isset($bySceneVideos[$sid])) {
            continue;
        }
        $out[] = [
            'id' => $sid,
            'title' => (string) ($scene['title'] ?? 'Galerija'),
            'sort' => (int) ($scene['sort'] ?? 0),
        ];
    }

    return $out;
}

/** @return list<array{id: string, title: string}> */
function efpic_gallery_scene_options(array $meta): array
{
    $scenes = $meta['scenes'] ?? [];
    if (!is_array($scenes) || $scenes === []) {
        return [['id' => 'main', 'title' => 'Galerija']];
    }
    usort($scenes, static fn ($a, $b) => ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0)));
    $out = [];
    foreach ($scenes as $scene) {
        if (!is_array($scene)) {
            continue;
        }
        $out[] = [
            'id' => (string) ($scene['id'] ?? 'main'),
            'title' => (string) ($scene['title'] ?? 'Galerija'),
        ];
    }

    return $out;
}

function efpic_gallery_slideshow_defaults(): array
{
    return [
        'enabled' => false,
        'audio_file' => '',
        'interval_sec' => 5,
        'intro_title' => '',
        'bg_mode' => 'white',
        'image_source' => 'favorites',
        'image_order_tokens' => [],
        'video_file' => '',
        'render_status' => 'none',
        'render_job_id' => '',
        'render_error' => '',
        'render_updated_at' => '',
    ];
}

function efpic_gallery_normalize_slideshow_slot(mixed $raw): array
{
    if (!is_array($raw)) {
        return efpic_gallery_slideshow_defaults();
    }
    $interval = (int) ($raw['interval_sec'] ?? 5);
    if ($interval < 2) {
        $interval = 2;
    }
    if ($interval > 60) {
        $interval = 60;
    }
    $intro = trim((string) ($raw['intro_title'] ?? ''));
    if (function_exists('mb_substr') && $intro !== '') {
        $intro = mb_substr($intro, 0, 120);
    } elseif (strlen($intro) > 120) {
        $intro = substr($intro, 0, 120);
    }
    $bg = (string) ($raw['bg_mode'] ?? 'white');
    if (!in_array($bg, ['white', 'gallery'], true)) {
        $bg = 'white';
    }
    $source = (string) ($raw['image_source'] ?? 'favorites');
    if (!in_array($source, ['favorites', 'all'], true)) {
        $source = 'favorites';
    }
    $order = [];
    if (is_array($raw['image_order_tokens'] ?? null)) {
        foreach ($raw['image_order_tokens'] as $tok) {
            $tok = (string) $tok;
            if (preg_match('/^[a-f0-9]{48}$/', $tok) === 1) {
                $order[] = $tok;
            }
        }
    }
    $video = (string) ($raw['video_file'] ?? '');
    if (preg_match('/^[a-zA-Z0-9._-]+$/', $video) !== 1) {
        $video = '';
    }
    $renderStatus = (string) ($raw['render_status'] ?? 'none');
    if (!in_array($renderStatus, ['none', 'queued', 'processing', 'ready', 'failed'], true)) {
        $renderStatus = 'none';
    }
    $jobId = (string) ($raw['render_job_id'] ?? '');
    if (preg_match('/^[a-f0-9]{32}$/', $jobId) !== 1) {
        $jobId = '';
    }

    return [
        'enabled' => !empty($raw['enabled']),
        'audio_file' => preg_match('/^[a-zA-Z0-9._-]+$/', (string) ($raw['audio_file'] ?? '')) === 1
            ? (string) $raw['audio_file'] : '',
        'interval_sec' => $interval,
        'intro_title' => $intro,
        'bg_mode' => $bg,
        'image_source' => $source,
        'image_order_tokens' => $order,
        'video_file' => $video,
        'render_status' => $renderStatus,
        'render_job_id' => $jobId,
        'render_error' => trim((string) ($raw['render_error'] ?? '')),
        'render_updated_at' => (string) ($raw['render_updated_at'] ?? ''),
    ];
}

/** @return array{admin: array, client: array} */
function efpic_gallery_slideshows_struct(array $meta): array
{
    $raw = $meta['slideshow'] ?? [];
    if (!is_array($raw)) {
        return [
            'admin' => efpic_gallery_slideshow_defaults(),
            'client' => efpic_gallery_slideshow_defaults(),
        ];
    }
    if (isset($raw['admin']) || isset($raw['client'])) {
        return [
            'admin' => efpic_gallery_normalize_slideshow_slot($raw['admin'] ?? null),
            'client' => efpic_gallery_normalize_slideshow_slot($raw['client'] ?? null),
        ];
    }

    return [
        'admin' => efpic_gallery_normalize_slideshow_slot($raw),
        'client' => efpic_gallery_slideshow_defaults(),
    ];
}

/** @deprecated Use efpic_gallery_slideshows_struct */
function efpic_gallery_normalize_slideshow(array $meta): array
{
    return efpic_gallery_slideshows_struct($meta)['admin'];
}

function efpic_apply_admin_favorites_from_post(array &$meta): void
{
    if (empty($_POST['favorites_dirty'])) {
        return;
    }
    $posted = $_POST['image_fav_admin'] ?? [];
    if (!is_array($posted)) {
        $posted = [];
    }
    foreach ($meta['images'] as $i => $img) {
        if (!is_array($img)) {
            continue;
        }
        $tok = (string) ($img['token'] ?? '');
        if ($tok === '') {
            continue;
        }
        $meta['images'][$i]['favorited_admin'] = isset($posted[$tok]);
    }
}

function efpic_slideshow_slot_interactive_ready(array $slot, int $favoriteCount): bool
{
    return $slot['enabled'] && $slot['audio_file'] !== '' && $favoriteCount > 0;
}

function efpic_slideshow_slot_video_ready(array $slot): bool
{
    $video = trim((string) ($slot['video_file'] ?? ''));
    if ($video === '') {
        return false;
    }

    return (string) ($slot['render_status'] ?? '') === 'ready';
}

function efpic_slideshow_slot_public_ready(array $slot, int $favoriteCount): bool
{
    return efpic_slideshow_slot_video_ready($slot) || efpic_slideshow_slot_interactive_ready($slot, $favoriteCount);
}

/** @deprecated Alias for interactive slideshow (MP3 + favorīti). */
function efpic_slideshow_slot_ready(array $slot, int $favoriteCount): bool
{
    return efpic_slideshow_slot_interactive_ready($slot, $favoriteCount);
}

/**
 * @return array{owner: string, mode: string, slideshow: array, images: list<array>}|null
 */
function efpic_try_resolve_public_slideshow_owner(
    array $meta,
    array $ctx,
    array $config,
    string $owner,
    array $slot,
    int $favoriteCount,
): ?array {
    if (empty($slot['enabled'])) {
        return null;
    }
    if (!efpic_slideshow_slot_interactive_ready($slot, $favoriteCount)) {
        return null;
    }
    $images = efpic_slideshow_favorite_images($meta, $ctx, $config, $owner);
    if ($images === []) {
        return null;
    }

    return [
        'owner' => $owner,
        'mode' => 'interactive',
        'slideshow' => $slot,
        'images' => $images,
    ];
}

/**
 * Public gallery slideshow: video first (client > admin), then interactive fallback.
 *
 * @return array{owner: string, mode: string, slideshow: array, images: list<array>}|null
 */
function efpic_resolve_public_slideshow(array $meta, array $ctx, array $config): ?array
{
    $slots = efpic_gallery_slideshows_struct($meta);
    foreach (['client', 'admin'] as $owner) {
        $slot = $slots[$owner];
        if (!$slot['enabled']) {
            continue;
        }
        if (efpic_slideshow_slot_video_ready($slot)) {
            return [
                'owner' => $owner,
                'mode' => 'video',
                'slideshow' => $slot,
                'images' => [],
            ];
        }
    }

    foreach (['client', 'admin'] as $owner) {
        $resolved = efpic_try_resolve_public_slideshow_owner(
            $meta,
            $ctx,
            $config,
            $owner,
            $slots[$owner],
            efpic_count_favorites($meta, $owner),
        );
        if ($resolved !== null) {
            return $resolved;
        }
    }

    return null;
}

/**
 * Publiskās galerijas MP4 slideshow sadaļas (fotogrāfs, tad klients).
 *
 * @return list<array{owner: string, slideshow: array, title: string}>
 */
function efpic_collect_public_slideshow_video_sections(array $meta, array $ctx, array $config): array
{
    $slots = efpic_gallery_slideshows_struct($meta);
    $out = [];
    foreach (['admin', 'client'] as $owner) {
        $slot = $slots[$owner];
        if (!$slot['enabled']) {
            continue;
        }
        if (!efpic_slideshow_slot_video_ready($slot)) {
            continue;
        }
        $title = trim((string) ($slot['intro_title'] ?? ''));
        if ($title === '') {
            $title = $owner === 'client' ? 'Klienta slideshow' : 'Slideshow';
        }
        $out[] = [
            'owner' => $owner,
            'slideshow' => $slot,
            'title' => $title,
        ];
    }

    return $out;
}

function efpic_gallery_asset_url(array $config, string $galleryToken, string $filename, ?string $guestToken = null): string
{
    $url = efpic_base_url($config) . '/v/g/' . rawurlencode($galleryToken) . '/asset/'
        . rawurlencode($filename);
    if ($guestToken !== null && $guestToken !== '') {
        $url .= '?g=' . rawurlencode($guestToken);
    }

    return $url;
}

function efpic_gallery_asset_registered(array $meta, string $filename): bool
{
    $slots = efpic_gallery_slideshows_struct($meta);
    foreach (['admin', 'client'] as $who) {
        if ($slots[$who]['audio_file'] === $filename || $slots[$who]['video_file'] === $filename) {
            return true;
        }
    }
    foreach ($meta['videos'] ?? [] as $video) {
        if (!is_array($video) || ($video['kind'] ?? '') !== 'file') {
            continue;
        }
        if ((string) ($video['file'] ?? '') === $filename) {
            return true;
        }
    }

    return false;
}

function efpic_store_gallery_upload(array $config, string $slug, array $file, array $allowedExt, int $maxBytes): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Augšupielādes kļūda');
    }
    if ((int) ($file['size'] ?? 0) > $maxBytes) {
        throw new InvalidArgumentException('Fails ir pārāk liels');
    }
    $orig = (string) ($file['name'] ?? 'file');
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        throw new InvalidArgumentException('Nederīgs faila tips');
    }
    $safe = efpic_random_hex(8) . '.' . $ext;
    $dir = efpic_ensure_gallery_assets_dir($config, $slug);
    $dest = $dir . DIRECTORY_SEPARATOR . $safe;
    if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
        throw new RuntimeException('Neizdevās saglabāt failu');
    }

    return $safe;
}

function efpic_delete_gallery_asset_file(array $config, string $slug, string $filename): void
{
    if (preg_match('/^[a-zA-Z0-9._-]+$/', $filename) !== 1) {
        return;
    }
    $path = efpic_gallery_assets_dir($config, $slug) . DIRECTORY_SEPARATOR . $filename;
    if (is_file($path)) {
        unlink($path);
    }
}

/** @return array{type: string, provider: string, embed_id: string}|null */
function efpic_parse_video_embed_url(string $url): ?array
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    if (preg_match('~(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)([a-zA-Z0-9_-]{6,20})~', $url, $m) === 1) {
        return ['type' => 'embed', 'provider' => 'youtube', 'embed_id' => $m[1]];
    }
    if (preg_match('~vimeo\.com/(?:video/)?(\d{6,12})~', $url, $m) === 1) {
        return ['type' => 'embed', 'provider' => 'vimeo', 'embed_id' => $m[1]];
    }

    return null;
}

/**
 * @param list<array<string, mixed>> $scenes
 * @return list<array<string, mixed>>
 */
function efpic_sanitize_gallery_scenes(array $scenes): array
{
    $out = [];
    $sort = 0;
    $hasMain = false;
    foreach ($scenes as $scene) {
        if (!is_array($scene)) {
            continue;
        }
        $id = trim((string) ($scene['id'] ?? ''));
        if ($id === '' || !preg_match('/^[a-z][a-z0-9_]{0,31}$/', $id)) {
            continue;
        }
        $title = trim((string) ($scene['title'] ?? ''));
        if ($title === '') {
            $title = $id === 'main' ? 'Galerija' : $id;
        }
        $sort++;
        $out[] = [
            'id' => $id,
            'title' => $title,
            'sort' => $sort,
            'header_image_token' => null,
            'hidden_from_guests' => !empty($scene['hidden_from_guests']),
        ];
        if ($id === 'main') {
            $hasMain = true;
        }
    }
    if (!$hasMain) {
        array_unshift($out, [
            'id' => 'main',
            'title' => 'Galerija',
            'sort' => 1,
            'header_image_token' => null,
            'hidden_from_guests' => false,
        ]);
        foreach ($out as $i => $row) {
            $out[$i]['sort'] = $i + 1;
        }
    }

    return $out;
}

/** @return list<array<string, mixed>> */
function efpic_parse_scenes_from_post(): array
{
    $raw = trim((string) ($_POST['scenes_json'] ?? ''));
    if ($raw === '') {
        $title = trim((string) ($_POST['scene_title'] ?? 'Galerija'));

        return efpic_sanitize_gallery_scenes([['id' => 'main', 'title' => $title !== '' ? $title : 'Galerija', 'sort' => 1]]);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('Nederīgas sadaļas');
    }

    return efpic_sanitize_gallery_scenes($decoded);
}

/** Portal: update titles, sort and hidden_from_guests only; preserve ids and count. */
function efpic_parse_portal_scenes_from_post(array $meta): array
{
    $existingById = [];
    foreach ($meta['scenes'] ?? [] as $scene) {
        if (!is_array($scene)) {
            continue;
        }
        $id = trim((string) ($scene['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $existingById[$id] = $scene;
    }
    if ($existingById === []) {
        $existingById['main'] = ['id' => 'main', 'title' => 'Galerija', 'sort' => 1, 'hidden_from_guests' => false];
    }

    $raw = trim((string) ($_POST['scenes_json'] ?? ''));
    if ($raw === '') {
        throw new InvalidArgumentException('Nav sadaļu datu');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('Nederīgas sadaļas');
    }

    $out = [];
    $sort = 0;
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '' || !isset($existingById[$id])) {
            continue;
        }
        $existing = $existingById[$id];
        $title = trim((string) ($row['title'] ?? ''));
        if ($title === '') {
            $title = trim((string) ($existing['title'] ?? ''));
        }
        if ($title === '') {
            $title = $id === 'main' ? 'Galerija' : $id;
        }
        $sort++;
        $out[] = [
            'id' => $id,
            'title' => $title,
            'sort' => $sort,
            'header_image_token' => $existing['header_image_token'] ?? null,
            'hidden_from_guests' => !empty($row['hidden_from_guests']),
        ];
    }

    foreach ($existingById as $id => $existing) {
        $found = false;
        foreach ($out as $scene) {
            if (($scene['id'] ?? '') === $id) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $sort++;
            $out[] = [
                'id' => $id,
                'title' => trim((string) ($existing['title'] ?? ($id === 'main' ? 'Galerija' : $id))),
                'sort' => $sort,
                'header_image_token' => $existing['header_image_token'] ?? null,
                'hidden_from_guests' => !empty($existing['hidden_from_guests']),
            ];
        }
    }

    return efpic_sanitize_gallery_scenes($out);
}

function efpic_reassign_orphan_scene_images(array &$meta): void
{
    $ids = [];
    foreach ($meta['scenes'] ?? [] as $scene) {
        if (is_array($scene)) {
            $ids[(string) ($scene['id'] ?? '')] = true;
        }
    }
    if (!isset($ids['main'])) {
        $ids['main'] = true;
    }
    foreach ($meta['images'] as $i => $img) {
        if (!is_array($img)) {
            continue;
        }
        $sid = (string) ($img['scene_id'] ?? 'main');
        if (!isset($ids[$sid])) {
            $meta['images'][$i]['scene_id'] = 'main';
        }
    }
    foreach ($meta['videos'] ?? [] as $vi => $video) {
        if (!is_array($video)) {
            continue;
        }
        $sid = (string) ($video['scene_id'] ?? 'main');
        if (!isset($ids[$sid])) {
            $meta['videos'][$vi]['scene_id'] = 'main';
        }
    }
}

function efpic_apply_image_scenes_from_post(array &$meta): void
{
    $sceneIds = [];
    foreach ($meta['scenes'] ?? [] as $scene) {
        if (is_array($scene)) {
            $sceneIds[(string) ($scene['id'] ?? '')] = true;
        }
    }
    $posted = $_POST['image_scene'] ?? [];
    if (!is_array($posted)) {
        return;
    }
    foreach ($meta['images'] as $i => $img) {
        if (!is_array($img)) {
            continue;
        }
        $tok = (string) ($img['token'] ?? '');
        if ($tok === '' || !isset($posted[$tok])) {
            continue;
        }
        $sid = (string) $posted[$tok];
        if ($sid !== '' && isset($sceneIds[$sid])) {
            $oldSid = (string) ($meta['images'][$i]['scene_id'] ?? 'main');
            if ($oldSid !== $sid) {
                $meta['images'][$i]['scene_id'] = $sid;
                if (!empty($meta['images'][$i]['sort_manual'])) {
                    efpic_assign_image_sort_in_scene_by_basename($meta, $i);
                } else {
                    unset($meta['images'][$i]['sort'], $meta['images'][$i]['sort_manual']);
                }
            } else {
                $meta['images'][$i]['scene_id'] = $sid;
            }
        }
    }
}

function efpic_apply_slideshow_from_post(array $config, string $slug, array &$meta, string $owner = 'admin'): void
{
    if (!in_array($owner, ['admin', 'client'], true)) {
        $owner = 'admin';
    }
    $slots = efpic_gallery_slideshows_struct($meta);
    $slideshow = $slots[$owner];
    $prefix = $owner === 'client' ? 'slideshow_client' : 'slideshow_admin';
    $enabledKey = $prefix . '_enabled';
    if ($owner === 'client') {
        if (array_key_exists($enabledKey, $_POST) || array_key_exists('slideshow_enabled', $_POST)) {
            $slideshow['enabled'] = !empty($_POST[$enabledKey]) || !empty($_POST['slideshow_enabled']);
        }
    } else {
        // Admin forma: neatzīmēts checkbox POSTā neparādās — traktējam kā izslēgtu.
        $slideshow['enabled'] = !empty($_POST[$enabledKey]);
    }
    $interval = (int) ($_POST[$prefix . '_interval'] ?? $_POST['slideshow_interval'] ?? $slideshow['interval_sec']);
    $slideshow['interval_sec'] = max(2, min(60, $interval));

    $removeKey = $prefix . '_remove_audio';
    $remove = !empty($_POST[$removeKey]);
    if ($owner === 'client' && !$remove) {
        $remove = !empty($_POST['remove_slideshow_audio']);
    }
    if ($owner === 'admin' && !$remove) {
        $remove = !empty($_POST['remove_slideshow_audio']);
    }
    if ($remove) {
        if ($slideshow['audio_file'] !== '') {
            efpic_delete_gallery_asset_file($config, $slug, $slideshow['audio_file']);
        }
        $slideshow['audio_file'] = '';
    }

    $fileKey = $prefix . '_mp3';
    if ($owner === 'client' && empty($_FILES[$fileKey])) {
        $fileKey = 'slideshow_mp3';
    }
    if (isset($_FILES[$fileKey]) && is_array($_FILES[$fileKey]) && ($_FILES[$fileKey]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $max = 25 * 1024 * 1024;
        $newFile = efpic_store_gallery_upload($config, $slug, $_FILES[$fileKey], ['mp3'], $max);
        if ($slideshow['audio_file'] !== '' && $slideshow['audio_file'] !== $newFile) {
            efpic_delete_gallery_asset_file($config, $slug, $slideshow['audio_file']);
        }
        $slideshow['audio_file'] = $newFile;
    }

    $introKey = $prefix . '_intro_title';
    if (array_key_exists($introKey, $_POST)) {
        $intro = trim((string) $_POST[$introKey]);
        if (function_exists('mb_substr')) {
            $intro = mb_substr($intro, 0, 120);
        } else {
            $intro = substr($intro, 0, 120);
        }
        $slideshow['intro_title'] = $intro;
    }

    $bgKey = $prefix . '_bg_mode';
    if (isset($_POST[$bgKey])) {
        $bg = (string) $_POST[$bgKey];
        $slideshow['bg_mode'] = in_array($bg, ['white', 'gallery'], true) ? $bg : 'white';
    }

    if ($owner === 'admin') {
        $slideshow['image_source'] = !empty($_POST[$prefix . '_image_source_all']) ? 'all' : 'favorites';

        if (!empty($_POST[$prefix . '_remove_video'])) {
            efpic_slideshow_clear_slot_video($config, $slug, $slideshow, $owner);
        }

        if (array_key_exists($prefix . '_image_order', $_POST)) {
            $orderRaw = trim((string) $_POST[$prefix . '_image_order']);
            $tokens = $orderRaw === '' ? [] : array_filter(array_map('trim', explode(',', $orderRaw)));
            $valid = [];
            foreach ($tokens as $tok) {
                if (preg_match('/^[a-f0-9]{48}$/', $tok) === 1) {
                    $valid[] = $tok;
                }
            }
            $slideshow['image_order_tokens'] = $valid;
        }
    }

    $slots[$owner] = $slideshow;
    $meta['slideshow'] = $slots;
}

function efpic_apply_videos_from_post(array $config, string $slug, array &$meta): void
{
    $videos = [];
    foreach ($meta['videos'] ?? [] as $video) {
        if (!is_array($video)) {
            continue;
        }
        $vid = (string) ($video['id'] ?? '');
        if ($vid !== '' && !empty($_POST['delete_video'][$vid])) {
            if (($video['kind'] ?? '') === 'file' && ($video['file'] ?? '') !== '') {
                efpic_delete_gallery_asset_file($config, $slug, (string) $video['file']);
            }
            continue;
        }
        $videos[] = $video;
    }

    $sceneIds = [];
    foreach ($meta['scenes'] ?? [] as $scene) {
        if (is_array($scene)) {
            $sceneIds[(string) ($scene['id'] ?? '')] = true;
        }
    }
    $defaultScene = isset($sceneIds['main']) ? 'main' : (string) array_key_first($sceneIds);

    if (isset($_FILES['gallery_video']) && is_array($_FILES['gallery_video']) && ($_FILES['gallery_video']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $max = 500 * 1024 * 1024;
        $file = efpic_store_gallery_upload($config, $slug, $_FILES['gallery_video'], ['mp4', 'mov', 'webm'], $max);
        $sceneId = (string) ($_POST['video_upload_scene'] ?? $defaultScene);
        if (!isset($sceneIds[$sceneId])) {
            $sceneId = $defaultScene;
        }
        $videos[] = [
            'id' => 'v_' . efpic_random_hex(6),
            'kind' => 'file',
            'file' => $file,
            'title' => trim((string) ($_POST['video_upload_title'] ?? '')),
            'scene_id' => $sceneId,
            'sort' => count($videos) + 1,
        ];
    }

    $postedVideoScenes = $_POST['video_scene'] ?? [];
    $postedVideoTitles = $_POST['video_title'] ?? [];
    if (is_array($postedVideoScenes) || is_array($postedVideoTitles)) {
        foreach ($videos as $vi => $video) {
            $vid = (string) ($video['id'] ?? '');
            if ($vid === '') {
                continue;
            }
            if (is_array($postedVideoScenes) && isset($postedVideoScenes[$vid])) {
                $sid = (string) $postedVideoScenes[$vid];
                if (isset($sceneIds[$sid])) {
                    $videos[$vi]['scene_id'] = $sid;
                }
            }
            if (is_array($postedVideoTitles) && isset($postedVideoTitles[$vid])) {
                $videos[$vi]['title'] = trim((string) $postedVideoTitles[$vid]);
            }
        }
    }

    $embedUrl = trim((string) ($_POST['video_embed_url'] ?? ''));
    $allowNewEmbed = $embedUrl !== ''
        && (empty($_POST['autosave']) || !empty($_POST['add_video_embed']));
    if ($allowNewEmbed) {
        $parsed = efpic_parse_video_embed_url($embedUrl);
        if ($parsed === null) {
            throw new InvalidArgumentException('Neatpazīts YouTube vai Vimeo links');
        }
        $sceneId = (string) ($_POST['video_embed_scene'] ?? $defaultScene);
        if (!isset($sceneIds[$sceneId])) {
            $sceneId = $defaultScene;
        }
        $videos[] = [
            'id' => 'v_' . efpic_random_hex(6),
            'kind' => 'embed',
            'provider' => $parsed['provider'],
            'embed_id' => $parsed['embed_id'],
            'source_url' => $embedUrl,
            'title' => trim((string) ($_POST['video_embed_title'] ?? '')),
            'scene_id' => $sceneId,
            'sort' => count($videos) + 1,
        ];
    }

    usort($videos, static fn ($a, $b) => ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0)));
    $sort = 0;
    foreach ($videos as $i => $video) {
        $sort++;
        $videos[$i]['sort'] = $sort;
    }

    $meta['videos'] = $videos;
}

/** @return list<array<string, mixed>> */
function efpic_slideshow_favorite_images(array $meta, array $ctx, array $config, string $who = 'client'): array
{
    $out = [];
    foreach (efpic_sort_images_for_display($meta) as $img) {
        if (!is_array($img)) {
            continue;
        }
        $isFav = $who === 'admin' ? efpic_image_favorited_admin($img) : efpic_image_favorited_client($img);
        if (!$isFav) {
            continue;
        }
        if (!efpic_image_visible_to_viewer($img, $meta, $ctx)) {
            continue;
        }
        $tok = (string) ($img['token'] ?? '');
        if ($tok === '' || !efpic_gallery_password_satisfied($meta, (string) ($meta['gallery_token'] ?? ''), $tok)) {
            continue;
        }
        $out[] = $img;
    }

    return $out;
}

function efpic_can_view_gallery_asset(array $config, array $meta, string $galleryToken, array $ctx, string $filename): bool
{
    if (efpic_admin_session_active()) {
        return true;
    }
    if (efpic_viewer_context_access_denied($ctx)) {
        return false;
    }
    if (!efpic_gallery_password_satisfied($meta, $galleryToken)) {
        return false;
    }

    $whitelist = $ctx['share_image_tokens'] ?? null;
    if (!is_array($whitelist)) {
        return true;
    }

    $slots = efpic_gallery_slideshows_struct($meta);
    foreach (['admin', 'client'] as $who) {
        if ($slots[$who]['audio_file'] === $filename || $slots[$who]['video_file'] === $filename) {
            return efpic_resolve_public_slideshow($meta, $ctx, $config) !== null;
        }
    }

    if (empty($ctx['share_include_videos'])) {
        return false;
    }

    foreach ($meta['videos'] ?? [] as $video) {
        if (!is_array($video) || ($video['kind'] ?? '') !== 'file') {
            continue;
        }
        if ((string) ($video['file'] ?? '') !== $filename) {
            continue;
        }
        $sceneId = (string) ($video['scene_id'] ?? 'main');

        return efpic_share_scene_has_whitelisted_images($meta, $sceneId, $whitelist);
    }

    return false;
}

function efpic_share_scene_has_whitelisted_images(array $meta, string $sceneId, array $whitelist): bool
{
    foreach ($meta['images'] ?? [] as $img) {
        if (!is_array($img)) {
            continue;
        }
        $tok = (string) ($img['token'] ?? '');
        if ($tok !== '' && isset($whitelist[$tok]) && (string) ($img['scene_id'] ?? 'main') === $sceneId) {
            return true;
        }
    }

    return false;
}

/** @deprecated Izmanto efpic_can_view_gallery_asset ar viewer kontekstu */
function efpic_can_view_gallery_asset_legacy(array $meta, string $galleryToken): bool
{
    if (efpic_admin_session_active()) {
        return true;
    }
    if (!efpic_gallery_has_password($meta)) {
        return true;
    }

    return efpic_gallery_session_unlocked($galleryToken);
}

function efpic_handle_gallery_asset(array $config, string $galleryToken, string $filename): void
{
    $found = efpic_find_gallery_by_token($config, $galleryToken);
    if ($found === null) {
        http_response_code(404);
        exit;
    }
    $meta = $found['meta'];
    $ctx = efpic_viewer_context($config, $meta);
    if (!efpic_can_view_gallery_asset($config, $meta, $galleryToken, $ctx, $filename)) {
        http_response_code(403);
        exit;
    }
    if (preg_match('/^[a-zA-Z0-9._-]+$/', $filename) !== 1 || !efpic_gallery_asset_registered($meta, $filename)) {
        http_response_code(404);
        exit;
    }
    $path = efpic_gallery_assets_dir($config, $found['slug']) . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $types = [
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
        'mov' => 'video/quicktime',
        'webm' => 'video/webm',
    ];
    $contentType = $types[$ext] ?? 'application/octet-stream';
    efpic_stream_local_file($path, $contentType);
}

/** @return array{guest_token: string, label: string, image_tokens: list<string>} */
function efpic_create_share_set(
    array &$meta,
    string $label,
    array $imageTokens,
    string $createdBy = 'admin',
    bool $includeVideos = false
): array
{
    $index = [];
    foreach ($meta['images'] ?? [] as $img) {
        if (is_array($img) && ($img['token'] ?? '') !== '') {
            $index[(string) $img['token']] = true;
        }
    }
    $valid = [];
    foreach ($imageTokens as $tok) {
        $tok = trim((string) $tok);
        if ($tok !== '' && isset($index[$tok])) {
            $valid[$tok] = true;
        }
    }
    $valid = array_keys($valid);
    if ($valid === []) {
        throw new InvalidArgumentException('Izvēlies vismaz vienu derīgu bildi izlasei.');
    }

    $guests = $meta['guests'] ?? [];
    if (!is_array($guests)) {
        $guests = [];
    }
    $entry = [
        'guest_token' => efpic_random_hex(16),
        'label' => $label !== '' ? $label : 'Izlase',
        'image_tokens' => $valid,
        'include_videos' => $includeVideos,
        'created_at' => gmdate('c'),
        'created_by' => $createdBy,
    ];
    $guests[] = $entry;
    $meta['guests'] = $guests;

    return $entry;
}

function efpic_delete_share_set(array &$meta, string $guestToken): void
{
    $guestToken = trim($guestToken);
    if ($guestToken === '') {
        return;
    }
    $guests = $meta['guests'] ?? [];
    if (!is_array($guests)) {
        return;
    }
    $meta['guests'] = array_values(array_filter($guests, static function ($g) use ($guestToken) {
        return !is_array($g) || (string) ($g['guest_token'] ?? '') !== $guestToken;
    }));
}

/** @return array<string, true> */
function efpic_share_sets_token_index(array $meta): array
{
    $index = [];
    foreach ($meta['guests'] ?? [] as $guest) {
        if (!is_array($guest)) {
            continue;
        }
        foreach ($guest['image_tokens'] ?? [] as $tok) {
            $tok = (string) $tok;
            if ($tok !== '') {
                $index[$tok] = true;
            }
        }
    }

    return $index;
}

/** @return array<string, int> */
function efpic_share_sets_count_index(array $meta): array
{
    $index = [];
    foreach ($meta['guests'] ?? [] as $guest) {
        if (!is_array($guest)) {
            continue;
        }
        $seen = [];
        foreach ($guest['image_tokens'] ?? [] as $tok) {
            $tok = (string) $tok;
            if ($tok === '' || isset($seen[$tok])) {
                continue;
            }
            $seen[$tok] = true;
            $index[$tok] = ($index[$tok] ?? 0) + 1;
        }
    }

    return $index;
}

function efpic_replace_share_set_images(array &$meta, string $guestToken, array $imageTokens): void
{
    $guestToken = trim($guestToken);
    if ($guestToken === '') {
        throw new InvalidArgumentException('Nav izvēlēta izlase.');
    }
    $index = [];
    foreach ($meta['images'] ?? [] as $img) {
        if (is_array($img) && ($img['token'] ?? '') !== '') {
            $index[(string) $img['token']] = true;
        }
    }
    $valid = [];
    foreach ($imageTokens as $tok) {
        $tok = trim((string) $tok);
        if ($tok !== '' && isset($index[$tok])) {
            $valid[$tok] = true;
        }
    }
    $valid = array_keys($valid);
    if ($valid === []) {
        throw new InvalidArgumentException('Izvēlies vismaz vienu derīgu bildi izlasei.');
    }

    $guests = $meta['guests'] ?? [];
    if (!is_array($guests)) {
        throw new InvalidArgumentException('Izlase nav atrasta.');
    }
    $found = false;
    foreach ($guests as $gi => $guest) {
        if (!is_array($guest) || (string) ($guest['guest_token'] ?? '') !== $guestToken) {
            continue;
        }
        $guests[$gi]['image_tokens'] = $valid;
        $found = true;
        break;
    }
    if (!$found) {
        throw new InvalidArgumentException('Izlase nav atrasta.');
    }
    $meta['guests'] = $guests;
}

function efpic_update_share_set_label(array &$meta, string $guestToken, string $label): void
{
    $guestToken = trim($guestToken);
    $label = trim($label);
    if ($label === '') {
        throw new InvalidArgumentException('Ievadi izlases nosaukumu.');
    }
    foreach ($meta['guests'] ?? [] as $gi => $guest) {
        if (!is_array($guest) || (string) ($guest['guest_token'] ?? '') !== $guestToken) {
            continue;
        }
        $meta['guests'][$gi]['label'] = $label;

        return;
    }
    throw new InvalidArgumentException('Izlase nav atrasta.');
}

function efpic_append_to_share_set(array &$meta, string $guestToken, array $imageTokens): void
{
    $guestToken = trim($guestToken);
    if ($guestToken === '') {
        throw new InvalidArgumentException('Nav izvēlēta izlase.');
    }
    $index = [];
    foreach ($meta['images'] ?? [] as $img) {
        if (is_array($img) && ($img['token'] ?? '') !== '') {
            $index[(string) $img['token']] = true;
        }
    }
    $add = [];
    foreach ($imageTokens as $tok) {
        $tok = trim((string) $tok);
        if ($tok !== '' && isset($index[$tok])) {
            $add[$tok] = true;
        }
    }
    if ($add === []) {
        throw new InvalidArgumentException('Izvēlies vismaz vienu derīgu bildi.');
    }

    $guests = $meta['guests'] ?? [];
    if (!is_array($guests)) {
        throw new InvalidArgumentException('Izlase nav atrasta.');
    }
    $found = false;
    foreach ($guests as $gi => $guest) {
        if (!is_array($guest) || (string) ($guest['guest_token'] ?? '') !== $guestToken) {
            continue;
        }
        $existing = [];
        foreach ($guest['image_tokens'] ?? [] as $tok) {
            $tok = (string) $tok;
            if ($tok !== '') {
                $existing[$tok] = true;
            }
        }
        foreach (array_keys($add) as $tok) {
            $existing[$tok] = true;
        }
        $guests[$gi]['image_tokens'] = array_keys($existing);
        $found = true;
        break;
    }
    if (!$found) {
        throw new InvalidArgumentException('Izlase nav atrasta.');
    }
    $meta['guests'] = $guests;
}

function efpic_update_share_set_meta(array &$meta, string $guestToken, bool $includeVideos): void
{
    $guestToken = trim($guestToken);
    foreach ($meta['guests'] ?? [] as $gi => $guest) {
        if (!is_array($guest) || (string) ($guest['guest_token'] ?? '') !== $guestToken) {
            continue;
        }
        $meta['guests'][$gi]['include_videos'] = $includeVideos;

        return;
    }
    throw new InvalidArgumentException('Izlase nav atrasta.');
}

function efpic_apply_share_actions_from_post(array &$meta, string $createdBy = 'admin'): void
{
    $action = trim((string) ($_POST['share_action'] ?? ''));
    if ($action === 'create') {
        $label = trim((string) ($_POST['share_set_label'] ?? ''));
        $raw = trim((string) ($_POST['share_set_tokens'] ?? ''));
        $tokens = array_values(array_filter(array_map('trim', explode(',', $raw))));
        efpic_create_share_set($meta, $label, $tokens, $createdBy, !empty($_POST['share_include_videos']));

        return;
    }
    if ($action === 'append') {
        $guestToken = trim((string) ($_POST['share_guest_token'] ?? ''));
        $raw = trim((string) ($_POST['share_set_tokens'] ?? ''));
        $tokens = array_values(array_filter(array_map('trim', explode(',', $raw))));
        efpic_append_to_share_set($meta, $guestToken, $tokens);

        return;
    }
    if ($action === 'replace') {
        $guestToken = trim((string) ($_POST['share_guest_token'] ?? ''));
        $raw = trim((string) ($_POST['share_set_tokens'] ?? ''));
        $tokens = array_values(array_filter(array_map('trim', explode(',', $raw))));
        efpic_replace_share_set_images($meta, $guestToken, $tokens);
        $label = trim((string) ($_POST['share_set_label'] ?? ''));
        if ($label !== '') {
            efpic_update_share_set_label($meta, $guestToken, $label);
        }

        return;
    }
    if ($action === 'update_videos') {
        $guestToken = trim((string) ($_POST['share_guest_token'] ?? ''));
        efpic_update_share_set_meta($meta, $guestToken, !empty($_POST['share_include_videos']));
    }
}

/** @deprecated Use efpic_apply_share_actions_from_post() */
function efpic_admin_apply_share_actions_from_post(array &$meta): void
{
    efpic_apply_share_actions_from_post($meta, 'admin');
}

function efpic_share_set_image_count(array $guest): int
{
    if (!is_array($guest)) {
        return 0;
    }
    $tokens = $guest['image_tokens'] ?? null;
    if (!is_array($tokens) || $tokens === []) {
        return 0;
    }

    return count($tokens);
}

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

/**
 * Sadaļas, kurām ir vismaz viena bilde vai video (kārtotas pēc sort).
 *
 * @return list<array{id: string, title: string, sort: int}>
 */
function efpic_gallery_scenes_with_content(array $meta, array $images): array
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
    foreach ($meta['videos'] ?? [] as $video) {
        if (!is_array($video)) {
            continue;
        }
        $sid = (string) ($video['scene_id'] ?? 'main');
        $bySceneVideos[$sid] = true;
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

    return [
        'enabled' => !empty($raw['enabled']),
        'audio_file' => preg_match('/^[a-zA-Z0-9._-]+$/', (string) ($raw['audio_file'] ?? '')) === 1
            ? (string) $raw['audio_file'] : '',
        'interval_sec' => $interval,
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

function efpic_slideshow_slot_ready(array $slot, int $favoriteCount): bool
{
    return $slot['enabled'] && $slot['audio_file'] !== '' && $favoriteCount > 0;
}

/**
 * Public gallery slideshow: client wins when their slideshow is ready.
 *
 * @return array{owner: string, slideshow: array, images: list<array>}|null
 */
function efpic_resolve_public_slideshow(array $meta, array $ctx, array $config): ?array
{
    $slots = efpic_gallery_slideshows_struct($meta);
    $clientCount = efpic_count_favorites($meta, 'client');
    $adminCount = efpic_count_favorites($meta, 'admin');

    if (efpic_slideshow_slot_ready($slots['client'], $clientCount)) {
        $images = efpic_slideshow_favorite_images($meta, $ctx, $config, 'client');

        return ['owner' => 'client', 'slideshow' => $slots['client'], 'images' => $images];
    }

    if (efpic_slideshow_slot_ready($slots['admin'], $adminCount)) {
        $images = efpic_slideshow_favorite_images($meta, $ctx, $config, 'admin');

        return ['owner' => 'admin', 'slideshow' => $slots['admin'], 'images' => $images];
    }

    return null;
}

function efpic_gallery_asset_url(array $config, string $galleryToken, string $filename): string
{
    return efpic_base_url($config) . '/v/g/' . rawurlencode($galleryToken) . '/asset/'
        . rawurlencode($filename);
}

function efpic_gallery_asset_registered(array $meta, string $filename): bool
{
    $slots = efpic_gallery_slideshows_struct($meta);
    foreach (['admin', 'client'] as $who) {
        if ($slots[$who]['audio_file'] === $filename) {
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
            $meta['images'][$i]['scene_id'] = $sid;
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

    $slideshow['enabled'] = !empty($_POST[$prefix . '_enabled']) || ($owner === 'client' && !empty($_POST['slideshow_enabled']));
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

    $embedUrl = trim((string) ($_POST['video_embed_url'] ?? ''));
    if ($embedUrl !== '') {
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
        if ($tok === '' || !efpic_can_view_image_file($meta, $tok)) {
            continue;
        }
        $out[] = $img;
    }

    return $out;
}

function efpic_can_view_gallery_asset(array $meta, string $galleryToken): bool
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
    if (!efpic_can_view_gallery_asset($meta, $galleryToken)) {
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
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Cache-Control: private, max-age=86400');
    readfile($path);
    exit;
}

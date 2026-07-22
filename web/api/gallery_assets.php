<?php

declare(strict_types=1);

function efpic_gallery_assets_dir(array $config, string $slug): string
{
    return efpic_gallery_dir($config, $slug) . DIRECTORY_SEPARATOR . 'assets';
}

function efpic_post_flag_is_on(string $key): bool
{
    if (!array_key_exists($key, $_POST)) {
        return false;
    }
    $value = $_POST[$key];
    if (is_array($value)) {
        foreach ($value as $part) {
            if ((string) $part === '1') {
                return true;
            }
        }

        return false;
    }

    return (string) $value === '1';
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

/** @return list<array<string, mixed>> */
function efpic_gallery_failiem_videos(array $meta): array
{
    $out = [];
    foreach ($meta['videos'] ?? [] as $video) {
        if (!is_array($video) || ($video['kind'] ?? '') !== 'failiem') {
            continue;
        }
        $out[] = $video;
    }

    return $out;
}

function efpic_delivery_video_hash(array $video): string
{
    if (($video['kind'] ?? '') !== 'failiem') {
        return '';
    }

    return (string) ($video['failiem']['file_hash'] ?? '');
}

function efpic_ensure_gallery_video_scene(array &$meta): void
{
    $scenes = $meta['scenes'] ?? [];
    if (!is_array($scenes)) {
        $scenes = [];
    }
    foreach ($scenes as $scene) {
        if (is_array($scene) && (string) ($scene['id'] ?? '') === 'video') {
            return;
        }
    }
    $maxSort = 0;
    foreach ($scenes as $scene) {
        if (!is_array($scene)) {
            continue;
        }
        $maxSort = max($maxSort, (int) ($scene['sort'] ?? 0));
    }
    $scenes[] = [
        'id' => 'video',
        'title' => 'Video',
        'sort' => $maxSort + 1,
        'header_image_token' => null,
        'hidden_from_guests' => false,
    ];
    $meta['scenes'] = efpic_sanitize_gallery_scenes($scenes);
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
        'id' => '',
        'title' => '',
        'enabled' => false,
        'audio_file' => '',
        'audio_files' => [],
        'interval_sec' => 5,
        'intro_title' => '',
        'section_title' => '',
        'section_placement' => 'top',
        'section_after_scene' => '',
        'section_order' => 0,
        'bg_mode' => 'white',
        'image_source' => 'favorites',
        'image_scene_ids' => [],
        'image_order_tokens' => [],
        'video_file' => '',
        'render_status' => 'none',
        'render_job_id' => '',
        'render_error' => '',
        'render_updated_at' => '',
        'render_fingerprint' => '',
    ];
}

function efpic_gallery_slideshow_item_id_valid(string $id): bool
{
    return preg_match('/^[a-f0-9]{8}$/', $id) === 1;
}

/** @return array<string, mixed> */
function efpic_gallery_new_slideshow_item(int $number = 1): array
{
    $item = efpic_gallery_slideshow_defaults();
    $item['id'] = efpic_random_hex(8);
    $item['title'] = 'Slideshow ' . max(1, $number);

    return $item;
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
    if (!in_array($source, ['favorites', 'all', 'scenes'], true)) {
        $source = 'favorites';
    }
    $sceneIds = [];
    if (is_array($raw['image_scene_ids'] ?? null)) {
        foreach ($raw['image_scene_ids'] as $sid) {
            $sid = (string) $sid;
            if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $sid) === 1) {
                $sceneIds[] = $sid;
            }
        }
    }
    $sceneIds = array_values(array_unique($sceneIds));
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
    if ($video !== '' && $renderStatus === 'none') {
        $renderStatus = 'ready';
    }
    $jobId = (string) ($raw['render_job_id'] ?? '');
    if (preg_match('/^[a-f0-9]{32}$/', $jobId) !== 1) {
        $jobId = '';
    }

    $audioFiles = [];
    if (is_array($raw['audio_files'] ?? null)) {
        foreach ($raw['audio_files'] as $file) {
            $file = (string) $file;
            if (preg_match('/^[a-zA-Z0-9._-]+$/', $file) === 1) {
                $audioFiles[] = $file;
            }
        }
    }
    $legacyAudio = (string) ($raw['audio_file'] ?? '');
    if ($audioFiles === [] && preg_match('/^[a-zA-Z0-9._-]+$/', $legacyAudio) === 1) {
        $audioFiles[] = $legacyAudio;
    }
    $audioFiles = array_values(array_unique($audioFiles));

    $sectionTitle = trim((string) ($raw['section_title'] ?? ''));
    if (function_exists('mb_substr') && $sectionTitle !== '') {
        $sectionTitle = mb_substr($sectionTitle, 0, 80);
    } elseif (strlen($sectionTitle) > 80) {
        $sectionTitle = substr($sectionTitle, 0, 80);
    }
    $placement = (string) ($raw['section_placement'] ?? 'top');
    if ($placement === 'after_scene') {
        $placement = 'before_scene';
    }
    if (!in_array($placement, ['top', 'bottom', 'before_scene'], true)) {
        $placement = 'top';
    }
    $afterScene = (string) ($raw['section_after_scene'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $afterScene)) {
        $afterScene = '';
    }
    if ($placement === 'before_scene' && $afterScene === '') {
        $placement = 'top';
    }
    $sectionOrder = (int) ($raw['section_order'] ?? 0);
    if ($sectionOrder < 0) {
        $sectionOrder = 0;
    }
    if ($sectionOrder > 999) {
        $sectionOrder = 999;
    }

    $id = trim((string) ($raw['id'] ?? ''));
    if (!efpic_gallery_slideshow_item_id_valid($id)) {
        $id = '';
    }
    $title = trim((string) ($raw['title'] ?? ''));
    if (function_exists('mb_substr') && $title !== '') {
        $title = mb_substr($title, 0, 80);
    } elseif (strlen($title) > 80) {
        $title = substr($title, 0, 80);
    }

    if (array_key_exists('enabled', $raw)) {
        $enabled = !empty($raw['enabled']);
    } elseif ($video !== '' && in_array($renderStatus, ['ready', 'none'], true)) {
        $enabled = true;
    } else {
        $enabled = false;
    }

    return [
        'id' => $id,
        'title' => $title,
        'enabled' => $enabled,
        'audio_file' => $audioFiles[0] ?? '',
        'audio_files' => $audioFiles,
        'interval_sec' => $interval,
        'intro_title' => $intro,
        'section_title' => $sectionTitle,
        'section_placement' => $placement,
        'section_after_scene' => $afterScene,
        'section_order' => $sectionOrder,
        'bg_mode' => $bg,
        'image_source' => $source,
        'image_scene_ids' => $sceneIds,
        'image_order_tokens' => $order,
        'video_file' => $video,
        'render_status' => $renderStatus,
        'render_job_id' => $jobId,
        'render_error' => trim((string) ($raw['render_error'] ?? '')),
        'render_updated_at' => (string) ($raw['render_updated_at'] ?? ''),
        'render_fingerprint' => (string) ($raw['render_fingerprint'] ?? ''),
    ];
}

/** @return array<string, mixed> */
function efpic_gallery_normalize_slideshow_item(mixed $raw, int $fallbackNumber = 1): array
{
    $item = efpic_gallery_normalize_slideshow_slot($raw);
    $item = efpic_gallery_ensure_slideshow_item_id($item);
    if ($item['title'] === '') {
        $item['title'] = 'Slideshow ' . max(1, $fallbackNumber);
    }

    return $item;
}

function efpic_slideshow_item_is_published(array $item): bool
{
    if (efpic_slideshow_slot_video_ready($item)) {
        return true;
    }
    $status = (string) ($item['render_status'] ?? 'none');
    if (in_array($status, ['queued', 'processing', 'failed'], true)) {
        return true;
    }
    $id = (string) ($item['id'] ?? '');

    return efpic_gallery_slideshow_item_id_valid($id);
}

/** Vai vecais slideshow.admin vēl jāpievieno items[] (pēc reorganizācijas). */
function efpic_slideshow_legacy_admin_should_migrate(array $adminRaw, array $sourceItems): bool
{
    $adminVideo = trim((string) ($adminRaw['video_file'] ?? ''));
    $adminId = trim((string) ($adminRaw['id'] ?? ''));
    foreach ($sourceItems as $raw) {
        if (!is_array($raw)) {
            continue;
        }
        if ($adminId !== '' && (string) ($raw['id'] ?? '') === $adminId) {
            return false;
        }
        if ($adminVideo !== '' && trim((string) ($raw['video_file'] ?? '')) === $adminVideo) {
            return false;
        }
    }
    if ($adminVideo !== '') {
        return true;
    }
    if (!empty($adminRaw['enabled'])) {
        return true;
    }
    $status = (string) ($adminRaw['render_status'] ?? 'none');

    return in_array($status, ['queued', 'processing', 'ready', 'failed'], true);
}

/** Stabils ID veciem ierakstiem bez id — lai GET un POST nesadalītu atšķirīgus ID. */
function efpic_gallery_stable_slideshow_item_id(array $raw): string
{
    $id = trim((string) ($raw['id'] ?? ''));
    if (efpic_gallery_slideshow_item_id_valid($id)) {
        return $id;
    }
    $video = trim((string) ($raw['video_file'] ?? ''));
    if ($video !== '') {
        return substr(md5($video), 0, 8);
    }
    $jobId = trim((string) ($raw['render_job_id'] ?? ''));
    if ($jobId !== '') {
        return substr(md5($jobId), 0, 8);
    }
    $title = trim((string) ($raw['title'] ?? ''));
    if ($title !== '') {
        return substr(md5($title), 0, 8);
    }

    return efpic_random_hex(8);
}

/** @param array<string, mixed> $item */
function efpic_gallery_ensure_slideshow_item_id(array $item): array
{
    $id = (string) ($item['id'] ?? '');
    if (!efpic_gallery_slideshow_item_id_valid($id)) {
        $item['id'] = efpic_gallery_stable_slideshow_item_id($item);
    }

    return $item;
}

/** Normalizē slideshow struktūru meta.json un saglabā, ja mainījās (admin → items, stabili id). */
function efpic_gallery_migrate_slideshow_meta_in_place(array &$meta): bool
{
    $before = json_encode($meta['slideshow'] ?? null);
    $storage = efpic_gallery_slideshow_storage($meta);
    efpic_gallery_persist_slideshow_storage($meta, $storage);

    return json_encode($meta['slideshow'] ?? null) !== $before;
}

function efpic_gallery_slideshow_draft_is_empty(array $draft): bool
{
    return efpic_slideshow_slot_audio_files($draft) === []
        && trim((string) ($draft['intro_title'] ?? '')) === ''
        && trim((string) ($draft['title'] ?? '')) === ''
        && ($draft['image_source'] ?? 'favorites') === 'favorites'
        && ($draft['image_scene_ids'] ?? []) === [];
}

/** @return array<string, mixed> */
function efpic_gallery_new_slideshow_item_from_draft(array $draft): array
{
    $item = efpic_gallery_normalize_slideshow_item($draft, 1);
    $item['id'] = efpic_random_hex(8);
    $item['enabled'] = true;
    $item['video_file'] = '';
    $item['render_status'] = 'none';
    $item['render_job_id'] = '';
    $item['render_error'] = '';
    $item['render_updated_at'] = '';
    $item['render_fingerprint'] = '';

    return $item;
}

/**
 * @return array{draft: array<string, mixed>, items: list<array<string, mixed>>, client: array<string, mixed>}
 */
function efpic_gallery_slideshow_storage(array $meta): array
{
    $raw = $meta['slideshow'] ?? [];
    if (!is_array($raw)) {
        return [
            'draft' => efpic_gallery_new_slideshow_item(),
            'items' => [],
            'client' => efpic_gallery_normalize_slideshow_slot(null),
        ];
    }

    $client = efpic_gallery_normalize_slideshow_slot($raw['client'] ?? null);
    $draftRaw = is_array($raw['draft'] ?? null) ? $raw['draft'] : [];
    $draft = efpic_gallery_normalize_slideshow_slot($draftRaw);
    $draft['id'] = '';
    $draft['title'] = trim((string) ($draftRaw['title'] ?? ''));

    $items = [];
    $orphans = [];
    $sourceItems = [];
    if (isset($raw['items']) && is_array($raw['items'])) {
        foreach ($raw['items'] as $itemRaw) {
            if (is_array($itemRaw)) {
                $sourceItems[] = $itemRaw;
            }
        }
    }
    $adminRaw = is_array($raw['admin'] ?? null) ? $raw['admin'] : [];
    if ($adminRaw !== [] && efpic_slideshow_legacy_admin_should_migrate($adminRaw, $sourceItems)) {
        array_unshift($sourceItems, $adminRaw);
    } elseif ($sourceItems === [] && $adminRaw !== []) {
        $sourceItems[] = $adminRaw;
    } elseif ($sourceItems === [] && $raw !== [] && !isset($raw['draft']) && !isset($raw['client']) && !isset($raw['items'])) {
        $sourceItems[] = $raw;
    }

    $n = 0;
    foreach ($sourceItems as $itemRaw) {
        ++$n;
        $item = efpic_gallery_ensure_slideshow_item_id(
            efpic_gallery_normalize_slideshow_item($itemRaw, $n),
        );
        if (efpic_slideshow_item_is_published($item)) {
            $items[] = $item;
        } else {
            $orphans[] = $item;
        }
    }

    if ($orphans !== [] && efpic_gallery_slideshow_draft_is_empty($draft)) {
        for ($oi = count($orphans) - 1; $oi >= 0; --$oi) {
            $orphanRaw = $orphans[$oi];
            if (efpic_gallery_slideshow_item_id_valid((string) ($orphanRaw['id'] ?? ''))) {
                continue;
            }
            $draft = efpic_gallery_normalize_slideshow_slot($orphanRaw);
            $draft['id'] = '';
            $draft['title'] = trim((string) ($orphanRaw['title'] ?? ''));
            break;
        }
    }

    return [
        'draft' => $draft,
        'items' => $items,
        'client' => $client,
    ];
}

/** @param array{draft: array<string, mixed>, items: list<array<string, mixed>>, client: array<string, mixed>} $storage */
function efpic_gallery_persist_slideshow_storage(array &$meta, array $storage): void
{
    $draftRaw = is_array($storage['draft'] ?? null) ? $storage['draft'] : [];
    $draft = efpic_gallery_normalize_slideshow_slot($draftRaw);
    $draft['id'] = '';
    $draft['title'] = trim((string) ($draftRaw['title'] ?? ''));
    $items = [];
    $n = 0;
    foreach ($storage['items'] ?? [] as $item) {
        ++$n;
        $items[] = efpic_gallery_ensure_slideshow_item_id(
            efpic_gallery_normalize_slideshow_item($item, $n),
        );
    }
    $meta['slideshow'] = [
        'draft' => $draft,
        'items' => $items,
        'client' => efpic_gallery_normalize_slideshow_slot($storage['client'] ?? null),
    ];
}

/**
 * @return array{admin: array, client: array, items: list<array>}
 */
function efpic_gallery_slideshows_struct(array $meta): array
{
    $storage = efpic_gallery_slideshow_storage($meta);
    $items = $storage['items'];
    $admin = $items[0] ?? $storage['draft'];

    return [
        'admin' => $admin,
        'client' => $storage['client'],
        'items' => $items,
        'draft' => $storage['draft'],
    ];
}

/** @deprecated Use efpic_gallery_slideshows_struct */
function efpic_gallery_normalize_slideshow(array $meta): array
{
    return efpic_gallery_slideshows_struct($meta)['admin'];
}

function efpic_apply_admin_favorites_from_post(array &$meta): void
{
    $hasFavPost = isset($_POST['image_fav_admin']) && is_array($_POST['image_fav_admin']);
    if (!empty($_POST['autosave']) && empty($_POST['favorites_dirty']) && !$hasFavPost) {
        return;
    }
    if (empty($_POST['autosave']) && empty($_POST['favorites_dirty']) && !$hasFavPost) {
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
    efpic_sync_draft_favorite_order_tokens($meta);
}

/** @param list<string> $orderTokens */
function efpic_slideshow_insert_token_by_sort_name(array $orderTokens, string $newToken, array $meta): array
{
    if ($newToken === '') {
        return $orderTokens;
    }
    $byToken = [];
    foreach ($meta['images'] ?? [] as $img) {
        if (!is_array($img)) {
            continue;
        }
        $tok = (string) ($img['token'] ?? '');
        if ($tok !== '') {
            $byToken[$tok] = $img;
        }
    }
    $newImg = $byToken[$newToken] ?? [];
    $insertAt = count($orderTokens);
    foreach ($orderTokens as $i => $tok) {
        if (efpic_compare_image_basenames($newImg, $byToken[$tok] ?? []) < 0) {
            $insertAt = $i;
            break;
        }
    }
    array_splice($orderTokens, $insertAt, 0, [$newToken]);

    return $orderTokens;
}

/** Saglabā favorītu kārtību: esošās bildes paliek vietā, jaunas ievieto pēc faila nosaukuma. */
function efpic_sync_draft_favorite_order_tokens(array &$meta): void
{
    $favorited = [];
    foreach ($meta['images'] ?? [] as $img) {
        if (!is_array($img)) {
            continue;
        }
        if (!efpic_image_favorited_admin($img)) {
            continue;
        }
        $tok = (string) ($img['token'] ?? '');
        if ($tok !== '') {
            $favorited[$tok] = true;
        }
    }

    $storage = efpic_gallery_slideshow_storage($meta);
    $draft = $storage['draft'];
    $order = is_array($draft['image_order_tokens'] ?? null) ? $draft['image_order_tokens'] : [];

    $newOrder = [];
    foreach ($order as $tok) {
        $tok = (string) $tok;
        if ($tok !== '' && isset($favorited[$tok])) {
            $newOrder[] = $tok;
            unset($favorited[$tok]);
        }
    }

    $byToken = [];
    foreach ($meta['images'] ?? [] as $img) {
        if (!is_array($img)) {
            continue;
        }
        $tok = (string) ($img['token'] ?? '');
        if ($tok !== '') {
            $byToken[$tok] = $img;
        }
    }
    $added = array_keys($favorited);
    usort($added, static function (string $a, string $b) use ($byToken): int {
        return efpic_compare_image_basenames($byToken[$a] ?? [], $byToken[$b] ?? []);
    });
    foreach ($added as $tok) {
        $newOrder = efpic_slideshow_insert_token_by_sort_name($newOrder, $tok, $meta);
    }

    $draft['image_order_tokens'] = $newOrder;
    $storage['draft'] = $draft;
    efpic_gallery_persist_slideshow_storage($meta, $storage);
}

function efpic_apply_client_favorites_from_post(array &$meta): void
{
    if (!array_key_exists('image_fav_client', $_POST)) {
        return;
    }
    $posted = $_POST['image_fav_client'] ?? [];
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
        $meta['images'][$i]['favorited_client'] = isset($posted[$tok]);
        unset($meta['images'][$i]['favorited']);
    }
}

/** @return list<string> */
function efpic_slideshow_slot_audio_files(array $slot): array
{
    $files = [];
    if (is_array($slot['audio_files'] ?? null)) {
        foreach ($slot['audio_files'] as $file) {
            $file = (string) $file;
            if (preg_match('/^[a-zA-Z0-9._-]+$/', $file) === 1) {
                $files[] = $file;
            }
        }
    }
    if ($files === []) {
        $legacy = (string) ($slot['audio_file'] ?? '');
        if (preg_match('/^[a-zA-Z0-9._-]+$/', $legacy) === 1) {
            $files[] = $legacy;
        }
    }

    return array_values(array_unique($files));
}

function efpic_slideshow_slot_interactive_ready(array $slot, int $favoriteCount): bool
{
    return $slot['enabled'] && efpic_slideshow_slot_audio_files($slot) !== [] && $favoriteCount > 0;
}

function efpic_slideshow_slot_video_ready(array $slot): bool
{
    $video = trim((string) ($slot['video_file'] ?? ''));
    if ($video === '') {
        return false;
    }

    $status = (string) ($slot['render_status'] ?? 'none');
    if ($status === 'ready') {
        return true;
    }
    if (in_array($status, ['queued', 'processing'], true)) {
        return false;
    }

    return true;
}

function efpic_slideshow_slot_public_video_enabled(array $slot): bool
{
    return !empty($slot['enabled']) && efpic_slideshow_slot_video_ready($slot);
}

function efpic_slideshow_slot_owns_video_file(array $slot, string $filename): bool
{
    return $filename !== '' && trim((string) ($slot['video_file'] ?? '')) === $filename;
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
    if (!efpic_viewer_include_slideshow($ctx)) {
        return null;
    }
    $storage = efpic_gallery_slideshow_storage($meta);
    $clientSlot = $storage['client'];
    if (efpic_slideshow_slot_public_video_enabled($clientSlot)) {
        return [
            'owner' => 'client',
            'slideshow_id' => 'client',
            'mode' => 'video',
            'slideshow' => $clientSlot,
            'images' => [],
        ];
    }
    foreach ($storage['items'] as $item) {
        if (!efpic_slideshow_slot_public_video_enabled($item)) {
            continue;
        }
        return [
            'owner' => 'admin',
            'slideshow_id' => (string) ($item['id'] ?? ''),
            'mode' => 'video',
            'slideshow' => $item,
            'images' => [],
        ];
    }

    $clientResolved = efpic_try_resolve_public_slideshow_owner(
        $meta,
        $ctx,
        $config,
        'client',
        $clientSlot,
        efpic_count_favorites($meta, 'client'),
    );
    if ($clientResolved !== null) {
        $clientResolved['slideshow_id'] = 'client';

        return $clientResolved;
    }
    foreach ($storage['items'] as $item) {
        $resolved = efpic_try_resolve_public_slideshow_owner(
            $meta,
            $ctx,
            $config,
            'admin',
            $item,
            efpic_count_favorites($meta, 'admin'),
        );
        if ($resolved !== null) {
            $resolved['slideshow_id'] = (string) ($item['id'] ?? '');

            return $resolved;
        }
    }

    return null;
}

/**
 * Publiskās galerijas MP4 slideshow sadaļas (kārtotas pēc section_order).
 *
 * @return list<array{owner: string, slideshow: array, title: string, placement: string, after_scene: string, order: int}>
 */
function efpic_collect_public_slideshow_video_sections(array $meta, array $ctx, array $config): array
{
    if (!efpic_viewer_include_slideshow($ctx)) {
        return [];
    }
    $storage = efpic_gallery_slideshow_storage($meta);
    $out = [];
    $adminIndex = 0;
    foreach ($storage['items'] as $item) {
        ++$adminIndex;
        if (!efpic_slideshow_slot_public_video_enabled($item)) {
            continue;
        }
        $title = trim((string) ($item['section_title'] ?? ''));
        $placement = (string) ($item['section_placement'] ?? 'top');
        if ($placement === 'after_scene') {
            $placement = 'before_scene';
        }
        if (!in_array($placement, ['top', 'bottom', 'before_scene'], true)) {
            $placement = 'top';
        }
        $afterScene = (string) ($item['section_after_scene'] ?? '');
        if ($placement === 'before_scene' && $afterScene === '') {
            $placement = 'top';
        }
        $order = (int) ($item['section_order'] ?? 0);
        if ($order <= 0) {
            $order = 20 + ($adminIndex - 1) * 5;
        }
        $out[] = [
            'owner' => 'admin',
            'slideshow_id' => (string) ($item['id'] ?? ''),
            'slideshow' => $item,
            'title' => $title,
            'placement' => $placement,
            'after_scene' => $afterScene,
            'order' => $order,
        ];
    }
    $clientSlot = $storage['client'];
    if (efpic_slideshow_slot_public_video_enabled($clientSlot)) {
        $title = trim((string) ($clientSlot['section_title'] ?? ''));
        $placement = (string) ($clientSlot['section_placement'] ?? 'top');
        if ($placement === 'after_scene') {
            $placement = 'before_scene';
        }
        if (!in_array($placement, ['top', 'bottom', 'before_scene'], true)) {
            $placement = 'top';
        }
        $afterScene = (string) ($clientSlot['section_after_scene'] ?? '');
        if ($placement === 'before_scene' && $afterScene === '') {
            $placement = 'top';
        }
        $order = (int) ($clientSlot['section_order'] ?? 0);
        if ($order <= 0) {
            $order = 10;
        }
        $out[] = [
            'owner' => 'client',
            'slideshow_id' => 'client',
            'slideshow' => $clientSlot,
            'title' => $title,
            'placement' => $placement,
            'after_scene' => $afterScene,
            'order' => $order,
        ];
    }
    usort($out, static fn (array $a, array $b): int => ((int) $a['order']) <=> ((int) $b['order']));

    return $out;
}

function efpic_slideshow_render_config_fingerprint(array $slot): string
{
    $order = is_array($slot['image_order_tokens'] ?? null) ? $slot['image_order_tokens'] : [];

    $sceneIds = is_array($slot['image_scene_ids'] ?? null) ? $slot['image_scene_ids'] : [];

    return hash('sha256', implode("\0", [
        implode(',', efpic_slideshow_slot_audio_files($slot)),
        implode(',', $order),
        (string) ($slot['intro_title'] ?? ''),
        (string) ($slot['bg_mode'] ?? ''),
        (string) ($slot['image_source'] ?? 'favorites'),
        implode(',', $sceneIds),
        (string) (int) ($slot['interval_sec'] ?? 5),
    ]));
}

function efpic_slideshow_video_is_stale(array $slot): bool
{
    if (!efpic_slideshow_slot_video_ready($slot)) {
        return false;
    }
    $saved = (string) ($slot['render_fingerprint'] ?? '');
    if ($saved === '') {
        return false;
    }

    return !hash_equals($saved, efpic_slideshow_render_config_fingerprint($slot));
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
    $storage = efpic_gallery_slideshow_storage($meta);
    foreach ($storage['items'] as $item) {
        if (($item['video_file'] ?? '') === $filename) {
            return true;
        }
        foreach (efpic_slideshow_slot_audio_files($item) as $audioFile) {
            if ($audioFile === $filename) {
                return true;
            }
        }
    }
    $clientSlot = $storage['client'];
    if (($clientSlot['video_file'] ?? '') === $filename) {
        return true;
    }
    foreach (efpic_slideshow_slot_audio_files($clientSlot) as $audioFile) {
        if ($audioFile === $filename) {
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

function efpic_collect_uploaded_files(string $field): array
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        return [];
    }
    $file = $_FILES[$field];
    if (!is_array($file['error'] ?? null)) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [];
        }

        return [$file];
    }

    $out = [];
    foreach ($file['error'] as $i => $error) {
        if ((int) $error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $out[] = [
            'name' => (string) ($file['name'][$i] ?? ''),
            'type' => (string) ($file['type'][$i] ?? ''),
            'tmp_name' => (string) ($file['tmp_name'][$i] ?? ''),
            'error' => (int) $error,
            'size' => (int) ($file['size'][$i] ?? 0),
        ];
    }

    return $out;
}

/** @param list<string> $files */
function efpic_slideshow_reorder_audio_files(array $files, array $order): array
{
    if ($order === []) {
        return $files;
    }
    $lookup = array_fill_keys($files, true);
    $out = [];
    foreach ($order as $file) {
        $file = (string) $file;
        if ($file !== '' && isset($lookup[$file])) {
            $out[] = $file;
            unset($lookup[$file]);
        }
    }
    foreach ($files as $file) {
        if (isset($lookup[$file])) {
            $out[] = $file;
        }
    }

    return $out;
}

/** @return list<array<string, mixed>> */
function efpic_slideshow_collect_scene_images(array $meta, array $ctx, array $config, array $sceneIds): array
{
    if ($sceneIds === []) {
        return [];
    }
    $sceneSet = array_flip($sceneIds);
    $sceneOrder = [];
    foreach (efpic_gallery_sorted_scenes($meta) as $i => $scene) {
        $sid = (string) ($scene['id'] ?? '');
        if ($sid !== '' && isset($sceneSet[$sid])) {
            $sceneOrder[$sid] = $i;
        }
    }
    $out = [];
    foreach (efpic_sort_images_for_display($meta) as $img) {
        if (!is_array($img)) {
            continue;
        }
        $sceneId = (string) ($img['scene_id'] ?? 'main');
        if (!isset($sceneSet[$sceneId])) {
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
    usort($out, static function (array $a, array $b) use ($sceneOrder): int {
        $sa = (string) ($a['scene_id'] ?? 'main');
        $sb = (string) ($b['scene_id'] ?? 'main');
        $oa = $sceneOrder[$sa] ?? 9999;
        $ob = $sceneOrder[$sb] ?? 9999;
        if ($oa !== $ob) {
            return $oa <=> $ob;
        }
        $na = strtolower((string) ($a['basename'] ?? $a['filename'] ?? ''));
        $nb = strtolower((string) ($b['basename'] ?? $b['filename'] ?? ''));

        return $na <=> $nb;
    });

    return $out;
}

/**
 * @param array<string, mixed> $slideshow
 */
function efpic_apply_slideshow_ready_item_from_post(array $config, string $slug, array &$item, string $prefix): void
{
    $readyKey = $prefix . '_ready';
    $enabledKey = $prefix . '_enabled';
    $hasReady = !empty($_POST[$readyKey]);
    $hasEnabled = array_key_exists($enabledKey, $_POST);
    $hasPlacement = array_key_exists($prefix . '_section_title', $_POST)
        || isset($_POST[$prefix . '_section_placement'])
        || array_key_exists($prefix . '_section_after_scene', $_POST)
        || array_key_exists($prefix . '_section_order', $_POST);

    if (!$hasReady && !$hasEnabled && !$hasPlacement) {
        return;
    }

    if ($hasEnabled) {
        $item['enabled'] = efpic_post_flag_is_on($enabledKey);
    }

    if (!$hasReady && !$hasPlacement) {
        return;
    }

    if (array_key_exists($prefix . '_section_title', $_POST)) {
        $sectionTitle = trim((string) $_POST[$prefix . '_section_title']);
        if (function_exists('mb_substr')) {
            $sectionTitle = mb_substr($sectionTitle, 0, 80);
        } else {
            $sectionTitle = substr($sectionTitle, 0, 80);
        }
        $item['section_title'] = $sectionTitle;
    }
    if (isset($_POST[$prefix . '_section_placement'])) {
        $placement = (string) $_POST[$prefix . '_section_placement'];
        if ($placement === 'after_scene') {
            $placement = 'before_scene';
        }
        $item['section_placement'] = in_array($placement, ['top', 'bottom', 'before_scene'], true) ? $placement : 'top';
    }
    if (array_key_exists($prefix . '_section_after_scene', $_POST)) {
        $afterScene = trim((string) $_POST[$prefix . '_section_after_scene']);
        $item['section_after_scene'] = preg_match('/^[a-zA-Z0-9_-]+$/', $afterScene) === 1 ? $afterScene : '';
    }
    if (($item['section_placement'] ?? 'top') === 'before_scene' && ($item['section_after_scene'] ?? '') === '') {
        $item['section_placement'] = 'top';
    }
    if (array_key_exists($prefix . '_section_order', $_POST)) {
        $item['section_order'] = max(0, min(999, (int) $_POST[$prefix . '_section_order']));
    }
    if (!empty($_POST[$prefix . '_remove_video'])) {
        efpic_slideshow_clear_slot_video($config, $slug, $item, 'admin', (string) ($item['id'] ?? ''));
    }
}

function efpic_apply_slideshow_item_fields_from_post(
    array $config,
    string $slug,
    array $meta,
    array &$slideshow,
    string $prefix,
    string $owner,
    bool $touchEnabled = false,
): void {
    if ($touchEnabled && array_key_exists($prefix . '_enabled', $_POST)) {
        $slideshow['enabled'] = efpic_post_flag_is_on($prefix . '_enabled');
    }
    $titleKey = $prefix . '_title';
    if (array_key_exists($titleKey, $_POST)) {
        $title = trim((string) $_POST[$titleKey]);
        if (function_exists('mb_substr')) {
            $title = mb_substr($title, 0, 80);
        } else {
            $title = substr($title, 0, 80);
        }
        $slideshow['title'] = $title;
    }
    $interval = (int) ($_POST[$prefix . '_interval'] ?? $slideshow['interval_sec']);
    $slideshow['interval_sec'] = max(2, min(60, $interval));

    $audioFiles = efpic_slideshow_slot_audio_files($slideshow);
    $maxAudioTracks = 8;

    if (array_key_exists($prefix . '_audio_order', $_POST)) {
        $orderRaw = trim((string) $_POST[$prefix . '_audio_order']);
        $order = $orderRaw === '' ? [] : array_filter(array_map('trim', explode(',', $orderRaw)));
        $validOrder = [];
        foreach ($order as $file) {
            if (preg_match('/^[a-zA-Z0-9._-]+$/', $file) === 1) {
                $validOrder[] = $file;
            }
        }
        $audioFiles = efpic_slideshow_reorder_audio_files($audioFiles, $validOrder);
    }

    $removeAll = !empty($_POST[$prefix . '_remove_audio']);
    if ($removeAll) {
        foreach ($audioFiles as $file) {
            efpic_delete_gallery_asset_file($config, $slug, $file);
        }
        $audioFiles = [];
    }

    $removeFiles = $_POST[$prefix . '_remove_audio_file'] ?? [];
    if (is_array($removeFiles) && $removeFiles !== []) {
        $kept = [];
        foreach ($audioFiles as $file) {
            if (!empty($removeFiles[$file])) {
                efpic_delete_gallery_asset_file($config, $slug, $file);
            } else {
                $kept[] = $file;
            }
        }
        $audioFiles = $kept;
    }

    $uploads = efpic_collect_uploaded_files($prefix . '_mp3');
    if ($owner === 'client') {
        $clientUploads = efpic_collect_uploaded_files('slideshow_mp3');
        if ($uploads === [] && $clientUploads !== []) {
            $uploads = $clientUploads;
        }
    }
    if ($uploads !== []) {
        $max = 25 * 1024 * 1024;
        foreach ($uploads as $upload) {
            if (count($audioFiles) >= $maxAudioTracks) {
                break;
            }
            $newFile = efpic_store_gallery_upload($config, $slug, $upload, ['mp3'], $max);
            if (!in_array($newFile, $audioFiles, true)) {
                $audioFiles[] = $newFile;
            }
        }
    }

    $slideshow['audio_files'] = array_values($audioFiles);
    $slideshow['audio_file'] = $audioFiles[0] ?? '';

    if (array_key_exists($prefix . '_intro_title', $_POST)) {
        $intro = trim((string) $_POST[$prefix . '_intro_title']);
        if (function_exists('mb_substr')) {
            $intro = mb_substr($intro, 0, 120);
        } else {
            $intro = substr($intro, 0, 120);
        }
        $slideshow['intro_title'] = $intro;
    }

    if (array_key_exists($prefix . '_section_title', $_POST)) {
        $sectionTitle = trim((string) $_POST[$prefix . '_section_title']);
        if (function_exists('mb_substr')) {
            $sectionTitle = mb_substr($sectionTitle, 0, 80);
        } else {
            $sectionTitle = substr($sectionTitle, 0, 80);
        }
        $slideshow['section_title'] = $sectionTitle;
    }

    if (isset($_POST[$prefix . '_section_placement'])) {
        $placement = (string) $_POST[$prefix . '_section_placement'];
        if ($placement === 'after_scene') {
            $placement = 'before_scene';
        }
        $slideshow['section_placement'] = in_array($placement, ['top', 'bottom', 'before_scene'], true) ? $placement : 'top';
    }
    if (array_key_exists($prefix . '_section_after_scene', $_POST)) {
        $afterScene = trim((string) $_POST[$prefix . '_section_after_scene']);
        $slideshow['section_after_scene'] = preg_match('/^[a-zA-Z0-9_-]+$/', $afterScene) === 1 ? $afterScene : '';
    }
    if (($slideshow['section_placement'] ?? 'top') === 'before_scene' && ($slideshow['section_after_scene'] ?? '') === '') {
        $slideshow['section_placement'] = 'top';
    }
    if (array_key_exists($prefix . '_section_order', $_POST)) {
        $slideshow['section_order'] = max(0, min(999, (int) $_POST[$prefix . '_section_order']));
    }

    if (isset($_POST[$prefix . '_bg_mode'])) {
        $bg = (string) $_POST[$prefix . '_bg_mode'];
        $slideshow['bg_mode'] = in_array($bg, ['white', 'gallery'], true) ? $bg : 'white';
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

    if (!empty($_POST[$prefix . '_remove_video'])) {
        efpic_slideshow_clear_slot_video($config, $slug, $slideshow, $owner, (string) ($slideshow['id'] ?? ''));
    }

    if ($owner === 'admin') {
        $source = (string) ($_POST[$prefix . '_image_source'] ?? 'favorites');
        if (!in_array($source, ['favorites', 'all', 'scenes'], true)) {
            $source = 'favorites';
        }
        $slideshow['image_source'] = $source;
        $validSceneIds = [];
        foreach (efpic_gallery_scene_options($meta) as $scene) {
            $validSceneIds[(string) ($scene['id'] ?? '')] = true;
        }
        $postedScenes = $_POST[$prefix . '_scene_ids'] ?? [];
        $sceneIds = [];
        if (is_array($postedScenes)) {
            foreach ($postedScenes as $sid) {
                $sid = (string) $sid;
                if (isset($validSceneIds[$sid])) {
                    $sceneIds[] = $sid;
                }
            }
        }
        $slideshow['image_scene_ids'] = array_values(array_unique($sceneIds));
    }
}

function efpic_slideshow_ready_effective_order(array $slot, string $owner, int $adminIndex = 1): int
{
    $order = (int) ($slot['section_order'] ?? 0);
    if ($order > 0) {
        return $order;
    }

    return $owner === 'client' ? 10 : 20 + max(0, $adminIndex - 1) * 5;
}

function efpic_slideshow_ready_move_target_valid(string $target): bool
{
    return $target === 'client' || efpic_gallery_slideshow_item_id_valid($target);
}

function efpic_slideshow_set_ready_slot_order(array &$meta, string $owner, string $id, int $order): void
{
    $storage = efpic_gallery_slideshow_storage($meta);
    if ($owner === 'client') {
        $storage['client']['section_order'] = max(1, min(999, $order));
        efpic_gallery_persist_slideshow_storage($meta, $storage);

        return;
    }
    foreach ($storage['items'] as $i => $item) {
        if ((string) ($item['id'] ?? '') !== $id) {
            continue;
        }
        $storage['items'][$i]['section_order'] = max(1, min(999, $order));
        efpic_gallery_persist_slideshow_storage($meta, $storage);

        return;
    }
}

function efpic_slideshow_apply_ready_move_from_post(array &$meta, string $target, string $direction): void
{
    if (!efpic_slideshow_ready_move_target_valid($target) || !in_array($direction, ['up', 'down'], true)) {
        return;
    }

    $storage = efpic_gallery_slideshow_storage($meta);
    $entries = [];
    $client = efpic_slideshow_slot_with_render($storage['client']);
    if (efpic_slideshow_item_is_published($client)) {
        $entries[] = [
            'owner' => 'client',
            'id' => 'client',
            'key' => 'client',
            'order' => efpic_slideshow_ready_effective_order($client, 'client'),
        ];
    }
    $adminIndex = 0;
    foreach ($storage['items'] as $item) {
        if (!efpic_slideshow_item_is_published($item)) {
            continue;
        }
        ++$adminIndex;
        $id = (string) ($item['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $entries[] = [
            'owner' => 'admin',
            'id' => $id,
            'key' => $id,
            'order' => efpic_slideshow_ready_effective_order($item, 'admin', $adminIndex),
        ];
    }
    if (count($entries) < 2) {
        return;
    }
    usort($entries, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

    $idx = -1;
    foreach ($entries as $i => $entry) {
        if ($entry['key'] === $target) {
            $idx = $i;
            break;
        }
    }
    if ($idx < 0) {
        return;
    }
    $swapIdx = $direction === 'up' ? $idx - 1 : $idx + 1;
    if ($swapIdx < 0 || $swapIdx >= count($entries)) {
        return;
    }

    $orderA = (int) $entries[$idx]['order'];
    $orderB = (int) $entries[$swapIdx]['order'];
    efpic_slideshow_set_ready_slot_order($meta, $entries[$idx]['owner'], $entries[$idx]['id'], $orderB);
    efpic_slideshow_set_ready_slot_order($meta, $entries[$swapIdx]['owner'], $entries[$swapIdx]['id'], $orderA);
}

/**
 * Gatavo slideshow stāvoklis no JSON (formas augšā) — nepieciešams, ja POST beigas tiek nogrieztas.
 *
 * @param list<array<string, mixed>> $items
 */
function efpic_apply_ready_slideshow_payload_from_post(array &$items, array &$client): void
{
    $rawJson = trim((string) ($_POST['ready_slideshow_payload'] ?? ''));
    if ($rawJson === '') {
        return;
    }
    $entries = json_decode($rawJson, true);
    if (!is_array($entries)) {
        return;
    }

    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $owner = (string) ($entry['owner'] ?? 'admin');
        if ($owner === 'client') {
            $client['enabled'] = !empty($entry['enabled']);
            if (array_key_exists('section_title', $entry)) {
                $sectionTitle = trim((string) $entry['section_title']);
                if (function_exists('mb_substr')) {
                    $sectionTitle = mb_substr($sectionTitle, 0, 80);
                } else {
                    $sectionTitle = substr($sectionTitle, 0, 80);
                }
                $client['section_title'] = $sectionTitle;
            }
            if (array_key_exists('section_placement', $entry)) {
                $placement = (string) $entry['section_placement'];
                if ($placement === 'after_scene') {
                    $placement = 'before_scene';
                }
                $client['section_placement'] = in_array($placement, ['top', 'bottom', 'before_scene'], true)
                    ? $placement : 'top';
            }
            if (array_key_exists('section_after_scene', $entry)) {
                $afterScene = trim((string) $entry['section_after_scene']);
                $client['section_after_scene'] = preg_match('/^[a-zA-Z0-9_-]+$/', $afterScene) === 1 ? $afterScene : '';
            }
            if (($client['section_placement'] ?? 'top') === 'before_scene' && ($client['section_after_scene'] ?? '') === '') {
                $client['section_placement'] = 'top';
            }
            continue;
        }

        $id = (string) ($entry['id'] ?? '');
        $targetIdx = null;
        if (efpic_gallery_slideshow_item_id_valid($id)) {
            foreach ($items as $i => $item) {
                if ((string) ($item['id'] ?? '') === $id) {
                    $targetIdx = $i;
                    break;
                }
            }
        }
        if ($targetIdx === null) {
            foreach ($items as $i => $item) {
                if (!efpic_slideshow_slot_video_ready($item)) {
                    continue;
                }
                $targetIdx = $i;
                break;
            }
        }
        if ($targetIdx === null && count($items) === 1) {
            $targetIdx = 0;
        }
        if ($targetIdx === null) {
            continue;
        }
        $item = &$items[$targetIdx];
        $item['enabled'] = !empty($entry['enabled']);
        if (array_key_exists('section_title', $entry)) {
            $sectionTitle = trim((string) $entry['section_title']);
            if (function_exists('mb_substr')) {
                $sectionTitle = mb_substr($sectionTitle, 0, 80);
            } else {
                $sectionTitle = substr($sectionTitle, 0, 80);
            }
            $item['section_title'] = $sectionTitle;
        }
        if (array_key_exists('section_placement', $entry)) {
            $placement = (string) $entry['section_placement'];
            if ($placement === 'after_scene') {
                $placement = 'before_scene';
            }
            $item['section_placement'] = in_array($placement, ['top', 'bottom', 'before_scene'], true)
                ? $placement : 'top';
        }
        if (array_key_exists('section_after_scene', $entry)) {
            $afterScene = trim((string) $entry['section_after_scene']);
            $item['section_after_scene'] = preg_match('/^[a-zA-Z0-9_-]+$/', $afterScene) === 1 ? $afterScene : '';
        }
        if (($item['section_placement'] ?? 'top') === 'before_scene' && ($item['section_after_scene'] ?? '') === '') {
            $item['section_placement'] = 'top';
        }
        unset($item);
    }
}

/** @param list<array<string, mixed>> $items */
function efpic_apply_legacy_slideshow_admin_enabled_from_post(array &$items): void
{
    if (!array_key_exists('slideshow_admin_enabled', $_POST)) {
        return;
    }
    $enabled = efpic_post_flag_is_on('slideshow_admin_enabled');
    $targetIdx = null;
    foreach ($items as $i => $item) {
        if (efpic_slideshow_slot_video_ready($item)) {
            $targetIdx = $i;
            break;
        }
    }
    if ($targetIdx === null && $items !== []) {
        $targetIdx = 0;
    }
    if ($targetIdx !== null) {
        $items[$targetIdx]['enabled'] = $enabled;
    }
}

function efpic_apply_admin_slideshow_items_from_post(array $config, string $slug, array &$meta): void
{
    $moveUp = trim((string) ($_POST['slideshow_move_up'] ?? ''));
    $moveDown = trim((string) ($_POST['slideshow_move_down'] ?? ''));
    if ($moveUp !== '') {
        efpic_slideshow_apply_ready_move_from_post($meta, $moveUp, 'up');
    } elseif ($moveDown !== '') {
        efpic_slideshow_apply_ready_move_from_post($meta, $moveDown, 'down');
    }

    $storage = efpic_gallery_slideshow_storage($meta);
    $items = $storage['items'];
    $draft = $storage['draft'];

    $deleteId = trim((string) ($_POST['slideshow_delete_item'] ?? ''));
    if ($deleteId !== '' && efpic_gallery_slideshow_item_id_valid($deleteId)) {
        $kept = [];
        foreach ($items as $item) {
            if ((string) ($item['id'] ?? '') === $deleteId) {
                efpic_slideshow_clear_slot_video($config, $slug, $item, 'admin', $deleteId);
                continue;
            }
            $kept[] = $item;
        }
        $items = $kept;
    }

    efpic_apply_slideshow_item_fields_from_post($config, $slug, $meta, $draft, 'slideshow_draft', 'admin');

    $favoritesOrderRaw = null;
    foreach (['slideshow_favorites_image_order', 'slideshow_item_favorites_image_order'] as $favoritesOrderKey) {
        if (array_key_exists($favoritesOrderKey, $_POST)) {
            $favoritesOrderRaw = trim((string) $_POST[$favoritesOrderKey]);
            break;
        }
    }
    if ($favoritesOrderRaw !== null) {
        $tokens = $favoritesOrderRaw === '' ? [] : array_filter(array_map('trim', explode(',', $favoritesOrderRaw)));
        $valid = [];
        foreach ($tokens as $tok) {
            if (preg_match('/^[a-f0-9]{48}$/', $tok) === 1) {
                $valid[] = $tok;
            }
        }
        $draft['image_order_tokens'] = $valid;
    }

    efpic_apply_legacy_slideshow_admin_enabled_from_post($items);

    foreach ($items as $i => &$item) {
        $id = (string) ($item['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $prefix = 'slideshow_item_' . $id;
        $enabledKey = $prefix . '_enabled';
        if (array_key_exists($enabledKey, $_POST)) {
            $item['enabled'] = efpic_post_flag_is_on($enabledKey);
        }
        efpic_apply_slideshow_ready_item_from_post($config, $slug, $item, $prefix);
    }
    unset($item);
    $items = array_values(array_filter($items, 'efpic_slideshow_item_is_published'));

    $client = $storage['client'];
    if (!empty($_POST['slideshow_client_ready']) || array_key_exists('slideshow_client_enabled', $_POST)) {
        efpic_apply_slideshow_ready_item_from_post($config, $slug, $client, 'slideshow_client');
    }

    efpic_apply_ready_slideshow_payload_from_post($items, $client);

    $storage['client'] = $client;
    $storage['draft'] = $draft;
    $storage['items'] = $items;
    efpic_gallery_persist_slideshow_storage($meta, $storage);
}

/** Izveido jaunu slideshow ierakstu no melnraksta un ieliek render rindā. */
function efpic_slideshow_create_from_draft(array $config, string $slug, array &$meta): string
{
    $storage = efpic_gallery_slideshow_storage($meta);
    $draft = $storage['draft'];
    $newItem = efpic_gallery_new_slideshow_item_from_draft($draft);
    $storage['items'][] = $newItem;
    $storage['draft'] = efpic_gallery_new_slideshow_item();
    efpic_gallery_persist_slideshow_storage($meta, $storage);

    $newId = (string) $newItem['id'];
    efpic_slideshow_enqueue_render($config, $slug, $meta, 'admin', $newId);

    return $newId;
}

function efpic_apply_slideshow_from_post(array $config, string $slug, array &$meta, string $owner = 'admin'): void
{
    if ($owner === 'admin') {
        efpic_apply_admin_slideshow_items_from_post($config, $slug, $meta);

        return;
    }
    $storage = efpic_gallery_slideshow_storage($meta);
    $slideshow = $storage['client'];
    if (!array_key_exists('slideshow_client_enabled', $_POST) && array_key_exists('slideshow_enabled', $_POST)) {
        $slideshow['enabled'] = !empty($_POST['slideshow_enabled']);
    }
    efpic_apply_slideshow_item_fields_from_post($config, $slug, $meta, $slideshow, 'slideshow_client', 'client');
    $storage['client'] = $slideshow;
    efpic_gallery_persist_slideshow_storage($meta, $storage);
}

/** Tikai publiskās sadaļas virsraksts, vieta un secība (admin var labot arī klienta slideshow). */
function efpic_apply_slideshow_public_placement_from_post(array &$meta, string $owner): void
{
    if (!in_array($owner, ['admin', 'client'], true)) {
        return;
    }
    if ($owner !== 'client') {
        return;
    }
    $prefix = 'slideshow_client';
    if (!array_key_exists($prefix . '_placement_fields', $_POST)) {
        return;
    }

    $storage = efpic_gallery_slideshow_storage($meta);
    $slideshow = $storage['client'];
    if (array_key_exists($prefix . '_section_title', $_POST)) {
        $sectionTitle = trim((string) $_POST[$prefix . '_section_title']);
        if (function_exists('mb_substr')) {
            $sectionTitle = mb_substr($sectionTitle, 0, 80);
        } else {
            $sectionTitle = substr($sectionTitle, 0, 80);
        }
        $slideshow['section_title'] = $sectionTitle;
    }
    if (isset($_POST[$prefix . '_section_placement'])) {
        $placement = (string) $_POST[$prefix . '_section_placement'];
        if ($placement === 'after_scene') {
            $placement = 'before_scene';
        }
        $slideshow['section_placement'] = in_array($placement, ['top', 'bottom', 'before_scene'], true) ? $placement : 'top';
    }
    if (array_key_exists($prefix . '_section_after_scene', $_POST)) {
        $afterScene = trim((string) $_POST[$prefix . '_section_after_scene']);
        $slideshow['section_after_scene'] = preg_match('/^[a-zA-Z0-9_-]+$/', $afterScene) === 1 ? $afterScene : '';
    }
    if (array_key_exists($prefix . '_section_order', $_POST)) {
        $slideshow['section_order'] = max(0, min(999, (int) $_POST[$prefix . '_section_order']));
    }
    if (($slideshow['section_placement'] ?? 'top') === 'before_scene' && ($slideshow['section_after_scene'] ?? '') === '') {
        $slideshow['section_placement'] = 'top';
    }
    $storage['client'] = $slideshow;
    efpic_gallery_persist_slideshow_storage($meta, $storage);
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

    usort($out, 'efpic_compare_image_basenames');

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

    $storage = efpic_gallery_slideshow_storage($meta);
    $allowSlideshow = efpic_viewer_include_slideshow($ctx);
    foreach ($storage['items'] as $item) {
        if (efpic_slideshow_slot_owns_video_file($item, $filename)) {
            return $allowSlideshow && efpic_slideshow_slot_public_video_enabled($item);
        }
        foreach (efpic_slideshow_slot_audio_files($item) as $audioFile) {
            if ($audioFile === $filename) {
                return $allowSlideshow && efpic_slideshow_slot_public_video_enabled($item);
            }
        }
    }
    $clientSlot = $storage['client'];
    if (efpic_slideshow_slot_owns_video_file($clientSlot, $filename)) {
        return $allowSlideshow && efpic_slideshow_slot_public_video_enabled($clientSlot);
    }
    foreach (efpic_slideshow_slot_audio_files($clientSlot) as $audioFile) {
        if ($audioFile === $filename) {
            return $allowSlideshow && efpic_slideshow_slot_public_video_enabled($clientSlot);
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
    bool $includeVideos = false,
    bool $includeSlideshow = true,
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
        'include_slideshow' => $includeSlideshow,
        'created_at' => gmdate('c'),
        'created_by' => $createdBy,
    ];
    $guests[] = $entry;
    $meta['guests'] = $guests;

    return $entry;
}

function efpic_log_share_set_created(array $config, string $slug, array &$meta, array $entry, string $actor): void
{
    if (!function_exists('efpic_gallery_log_activity')) {
        return;
    }
    $label = (string) ($entry['label'] ?? 'Izlase');
    $tokens = is_array($entry['image_tokens'] ?? null) ? $entry['image_tokens'] : [];
    $count = count($tokens);
    efpic_gallery_log_activity(
        $config,
        $slug,
        $meta,
        'share_created',
        'Kopīgojamā izlase «' . $label . '» (' . $count . ' bildes)',
        $actor,
        ['image_tokens' => $tokens],
    );
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

function efpic_remove_from_share_set(array &$meta, string $guestToken, string $imageToken): void
{
    $guestToken = trim($guestToken);
    $imageToken = trim($imageToken);
    if ($guestToken === '' || $imageToken === '') {
        throw new InvalidArgumentException('Nav izvēlēta bilde vai izlase.');
    }
    $guests = $meta['guests'] ?? [];
    if (!is_array($guests)) {
        throw new InvalidArgumentException('Izlase nav atrasta.');
    }
    foreach ($guests as $gi => $guest) {
        if (!is_array($guest) || (string) ($guest['guest_token'] ?? '') !== $guestToken) {
            continue;
        }
        $tokens = [];
        foreach ($guest['image_tokens'] ?? [] as $tok) {
            $tok = (string) $tok;
            if ($tok !== '' && $tok !== $imageToken) {
                $tokens[] = $tok;
            }
        }
        if ($tokens === []) {
            throw new InvalidArgumentException('Izlasei jāpaliek vismaz viena bilde.');
        }
        $guests[$gi]['image_tokens'] = $tokens;
        $meta['guests'] = $guests;

        return;
    }
    throw new InvalidArgumentException('Izlase nav atrasta.');
}

function efpic_update_share_set_meta(
    array &$meta,
    string $guestToken,
    ?bool $includeVideos = null,
    ?bool $includeSlideshow = null,
): void {
    $guestToken = trim($guestToken);
    foreach ($meta['guests'] ?? [] as $gi => $guest) {
        if (!is_array($guest) || (string) ($guest['guest_token'] ?? '') !== $guestToken) {
            continue;
        }
        if ($includeVideos !== null) {
            $meta['guests'][$gi]['include_videos'] = $includeVideos;
        }
        if ($includeSlideshow !== null) {
            $meta['guests'][$gi]['include_slideshow'] = $includeSlideshow;
        }

        return;
    }
    throw new InvalidArgumentException('Izlase nav atrasta.');
}

function efpic_gallery_has_shareable_client_slideshow(array $meta): bool
{
    $clientSlot = efpic_slideshow_slot_with_render(efpic_gallery_slideshow_storage($meta)['client']);
    if (efpic_slideshow_slot_video_ready($clientSlot)) {
        return true;
    }
    $favCount = efpic_count_favorites($meta, 'client');

    return efpic_slideshow_slot_audio_files($clientSlot) !== [] && $favCount > 0;
}

function efpic_apply_share_actions_from_post(
    array &$meta,
    string $createdBy = 'admin',
    ?array $logContext = null,
): void {
    $action = trim((string) ($_POST['share_action'] ?? ''));
    if ($action === 'create') {
        $label = trim((string) ($_POST['share_set_label'] ?? ''));
        $raw = trim((string) ($_POST['share_set_tokens'] ?? ''));
        $tokens = array_values(array_filter(array_map('trim', explode(',', $raw))));
        $includeSlideshow = array_key_exists('share_include_slideshow', $_POST)
            ? !empty($_POST['share_include_slideshow'])
            : true;
        $entry = efpic_create_share_set(
            $meta,
            $label,
            $tokens,
            $createdBy,
            !empty($_POST['share_include_videos']),
            $includeSlideshow,
        );
        if (is_array($logContext) && isset($logContext['config'], $logContext['slug'])) {
            efpic_log_share_set_created($logContext['config'], (string) $logContext['slug'], $meta, $entry, $createdBy);
        }

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
    if ($action === 'remove_image') {
        $guestToken = trim((string) ($_POST['share_guest_token'] ?? ''));
        $imageToken = trim((string) ($_POST['share_image_token'] ?? ''));
        efpic_remove_from_share_set($meta, $guestToken, $imageToken);

        return;
    }
    if ($action === 'update_videos') {
        $guestToken = trim((string) ($_POST['share_guest_token'] ?? ''));
        efpic_update_share_set_meta($meta, $guestToken, !empty($_POST['share_include_videos']), null);

        return;
    }
    if ($action === 'update_slideshow') {
        $guestToken = trim((string) ($_POST['share_guest_token'] ?? ''));
        efpic_update_share_set_meta($meta, $guestToken, null, !empty($_POST['share_include_slideshow']));
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

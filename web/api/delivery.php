<?php

declare(strict_types=1);

require_once __DIR__ . '/failiem_client.php';
require_once __DIR__ . '/image_dimensions.php';
require_once __DIR__ . '/gallery_assets.php';

/**
 * Sinhronizē delivery galeriju no divām Failiem mapēm.
 *
 * @return array{ok: bool, stats: array, warnings: list<string>}
 */
function efpic_sync_delivery_gallery(array $config, string $slug): array
{
    $meta = efpic_load_gallery_meta($config, $slug);
    if ($meta === null) {
        throw new RuntimeException('Galerija nav atrasta');
    }
    if (!efpic_is_delivery_gallery($meta)) {
        throw new RuntimeException('Nav delivery tipa galerija');
    }

    $failiem = $meta['failiem'] ?? [];
    if (!is_array($failiem)) {
        $failiem = [];
    }

    $fullHash = efpic_failiem_parse_folder_hash((string) ($failiem['folder_full_hash'] ?? ''))
        ?: efpic_failiem_parse_folder_hash((string) ($failiem['folder_full_url'] ?? ''));
    $webHash = efpic_failiem_parse_folder_hash((string) ($failiem['folder_web_hash'] ?? ''))
        ?: efpic_failiem_parse_folder_hash((string) ($failiem['folder_web_url'] ?? ''));

    if ($fullHash === '' || $webHash === '') {
        throw new InvalidArgumentException('Norādiet abas mapes (pilns + web)');
    }

    $strip = efpic_failiem_strip_suffixes($config, $failiem);
    $fullScan = efpic_failiem_scan_image_folder($config, $fullHash);
    $webScan = efpic_failiem_scan_image_folder($config, $webHash);
    $fullFiles = $fullScan['files'];
    $webFiles = $webScan['files'];
    $pairResult = efpic_failiem_pair_files($fullFiles, $webFiles, $strip);
    $orphansFull = array_merge($pairResult['orphans_full'], $pairResult['skipped_full'] ?? []);
    $orphansWeb = array_merge($pairResult['orphans_web'], $pairResult['skipped_web'] ?? []);

    $existingByKey = [];
    $existingByFullHash = [];
    $existingByWebHash = [];
    foreach ($meta['images'] ?? [] as $img) {
        if (!is_array($img)) {
            continue;
        }
        $key = (string) ($img['pair_key'] ?? '');
        if ($key !== '') {
            $existingByKey[$key] = $img;
        }
        $fullHash = (string) (($img['failiem_full']['file_hash'] ?? '') ?: '');
        $webHash = (string) (($img['failiem_web']['file_hash'] ?? '') ?: '');
        if ($fullHash !== '') {
            $existingByFullHash[$fullHash] = $img;
        }
        if ($webHash !== '') {
            $existingByWebHash[$webHash] = $img;
        }
    }

    $paired = $pairResult['paired'];
    usort($paired, static fn ($a, $b) => efpic_compare_image_basenames(
        ['basename' => (string) ($a['basename'] ?? '')],
        ['basename' => (string) ($b['basename'] ?? '')]
    ));

    $newImages = [];
    $newImageIndices = [];
    $forceDimRefresh = [];
    $usedPairKeys = [];
    $usedTokens = [];
    foreach ($paired as $pair) {
        $key = (string) $pair['key'];
        $fullHash = (string) ($pair['full']['hash'] ?? '');
        $webHash = (string) ($pair['web']['hash'] ?? '');
        $prev = null;
        if ($fullHash !== '' && isset($existingByFullHash[$fullHash])) {
            $prev = $existingByFullHash[$fullHash];
        } elseif ($webHash !== '' && isset($existingByWebHash[$webHash])) {
            $prev = $existingByWebHash[$webHash];
        } elseif ($key !== '' && !isset($usedPairKeys[$key])) {
            $prev = $existingByKey[$key] ?? null;
        }
        if ($key !== '') {
            $usedPairKeys[$key] = true;
        }
        $token = is_array($prev) ? (string) ($prev['token'] ?? '') : '';
        if ($token === '' || isset($usedTokens[$token])) {
            $token = efpic_random_hex(24);
        }
        $usedTokens[$token] = true;

        $entry = [
            'token' => $token,
            'sort' => is_array($prev) && !empty($prev['sort_manual']) ? (int) ($prev['sort'] ?? 0) : 0,
            'sort_manual' => is_array($prev) ? !empty($prev['sort_manual']) : false,
            'scene_id' => is_array($prev) ? (string) ($prev['scene_id'] ?? 'main') : 'main',
            'pair_key' => $key,
            'basename' => (string) $pair['basename'],
            'file' => '',
            'failiem_full' => [
                'file_hash' => (string) $pair['full']['hash'],
                'name' => (string) $pair['full']['name'],
                'size_bytes' => (int) $pair['full']['size_bytes'],
            ],
            'failiem_web' => [
                'file_hash' => (string) $pair['web']['hash'],
                'name' => (string) $pair['web']['name'],
                'size_bytes' => (int) $pair['web']['size_bytes'],
            ],
            'client_hidden' => is_array($prev) ? !empty($prev['client_hidden']) : false,
            'favorited_admin' => is_array($prev) ? !empty($prev['favorited_admin']) : false,
            'favorited_client' => is_array($prev)
                ? (!empty($prev['favorited_client']) || !empty($prev['favorited']))
                : false,
            'likes_count' => is_array($prev) ? (int) ($prev['likes_count'] ?? 0) : 0,
            'like_voters' => is_array($prev) && is_array($prev['like_voters'] ?? null) ? $prev['like_voters'] : [],
        ];
        if (is_array($prev)) {
            $newWebHash = (string) ($pair['web']['hash'] ?? '');
            $newFullHash = (string) ($pair['full']['hash'] ?? '');
            $newWebSize = (int) ($pair['web']['size_bytes'] ?? 0);
            $newFullSize = (int) ($pair['full']['size_bytes'] ?? 0);
            if (efpic_image_should_preserve_dimensions($prev, $newWebHash, $newFullHash, $newWebSize, $newFullSize)) {
                $entry['width'] = (int) ($prev['width'] ?? 0);
                $entry['height'] = (int) ($prev['height'] ?? 0);
                $entry['dimensions_source_key'] = $newWebHash . '|' . $newFullHash . '|' . $newWebSize . '|' . $newFullSize;
            } else {
                $forceDimRefresh[$token] = true;
            }
        } else {
            $newImageIndices[] = count($newImages);
            $forceDimRefresh[$token] = true;
        }
        $newImages[] = $entry;
    }

    $meta['images'] = $newImages;
    // Pārkārto pēc failu numura visā galerijā — vecās sort vērtības (arī hash-saistītām bildēm) var palikt beigās.
    efpic_rebaseline_all_scene_sorts_by_basename($meta, true);
    $meta['failiem']['folder_full_hash'] = $fullHash;
    $meta['failiem']['folder_web_hash'] = $webHash;
    $parentUrl = trim((string) ($meta['failiem']['folder_parent_url'] ?? ''));
    if ($parentUrl !== '') {
        $meta['failiem']['folder_parent_hash'] = efpic_failiem_parse_folder_hash($parentUrl);
    }
    $meta['failiem']['last_sync_at'] = gmdate('c');
    $meta['failiem']['sync_stats'] = [
        'paired' => count($newImages),
        'orphans_full' => count($orphansFull),
        'orphans_web' => count($orphansWeb),
        'full_count' => count($fullFiles),
        'web_count' => count($webFiles),
        'skipped_non_image_full' => (int) ($fullScan['skipped_non_image'] ?? 0),
        'skipped_non_image_web' => (int) ($webScan['skipped_non_image'] ?? 0),
        'orphan_full_names' => array_slice(array_values(array_map(
            static fn (array $f): string => (string) ($f['name'] ?? ''),
            $orphansFull,
        )), 0, 20),
        'orphan_web_names' => array_slice(array_values(array_map(
            static fn (array $f): string => (string) ($f['name'] ?? ''),
            $orphansWeb,
        )), 0, 20),
    ];

    $coverTok = trim((string) ($meta['cover_image_token'] ?? ''));
    if ($coverTok !== '') {
        $coverExists = false;
        foreach ($newImages as $img) {
            if (is_array($img) && ($img['token'] ?? '') === $coverTok) {
                $coverExists = true;
                break;
            }
        }
        if (!$coverExists) {
            $coverTok = '';
        }
    }
    if ($coverTok === '' && $newImages !== []) {
        $meta['cover_image_token'] = $newImages[0]['token'];
    }

    $videoSync = efpic_sync_delivery_videos($config, $meta);
    $meta['failiem']['sync_stats']['video_count'] = $videoSync['video_count'];

    efpic_save_gallery_meta($config, $slug, $meta);

    // Sync tikai saglabā pāri — izmērus ievāc servera rinda pēc redirect (nebloķē sync).
    $metaAfter = efpic_load_gallery_meta($config, $slug);
    $dimResult = [
        'updated' => 0,
        'reprobed' => 0,
        'backfilled' => 0,
        'stats' => efpic_gallery_image_dimensions_stats(is_array($metaAfter) ? $metaAfter : []),
        'pending_tokens' => count($forceDimRefresh),
    ];

    $warnings = [];
    if ($orphansFull !== []) {
        $msg = 'Pilnā mapē bez pāra: ' . count($orphansFull) . ' faili';
        $names = efpic_failiem_format_file_name_list($orphansFull);
        if ($names !== '') {
            $msg .= ' (' . $names . ')';
        }
        $warnings[] = $msg;
    }
    if ($orphansWeb !== []) {
        $msg = 'Web mapē bez pāra: ' . count($orphansWeb) . ' faili';
        $names = efpic_failiem_format_file_name_list($orphansWeb);
        if ($names !== '') {
            $msg .= ' (' . $names . ')';
        }
        $warnings[] = $msg;
    }
    if ((int) ($fullScan['skipped_non_image'] ?? 0) > 0 || (int) ($webScan['skipped_non_image'] ?? 0) > 0) {
        $warnings[] = 'Nav attēlu (API izlaida): pilns '
            . (int) ($fullScan['skipped_non_image'] ?? 0)
            . ', web '
            . (int) ($webScan['skipped_non_image'] ?? 0);
    }
    if (count($fullFiles) !== count($webFiles)) {
        $warnings[] = 'Mapēs atšķiras bilžu skaits: pilns ' . count($fullFiles) . ', web ' . count($webFiles);
    }
    $failiemTotal = max(count($fullFiles), count($webFiles));
    if ($failiemTotal > count($newImages)) {
        $warnings[] = ($failiemTotal - count($newImages))
            . ' Failiem faili nav pārī (pilns+web) — netika pievienoti galerijai';
    }
    if ($videoSync['removed'] > 0) {
        $warnings[] = 'Noņemti ' . $videoSync['removed'] . ' video, kas vairs nav Failiem mapē';
    }

    return [
        'ok' => true,
        'stats' => $meta['failiem']['sync_stats'],
        'warnings' => $warnings,
        'dimensions_backfilled' => $dimResult['updated'],
        'dimensions_reprobed' => $dimResult['reprobed'],
        'dimensions_stats' => $dimResult['stats'],
        'video_count' => $videoSync['video_count'],
    ];
}

/**
 * Sinhronizē video no Failiem mapes uz meta.videos (kind=failiem).
 *
 * @return array{video_count: int, removed: int}
 */
function efpic_sync_delivery_videos(array $config, array &$meta): array
{
    $videoFolderHash = efpic_failiem_video_folder_hash($meta);
    $manualVideos = [];
    $existingFailiem = [];
    foreach ($meta['videos'] ?? [] as $video) {
        if (!is_array($video)) {
            continue;
        }
        if (($video['kind'] ?? '') === 'failiem') {
            $hash = (string) ($video['failiem']['file_hash'] ?? '');
            if ($hash !== '') {
                $existingFailiem[$hash] = $video;
            }
        } else {
            $manualVideos[] = $video;
        }
    }

    if ($videoFolderHash === '') {
        $removed = count($existingFailiem);
        $meta['videos'] = $manualVideos;
        if (!is_array($meta['failiem'] ?? null)) {
            $meta['failiem'] = [];
        }
        $meta['failiem']['folder_video_hash'] = '';

        return ['video_count' => 0, 'removed' => $removed];
    }

    $videoFiles = efpic_failiem_list_video_folder($config, $videoFolderHash);
    if ($videoFiles !== []) {
        efpic_ensure_gallery_video_scene($meta);
    }

    usort($videoFiles, static fn ($a, $b) => strnatcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

    $syncedVideos = [];
    $sort = 10;
    foreach ($videoFiles as $file) {
        $hash = (string) ($file['hash'] ?? '');
        if ($hash === '') {
            continue;
        }
        $prev = $existingFailiem[$hash] ?? null;
        $id = is_array($prev) ? (string) ($prev['id'] ?? '') : '';
        if ($id === '') {
            $id = 'fv_' . efpic_random_hex(12);
        }
        $title = is_array($prev) ? trim((string) ($prev['title'] ?? '')) : '';
        if ($title === '') {
            $title = pathinfo((string) ($file['name'] ?? ''), PATHINFO_FILENAME);
        }
        $sceneId = is_array($prev) ? (string) ($prev['scene_id'] ?? 'video') : 'video';
        if ($sceneId === '') {
            $sceneId = 'video';
        }
        $entrySort = is_array($prev) ? (int) ($prev['sort'] ?? $sort) : $sort;
        $syncedVideos[] = [
            'id' => $id,
            'kind' => 'failiem',
            'failiem' => [
                'file_hash' => $hash,
                'name' => (string) ($file['name'] ?? ''),
                'size_bytes' => (int) ($file['size_bytes'] ?? 0),
                'mime_type' => (string) ($file['mime_type'] ?? 'video/mp4'),
            ],
            'title' => $title,
            'scene_id' => $sceneId,
            'sort' => $entrySort > 0 ? $entrySort : $sort,
        ];
        $sort += 10;
    }

    usort($syncedVideos, static fn ($a, $b) => ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0)));
    $sort = 10;
    foreach ($syncedVideos as $i => $video) {
        $syncedVideos[$i]['sort'] = $sort;
        $sort += 10;
    }

    $removed = max(0, count($existingFailiem) - count($syncedVideos));
    $meta['videos'] = array_merge($manualVideos, $syncedVideos);
    if (!is_array($meta['failiem'] ?? null)) {
        $meta['failiem'] = [];
    }
    $meta['failiem']['folder_video_hash'] = $videoFolderHash;

    return ['video_count' => count($syncedVideos), 'removed' => $removed];
}

function efpic_create_delivery_gallery(array $config, array $input): array
{
    $name = trim((string) ($input['name'] ?? 'Galerija'));
    $slug = trim((string) ($input['slug'] ?? ''));
    if ($slug === '') {
        $slug = efpic_slugify($name);
    }
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $slug)) {
        throw new InvalidArgumentException('Nederīgs slug');
    }

    $dir = efpic_gallery_dir($config, $slug);
    if (is_dir($dir)) {
        throw new RuntimeException('Galerija ar šo slug jau eksistē', 409);
    }

    $meta = efpic_gallery_defaults('delivery');
    $meta['name'] = $name;
    $meta['event_date'] = trim((string) ($input['event_date'] ?? '')) ?: null;
    $meta['theme'] = efpic_normalize_gallery_theme((string) ($input['theme'] ?? 'efpic-modern'));

    efpic_set_gallery_password($meta, (string) ($input['password'] ?? ''));

    $meta['failiem']['folder_parent_url'] = trim((string) ($input['folder_parent_url'] ?? ''));
    $meta['failiem']['folder_parent_hash'] = efpic_failiem_parse_folder_hash($meta['failiem']['folder_parent_url']);
    $meta['failiem']['folder_full_url'] = trim((string) ($input['folder_full_url'] ?? ''));
    $meta['failiem']['folder_web_url'] = trim((string) ($input['folder_web_url'] ?? ''));
    $meta['failiem']['folder_video_url'] = trim((string) ($input['folder_video_url'] ?? ''));
    $meta['failiem']['folder_full_hash'] = efpic_failiem_parse_folder_hash($meta['failiem']['folder_full_url']);
    $meta['failiem']['folder_web_hash'] = efpic_failiem_parse_folder_hash($meta['failiem']['folder_web_url']);
    $meta['failiem']['folder_video_hash'] = efpic_failiem_parse_folder_hash($meta['failiem']['folder_video_url']);
    $meta['failiem']['pair_suffix_strip'] = efpic_failiem_strip_suffixes($config);

    $meta['client_access']['email'] = trim((string) ($input['client_email'] ?? ''));
    $meta['client_access']['phone'] = trim((string) ($input['client_phone'] ?? ''));
    $meta['settings']['expires_at'] = efpic_gallery_default_expires_at();

    $enablePortal = !array_key_exists('client_portal_enabled', $input) || !empty($input['client_portal_enabled']);
    if ($enablePortal) {
        efpic_set_client_portal_password($meta, (string) ($input['client_password'] ?? ''));
    } else {
        efpic_disable_client_portal($meta);
    }

    if (!empty($input['scenes']) && is_array($input['scenes'])) {
        $meta['scenes'] = $input['scenes'];
    }

    mkdir($dir, 0755, true);
    efpic_save_gallery_meta($config, $slug, $meta);

    return ['slug' => $slug, 'meta' => $meta];
}

function efpic_update_delivery_image_order(array $config, string $slug, array $orderedTokens): void
{
    $meta = efpic_load_gallery_meta($config, $slug);
    if ($meta === null) {
        throw new RuntimeException('Galerija nav atrasta');
    }

    $byToken = [];
    foreach ($meta['images'] ?? [] as $img) {
        if (is_array($img) && ($img['token'] ?? '') !== '') {
            $byToken[$img['token']] = $img;
        }
    }

    $byScene = [];
    foreach ($orderedTokens as $tok) {
        $tok = (string) $tok;
        if ($tok === '' || !isset($byToken[$tok])) {
            continue;
        }
        $sid = (string) ($byToken[$tok]['scene_id'] ?? 'main');
        $byScene[$sid][] = $tok;
    }

    foreach ($byScene as $tokens) {
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            $byToken[$tok]['sort'] = ($i + 1) * 10;
            $byToken[$tok]['sort_manual'] = true;
        }
    }

    $newList = array_values($byToken);
    foreach ($meta['images'] ?? [] as $img) {
        if (!is_array($img)) {
            continue;
        }
        $tok = (string) ($img['token'] ?? '');
        if ($tok !== '' && !isset($byToken[$tok])) {
            $newList[] = $img;
        }
    }

    $meta['images'] = $newList;
    efpic_save_gallery_meta($config, $slug, $meta);
}

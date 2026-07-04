<?php

declare(strict_types=1);

require_once __DIR__ . '/failiem_client.php';
require_once __DIR__ . '/image_dimensions.php';

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
    $fullFiles = efpic_failiem_list_folder($config, $fullHash);
    $webFiles = efpic_failiem_list_folder($config, $webHash);
    $pairResult = efpic_failiem_pair_files($fullFiles, $webFiles, $strip);

    $existingByKey = [];
    foreach ($meta['images'] ?? [] as $img) {
        if (!is_array($img)) {
            continue;
        }
        $key = (string) ($img['pair_key'] ?? '');
        if ($key !== '') {
            $existingByKey[$key] = $img;
        }
    }

    $paired = $pairResult['paired'];
    usort($paired, static fn ($a, $b) => efpic_compare_image_basenames(
        ['basename' => (string) ($a['basename'] ?? '')],
        ['basename' => (string) ($b['basename'] ?? '')]
    ));

    $newImages = [];
    $forceDimRefresh = [];
    foreach ($paired as $pair) {
        $key = (string) $pair['key'];
        $prev = $existingByKey[$key] ?? null;
        $token = is_array($prev) ? (string) ($prev['token'] ?? '') : '';
        if ($token === '') {
            $token = efpic_random_hex(24);
        }

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
        }
        $newImages[] = $entry;
    }

    $meta['images'] = $newImages;
    efpic_reconcile_auto_scene_sorts($meta);
    $meta['failiem']['folder_full_hash'] = $fullHash;
    $meta['failiem']['folder_web_hash'] = $webHash;
    $parentUrl = trim((string) ($meta['failiem']['folder_parent_url'] ?? ''));
    if ($parentUrl !== '') {
        $meta['failiem']['folder_parent_hash'] = efpic_failiem_parse_folder_hash($parentUrl);
    }
    $meta['failiem']['last_sync_at'] = gmdate('c');
    $meta['failiem']['sync_stats'] = [
        'paired' => count($pairResult['paired']),
        'orphans_full' => count($pairResult['orphans_full']),
        'orphans_web' => count($pairResult['orphans_web']),
        'full_count' => count($fullFiles),
        'web_count' => count($webFiles),
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

    efpic_save_gallery_meta($config, $slug, $meta);

    // Pārrēķina izmērus visām bildēm, kur Failiem fails ir mainījies (bez partijas limita).
    @set_time_limit(180);
    $metaForDims = efpic_load_gallery_meta($config, $slug);
    $dimReprobed = 0;
    $dimUpdated = 0;
    if ($metaForDims !== null) {
        $dimReprobed = efpic_gallery_reprobe_changed_image_dimensions(
            $config,
            $slug,
            $metaForDims,
            $forceDimRefresh,
            true,
        );
        $metaForDims = efpic_load_gallery_meta($config, $slug) ?? $metaForDims;
        $dimUpdated = efpic_gallery_backfill_image_dimensions(
            $config,
            $slug,
            $metaForDims,
            EFPIC_DIMS_SYNC_BATCH,
            true,
            EFPIC_DIMS_BACKFILL_SAVE_EVERY,
            [],
        );
    }
    $metaAfter = efpic_load_gallery_meta($config, $slug);
    $dimResult = [
        'updated' => $dimReprobed + $dimUpdated,
        'reprobed' => $dimReprobed,
        'backfilled' => $dimUpdated,
        'stats' => efpic_gallery_image_dimensions_stats(is_array($metaAfter) ? $metaAfter : []),
    ];

    $warnings = [];
    if ($pairResult['orphans_full'] !== []) {
        $warnings[] = 'Pilnā mapē bez pāra: ' . count($pairResult['orphans_full']) . ' faili';
    }
    if ($pairResult['orphans_web'] !== []) {
        $warnings[] = 'Web mapē bez pāra: ' . count($pairResult['orphans_web']) . ' faili';
    }


    return [
        'ok' => true,
        'stats' => $meta['failiem']['sync_stats'],
        'warnings' => $warnings,
        'dimensions_backfilled' => $dimResult['updated'],
        'dimensions_reprobed' => $dimResult['reprobed'],
        'dimensions_stats' => $dimResult['stats'],
    ];
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
    $meta['failiem']['folder_full_hash'] = efpic_failiem_parse_folder_hash($meta['failiem']['folder_full_url']);
    $meta['failiem']['folder_web_hash'] = efpic_failiem_parse_folder_hash($meta['failiem']['folder_web_url']);
    $meta['failiem']['pair_suffix_strip'] = efpic_failiem_strip_suffixes($config);

    $meta['client_access']['email'] = trim((string) ($input['client_email'] ?? ''));
    $meta['client_access']['phone'] = trim((string) ($input['client_phone'] ?? ''));
    $meta['settings']['expires_at'] = efpic_gallery_default_expires_at();
    efpic_set_client_portal_password($meta, (string) ($input['client_password'] ?? ''));

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

<?php

declare(strict_types=1);

require_once __DIR__ . '/failiem_client.php';

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

    $newImages = [];
    $sort = 0;
    foreach ($pairResult['paired'] as $pair) {
        $sort++;
        $key = (string) $pair['key'];
        $prev = $existingByKey[$key] ?? null;
        $token = is_array($prev) ? (string) ($prev['token'] ?? '') : '';
        if ($token === '') {
            $token = efpic_random_hex(24);
        }

        $newImages[] = [
            'token' => $token,
            'sort' => is_array($prev) ? (int) ($prev['sort'] ?? $sort) : $sort,
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
            'favorited' => is_array($prev) ? !empty($prev['favorited']) : false,
        ];
    }

    usort($newImages, static fn ($a, $b) => ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0)));

    $meta['images'] = $newImages;
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

    if (($meta['cover_image_token'] ?? '') === '' && $newImages !== []) {
        $meta['cover_image_token'] = $newImages[0]['token'];
    }

    efpic_save_gallery_meta($config, $slug, $meta);

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
    $meta['theme'] = (string) ($input['theme'] ?? 'classic');

    $pass = (string) ($input['password'] ?? '');
    if ($pass !== '') {
        $meta['password_hash'] = efpic_hash_password($pass);
    }

    $meta['failiem']['folder_parent_url'] = trim((string) ($input['folder_parent_url'] ?? ''));
    $meta['failiem']['folder_parent_hash'] = efpic_failiem_parse_folder_hash($meta['failiem']['folder_parent_url']);
    $meta['failiem']['folder_full_url'] = trim((string) ($input['folder_full_url'] ?? ''));
    $meta['failiem']['folder_web_url'] = trim((string) ($input['folder_web_url'] ?? ''));
    $meta['failiem']['folder_full_hash'] = efpic_failiem_parse_folder_hash($meta['failiem']['folder_full_url']);
    $meta['failiem']['folder_web_hash'] = efpic_failiem_parse_folder_hash($meta['failiem']['folder_web_url']);
    $meta['failiem']['pair_suffix_strip'] = efpic_failiem_strip_suffixes($config);

    $meta['client_access']['email'] = trim((string) ($input['client_email'] ?? ''));

    $clientPass = (string) ($input['client_password'] ?? '');
    if ($clientPass !== '') {
        $meta['client_access']['password_hash'] = efpic_hash_password($clientPass);
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

    $sort = 0;
    $newList = [];
    foreach ($orderedTokens as $tok) {
        $tok = (string) $tok;
        if ($tok === '' || !isset($byToken[$tok])) {
            continue;
        }
        $sort++;
        $row = $byToken[$tok];
        $row['sort'] = $sort;
        $newList[] = $row;
        unset($byToken[$tok]);
    }

    foreach ($byToken as $row) {
        $sort++;
        $row['sort'] = $sort;
        $newList[] = $row;
    }

    $meta['images'] = $newList;
    efpic_save_gallery_meta($config, $slug, $meta);
}

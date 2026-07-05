<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/failiem_client.php';
require_once __DIR__ . '/gallery_access.php';

const EFPIC_FAILIEM_FACE_CACHE_SEC = 3600;

/** @return array<string, mixed> */
function efpic_gallery_face_search_defaults(): array
{
    return [
        'enabled' => false,
        'provider' => 'failiem',
        'failiem_upload_hash' => '',
        'status' => 'none',
        'error' => '',
    ];
}

/** @return array<string, mixed> */
function efpic_gallery_face_search(array $meta): array
{
    $fs = $meta['face_search'] ?? null;
    $merged = is_array($fs) ? array_merge(efpic_gallery_face_search_defaults(), $fs) : efpic_gallery_face_search_defaults();
    $merged['provider'] = 'failiem';

    return $merged;
}

function efpic_gallery_face_search_enabled(array $meta): bool
{
    return !empty(efpic_gallery_face_search($meta)['enabled']);
}

function efpic_gallery_face_search_uses_failiem(array $meta): bool
{
    return efpic_gallery_face_search_enabled($meta);
}

/** Notīra vecās Synology face worker rindas (vairs netiek izmantotas). */
function efpic_face_legacy_queue_purge(array $config): int
{
    $base = dirname(efpic_storage_path($config)) . DIRECTORY_SEPARATOR . 'face_queue';
    if (!is_dir($base)) {
        return 0;
    }
    $removed = 0;
    foreach (glob($base . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
        if (@unlink($path)) {
            $removed++;
        }
    }
    foreach (glob($base . DIRECTORY_SEPARATOR . '*.selfie.jpg') ?: [] as $path) {
        @unlink($path);
    }
    @unlink(dirname(efpic_storage_path($config)) . DIRECTORY_SEPARATOR . 'face_worker_state.json');

    return $removed;
}

function efpic_failiem_face_site_base(array $config): string
{
    $f = efpic_failiem_cfg($config);
    $base = (string) ($f['face_site_base'] ?? $f['cdn_base'] ?? 'https://failiem.lv');

    return rtrim($base, '/');
}

function efpic_failiem_face_upload_hash(array $meta): string
{
    $fs = efpic_gallery_face_search($meta);
    $override = efpic_failiem_parse_folder_hash((string) ($fs['failiem_upload_hash'] ?? ''));
    if ($override !== '') {
        return $override;
    }

    return efpic_failiem_delivery_folder_hash($meta, 'web');
}

function efpic_failiem_face_cache_path(array $config, string $slug): string
{
    return efpic_gallery_dir($config, $slug) . DIRECTORY_SEPARATOR . 'failiem_face_cache.json';
}

/** @return array<string, mixed>|null */
function efpic_failiem_face_load_cache(array $config, string $slug): ?array
{
    $data = efpic_read_json_file(efpic_failiem_face_cache_path($config, $slug));
    if ($data === null || !is_array($data)) {
        return null;
    }
    $fetchedAt = strtotime((string) ($data['fetched_at'] ?? ''));
    if ($fetchedAt === false || (time() - $fetchedAt) > EFPIC_FAILIEM_FACE_CACHE_SEC) {
        return null;
    }

    return $data;
}

/** @param array<string, mixed> $payload */
function efpic_failiem_face_save_cache(array $config, string $slug, array $payload): void
{
    $payload['fetched_at'] = gmdate('c');
    efpic_write_json_file(efpic_failiem_face_cache_path($config, $slug), $payload);
}

/** @return array<string, mixed> */
function efpic_failiem_face_ajax_get(array $config, string $uploadHash, string $action, array $extra = []): array
{
    $uploadHash = efpic_failiem_parse_folder_hash($uploadHash);
    if ($uploadHash === '') {
        throw new InvalidArgumentException('Nederīgs Failiem mapes hash');
    }

    $query = array_merge(['upload_hash' => $uploadHash, 'action' => $action], $extra);
    $url = efpic_failiem_face_site_base($config) . '/ajax/face_search.php?' . http_build_query($query);

    if (!function_exists('curl_init')) {
        throw new RuntimeException('curl nav pieejams');
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl init failed');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: EFPIC-Gallery/1.0',
        ],
    ]);

    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code < 200 || $code >= 300) {
        throw new RuntimeException('Failiem sejas HTTP ' . $code . ($err !== '' ? ': ' . $err : ''));
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('Failiem seju atbilde nav JSON');
    }

    return $data;
}

/** @return array<string, mixed> */
function efpic_failiem_face_check_status(array $config, string $uploadHash): array
{
    return efpic_failiem_face_ajax_get($config, $uploadHash, 'check_status');
}

/** @return array<string, mixed> */
function efpic_failiem_face_get_results(array $config, string $uploadHash, bool $extended = false): array
{
    $extra = $extended ? ['extended' => 'true'] : [];

    return efpic_failiem_face_ajax_get($config, $uploadHash, 'get_results', $extra);
}

function efpic_failiem_face_thumb_url(
    array $config,
    string $fileHash,
    string $faceId,
    string $fileName = ''
): string {
    $url = efpic_failiem_face_site_base($config)
        . '/thumb_face.php?i=' . rawurlencode($fileHash)
        . '&f=' . rawurlencode($faceId);
    if ($fileName !== '') {
        $url .= '&n=' . rawurlencode($fileName);
    }

    return $url;
}

/** @return array<string, string> failiem file hash => efpic image token */
function efpic_failiem_face_hash_to_token_map(array $meta, array $ctx): array
{
    $map = [];
    foreach (efpic_client_navigable_images($meta, $ctx) as $img) {
        if (!is_array($img)) {
            continue;
        }
        $tok = (string) ($img['token'] ?? '');
        $hash = efpic_delivery_file_hash($img, 'web');
        if ($tok !== '' && $hash !== '') {
            $map[$hash] = $tok;
        }
    }

    return $map;
}

/**
 * @return array{
 *   ok: bool,
 *   upload_hash: string,
 *   processing_done: bool,
 *   processing_error: bool,
 *   persons: list<array<string, mixed>>,
 *   person_images: array<string, list<int|string>>,
 *   person_images_ids_hashes: array<string, string>,
 *   from_cache: bool
 * }
 */
function efpic_failiem_face_fetch_bundle(
    array $config,
    string $slug,
    array $meta,
    bool $forceRefresh = false
): array {
    $uploadHash = efpic_failiem_face_upload_hash($meta);
    if ($uploadHash === '') {
        return [
            'ok' => false,
            'upload_hash' => '',
            'processing_done' => false,
            'processing_error' => false,
            'persons' => [],
            'person_images' => [],
            'person_images_ids_hashes' => [],
            'from_cache' => false,
            'error' => 'Nav norādīta Failiem web mape',
        ];
    }

    if (!$forceRefresh) {
        $cached = efpic_failiem_face_load_cache($config, $slug);
        if ($cached !== null && (string) ($cached['upload_hash'] ?? '') === $uploadHash) {
            return [
                'ok' => true,
                'upload_hash' => $uploadHash,
                'processing_done' => !empty($cached['processing_done']),
                'processing_error' => !empty($cached['processing_error']),
                'persons' => is_array($cached['persons'] ?? null) ? $cached['persons'] : [],
                'person_images' => is_array($cached['person_images'] ?? null) ? $cached['person_images'] : [],
                'person_images_ids_hashes' => is_array($cached['person_images_ids_hashes'] ?? null)
                    ? $cached['person_images_ids_hashes'] : [],
                'from_cache' => true,
            ];
        }
    }

    try {
        $status = efpic_failiem_face_check_status($config, $uploadHash);
        $processingDone = !empty($status['processing_done']);
        $processingError = !empty($status['processing_error']);

        $persons = [];
        $personImages = [];
        $personImagesIdsHashes = [];

        if ($processingDone && !$processingError) {
            $results = efpic_failiem_face_get_results($config, $uploadHash);
            if (!empty($results['success']) && empty($results['no_persons_detected'])) {
                $persons = is_array($results['persons'] ?? null) ? $results['persons'] : [];
                $personImages = is_array($results['person_images'] ?? null) ? $results['person_images'] : [];
                $personImagesIdsHashes = is_array($results['person_images_ids_hashes'] ?? null)
                    ? $results['person_images_ids_hashes'] : [];
            }
        }

        efpic_failiem_face_save_cache($config, $slug, [
            'upload_hash' => $uploadHash,
            'processing_done' => $processingDone,
            'processing_error' => $processingError,
            'persons' => $persons,
            'person_images' => $personImages,
            'person_images_ids_hashes' => $personImagesIdsHashes,
        ]);

        return [
            'ok' => true,
            'upload_hash' => $uploadHash,
            'processing_done' => $processingDone,
            'processing_error' => $processingError,
            'persons' => $persons,
            'person_images' => $personImages,
            'person_images_ids_hashes' => $personImagesIdsHashes,
            'from_cache' => false,
        ];
    } catch (Throwable $e) {
        $cached = efpic_read_json_file(efpic_failiem_face_cache_path($config, $slug));
        if (is_array($cached) && (string) ($cached['upload_hash'] ?? '') === $uploadHash) {
            return [
                'ok' => true,
                'upload_hash' => $uploadHash,
                'processing_done' => !empty($cached['processing_done']),
                'processing_error' => !empty($cached['processing_error']),
                'persons' => is_array($cached['persons'] ?? null) ? $cached['persons'] : [],
                'person_images' => is_array($cached['person_images'] ?? null) ? $cached['person_images'] : [],
                'person_images_ids_hashes' => is_array($cached['person_images_ids_hashes'] ?? null)
                    ? $cached['person_images_ids_hashes'] : [],
                'from_cache' => true,
                'error' => $e->getMessage(),
            ];
        }

        return [
            'ok' => false,
            'upload_hash' => $uploadHash,
            'processing_done' => false,
            'processing_error' => true,
            'persons' => [],
            'person_images' => [],
            'person_images_ids_hashes' => [],
            'from_cache' => false,
            'error' => $e->getMessage(),
        ];
    }
}

/** @return array<string, mixed> */
function efpic_failiem_face_cached_status(array $config, string $slug, array $meta): array
{
    $uploadHash = efpic_failiem_face_upload_hash($meta);
    if ($uploadHash === '') {
        return [
            'provider' => 'failiem',
            'upload_hash' => '',
            'processing_done' => false,
            'processing_error' => false,
            'person_count' => 0,
            'from_cache' => false,
            'ready' => false,
            'error' => 'Nav norādīta Failiem web mape',
        ];
    }

    $cached = efpic_failiem_face_load_cache($config, $slug);
    if ($cached === null || (string) ($cached['upload_hash'] ?? '') !== $uploadHash) {
        $stale = efpic_read_json_file(efpic_failiem_face_cache_path($config, $slug));
        if (is_array($stale) && (string) ($stale['upload_hash'] ?? '') === $uploadHash) {
            $cached = $stale;
        }
    }

    $personCount = is_array($cached) && is_array($cached['persons'] ?? null) ? count($cached['persons']) : 0;

    return [
        'provider' => 'failiem',
        'upload_hash' => $uploadHash,
        'processing_done' => is_array($cached) && !empty($cached['processing_done']),
        'processing_error' => is_array($cached) && !empty($cached['processing_error']),
        'person_count' => $personCount,
        'from_cache' => true,
        'ready' => is_array($cached)
            && !empty($cached['processing_done'])
            && $personCount > 0
            && empty($cached['processing_error']),
        'error' => is_array($cached) ? '' : 'Vēl nav ielādēts — spied «Atsvaidzināt no Failiem»',
    ];
}

/**
 * @param list<string|int> $personIds
 * @return list<string>
 */
function efpic_failiem_face_tokens_for_persons(
    array $meta,
    array $ctx,
    array $personIds,
    array $personImages,
    array $personImagesIdsHashes
): array {
    $hashMap = efpic_failiem_face_hash_to_token_map($meta, $ctx);
    $tokens = [];

    foreach ($personIds as $personId) {
        foreach (efpic_failiem_face_navigable_tokens_for_person(
            (string) $personId,
            $personImages,
            $personImagesIdsHashes,
            $hashMap
        ) as $tok) {
            $tokens[$tok] = true;
        }
    }

    return array_keys($tokens);
}

/**
 * @param array<string, string> $hashMap failiem file hash => efpic image token
 * @return list<string>
 */
function efpic_failiem_face_navigable_tokens_for_person(
    string $personId,
    array $personImages,
    array $personImagesIdsHashes,
    array $hashMap
): array {
    $tokens = [];
    $imageIds = $personImages[$personId] ?? null;
    if (!is_array($imageIds)) {
        return [];
    }
    foreach ($imageIds as $imageId) {
        $hash = (string) ($personImagesIdsHashes[(string) $imageId] ?? '');
        if ($hash !== '' && isset($hashMap[$hash])) {
            $tokens[$hashMap[$hash]] = true;
        }
    }

    return array_keys($tokens);
}

/**
 * @return list<string>
 */
function efpic_failiem_face_tokens_without_faces(
    array $meta,
    array $ctx,
    array $personImages,
    array $personImagesIdsHashes,
): array {
    $allTokens = [];
    foreach (efpic_client_navigable_images($meta, $ctx) as $img) {
        if (!is_array($img)) {
            continue;
        }
        $tok = (string) ($img['token'] ?? '');
        if ($tok !== '') {
            $allTokens[$tok] = true;
        }
    }

    $hashMap = efpic_failiem_face_hash_to_token_map($meta, $ctx);
    $faceTokens = [];
    foreach (array_keys($personImages) as $personId) {
        foreach (efpic_failiem_face_navigable_tokens_for_person(
            (string) $personId,
            $personImages,
            $personImagesIdsHashes,
            $hashMap
        ) as $tok) {
            $faceTokens[$tok] = true;
        }
    }

    $out = [];
    foreach (array_keys($allTokens) as $tok) {
        if (!isset($faceTokens[$tok])) {
            $out[] = $tok;
        }
    }

    return $out;
}

/**
 * @return array{
 *   ok: bool,
 *   ready: bool,
 *   processing_done: bool,
 *   persons: list<array{id: string, thumb_url: string, photo_count: int, sample_file: string}>
 * }
 */
function efpic_failiem_face_public_persons(
    array $config,
    string $slug,
    array $meta,
    array $ctx,
    bool $forceRefresh = false
): array {
    $bundle = efpic_failiem_face_fetch_bundle($config, $slug, $meta, $forceRefresh);
    if (empty($bundle['ok'])) {
        return [
            'ok' => false,
            'ready' => false,
            'processing_done' => false,
            'error' => (string) ($bundle['error'] ?? 'failiem_error'),
            'persons' => [],
        ];
    }

    $out = [];
    $hashMap = efpic_failiem_face_hash_to_token_map($meta, $ctx);
    $personImages = is_array($bundle['person_images'] ?? null) ? $bundle['person_images'] : [];
    $personImagesIdsHashes = is_array($bundle['person_images_ids_hashes'] ?? null)
        ? $bundle['person_images_ids_hashes'] : [];
    foreach ($bundle['persons'] as $person) {
        if (!is_array($person)) {
            continue;
        }
        $personId = (string) ($person['person_id'] ?? '');
        if ($personId === '') {
            continue;
        }
        $navigableCount = count(efpic_failiem_face_navigable_tokens_for_person(
            $personId,
            $personImages,
            $personImagesIdsHashes,
            $hashMap
        ));
        if ($navigableCount === 0) {
            continue;
        }
        $out[] = [
            'id' => $personId,
            'thumb_url' => efpic_failiem_face_thumb_url(
                $config,
                (string) ($person['file_hash'] ?? ''),
                (string) ($person['face_id'] ?? ''),
                (string) ($person['file_name'] ?? '')
            ),
            'photo_count' => $navigableCount,
            'sample_file' => (string) ($person['file_name'] ?? ''),
        ];
    }

    usort($out, static fn ($a, $b) => ($b['photo_count'] ?? 0) <=> ($a['photo_count'] ?? 0));

    return [
        'ok' => true,
        'ready' => !empty($bundle['processing_done']) && $out !== [],
        'processing_done' => !empty($bundle['processing_done']),
        'processing_error' => !empty($bundle['processing_error']),
        'upload_hash' => (string) ($bundle['upload_hash'] ?? ''),
        'from_cache' => !empty($bundle['from_cache']),
        'persons' => $out,
    ];
}

/** @return array<string, mixed> */
function efpic_failiem_face_admin_status(array $config, string $slug, array $meta): array
{
    $bundle = efpic_failiem_face_fetch_bundle($config, $slug, $meta);
    $personCount = is_array($bundle['persons'] ?? null) ? count($bundle['persons']) : 0;

    return [
        'provider' => 'failiem',
        'upload_hash' => (string) ($bundle['upload_hash'] ?? ''),
        'processing_done' => !empty($bundle['processing_done']),
        'processing_error' => !empty($bundle['processing_error']),
        'person_count' => $personCount,
        'from_cache' => !empty($bundle['from_cache']),
        'ready' => !empty($bundle['processing_done']) && $personCount > 0 && empty($bundle['processing_error']),
    ];
}

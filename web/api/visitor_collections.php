<?php

declare(strict_types=1);

require_once __DIR__ . '/gallery_activity.php';
require_once __DIR__ . '/gallery_notifications.php';
require_once __DIR__ . '/zip_build.php';
require_once __DIR__ . '/guest_delivery_handlers.php';

/** @return array{visitors: array<string, array<string, mixed>>, email_index: array<string, string>, collections: array<string, array<string, mixed>>, zip_downloads: array<string, array<string, mixed>>} */
function efpic_visitor_collections_defaults(): array
{
    return [
        'visitors' => [],
        'email_index' => [],
        'collections' => [],
        'zip_downloads' => [],
    ];
}

function efpic_visitor_collections_path(array $config, string $slug): string
{
    return efpic_gallery_dir($config, $slug) . DIRECTORY_SEPARATOR . 'visitor_collections.json';
}

function efpic_visitor_zips_dir(array $config, string $slug): string
{
    return efpic_gallery_dir($config, $slug) . DIRECTORY_SEPARATOR . 'visitor_zips';
}

/** @return array<string, mixed> */
function efpic_visitor_collections_load(array $config, string $slug): array
{
    $data = efpic_read_json_file(efpic_visitor_collections_path($config, $slug));
    if (!is_array($data)) {
        return efpic_visitor_collections_defaults();
    }

    return array_merge(efpic_visitor_collections_defaults(), $data);
}

/** @param array<string, mixed> $data */
function efpic_visitor_collections_save(array $config, string $slug, array $data): void
{
    efpic_write_json_file(efpic_visitor_collections_path($config, $slug), $data);
}

function efpic_visitor_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function efpic_visitor_session_key(string $galleryToken): string
{
    efpic_client_session_start();
    if (!isset($_SESSION['efpic_visitor']) || !is_array($_SESSION['efpic_visitor'])) {
        $_SESSION['efpic_visitor'] = [];
    }

    return $galleryToken;
}

function efpic_visitor_set_session(string $galleryToken, string $visitorId, string $collectionId): void
{
    efpic_client_session_start();
    if (!isset($_SESSION['efpic_visitor']) || !is_array($_SESSION['efpic_visitor'])) {
        $_SESSION['efpic_visitor'] = [];
    }
    $_SESSION['efpic_visitor'][$galleryToken] = [
        'visitor_id' => $visitorId,
        'active_collection_id' => $collectionId,
    ];
}

/** @return array{visitor_id: string, active_collection_id: string}|null */
function efpic_visitor_session_state(string $galleryToken): ?array
{
    efpic_client_session_start();
    $row = $_SESSION['efpic_visitor'][$galleryToken] ?? null;
    if (!is_array($row)) {
        return null;
    }
    $visitorId = (string) ($row['visitor_id'] ?? '');
    $collectionId = (string) ($row['active_collection_id'] ?? '');
    if ($visitorId === '' || $collectionId === '') {
        return null;
    }

    return [
        'visitor_id' => $visitorId,
        'active_collection_id' => $collectionId,
    ];
}

function efpic_visitor_apply_access_token(array $config, string $galleryToken, string $accessToken): bool
{
    $accessToken = trim($accessToken);
    if ($accessToken === '') {
        return false;
    }
    $found = efpic_find_gallery_by_token($config, $galleryToken);
    if ($found === null) {
        return false;
    }
    $data = efpic_visitor_collections_load($config, $found['slug']);
    foreach ($data['visitors'] as $visitor) {
        if (!is_array($visitor)) {
            continue;
        }
        if (!hash_equals((string) ($visitor['access_token'] ?? ''), $accessToken)) {
            continue;
        }
        $visitorId = (string) ($visitor['id'] ?? '');
        if ($visitorId === '') {
            return false;
        }
        $collectionId = efpic_visitor_latest_collection_id($data, $visitorId);
        if ($collectionId === '') {
            return false;
        }
        efpic_visitor_set_session($galleryToken, $visitorId, $collectionId);

        return true;
    }

    return false;
}

/** @param array<string, mixed> $data */
function efpic_visitor_latest_collection_id(array $data, string $visitorId): string
{
    $latest = '';
    $latestTs = '';
    foreach ($data['collections'] as $collection) {
        if (!is_array($collection) || (string) ($collection['visitor_id'] ?? '') !== $visitorId) {
            continue;
        }
        $updated = (string) ($collection['updated_at'] ?? $collection['created_at'] ?? '');
        if ($latest === '' || $updated >= $latestTs) {
            $latest = (string) ($collection['id'] ?? '');
            $latestTs = $updated;
        }
    }

    return $latest;
}

/** @param array<string, mixed> $data */
function efpic_visitor_get_visitor(array $data, string $visitorId): ?array
{
    $visitor = $data['visitors'][$visitorId] ?? null;

    return is_array($visitor) ? $visitor : null;
}

/** @param array<string, mixed> $data */
function efpic_visitor_get_collection(array $data, string $collectionId): ?array
{
    $collection = $data['collections'][$collectionId] ?? null;

    return is_array($collection) ? $collection : null;
}

/**
 * @param array<string, mixed> $data
 * @return list<array<string, mixed>>
 */
function efpic_visitor_collections_for_visitor(array $data, string $visitorId): array
{
    $out = [];
    foreach ($data['collections'] as $collection) {
        if (!is_array($collection) || (string) ($collection['visitor_id'] ?? '') !== $visitorId) {
            continue;
        }
        $out[] = $collection;
    }
    usort($out, static fn ($a, $b) => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));

    return $out;
}

/**
 * @return array{visitor: array<string, mixed>, collections: list<array<string, mixed>>, active_collection_id: string}
 */
function efpic_visitor_identify(
    array $config,
    string $slug,
    array $meta,
    string $galleryToken,
    string $name,
    string $email,
    ?string $collectionName = null,
    bool $createCollection = true,
): array {
    $name = trim($name);
    $emailNorm = efpic_visitor_normalize_email($email);
    if ($name === '' || $emailNorm === '' || !filter_var($emailNorm, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Nepieciešams derīgs vārds un e-pasts');
    }

    $data = efpic_visitor_collections_load($config, $slug);
    $visitorId = (string) ($data['email_index'][$emailNorm] ?? '');
    $isNew = false;
    if ($visitorId === '' || !isset($data['visitors'][$visitorId])) {
        $isNew = true;
        $visitorId = 'v_' . efpic_random_hex(12);
        $data['visitors'][$visitorId] = [
            'id' => $visitorId,
            'name' => $name,
            'email' => $emailNorm,
            'access_token' => efpic_random_hex(24),
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $data['email_index'][$emailNorm] = $visitorId;
    } else {
        $data['visitors'][$visitorId]['name'] = $name;
        $data['visitors'][$visitorId]['updated_at'] = gmdate('c');
    }

    $collections = efpic_visitor_collections_for_visitor($data, $visitorId);
    $activeCollectionId = '';
    if ($createCollection) {
        $label = trim((string) $collectionName);
        if ($label === '') {
            $label = $collections === [] ? 'Mana izlase' : ('Izlase ' . (count($collections) + 1));
        }
        $activeCollectionId = efpic_visitor_create_collection_record($data, $visitorId, $label);
    } else {
        $activeCollectionId = efpic_visitor_latest_collection_id($data, $visitorId);
        if ($activeCollectionId === '' && $collections !== []) {
            $activeCollectionId = (string) ($collections[0]['id'] ?? '');
        }
    }

    if ($activeCollectionId === '') {
        $activeCollectionId = efpic_visitor_create_collection_record($data, $visitorId, 'Mana izlase');
    }

    efpic_visitor_collections_save($config, $slug, $data);
    efpic_visitor_set_session($galleryToken, $visitorId, $activeCollectionId);

    $visitor = $data['visitors'][$visitorId];
    if (empty($visitor['continue_email_sent'])) {
        efpic_visitor_send_continue_email($config, $meta, $slug, $galleryToken, $visitor, $isNew);
        $data['visitors'][$visitorId]['continue_email_sent'] = true;
        $data['visitors'][$visitorId]['updated_at'] = gmdate('c');
        efpic_visitor_collections_save($config, $slug, $data);
    }

    $message = $isNew
        ? 'Apmeklētājs reģistrēts: ' . $name . ' (' . $emailNorm . ')'
        : 'Apmeklētājs atgriezās: ' . $name . ' (' . $emailNorm . ')';
    efpic_gallery_log_activity($config, $slug, $meta, 'visitor_collection_identify', $message, 'visitor:' . $emailNorm, [
        'visitor_id' => $visitorId,
        'collection_id' => $activeCollectionId,
    ]);

    return [
        'visitor' => $data['visitors'][$visitorId],
        'collections' => efpic_visitor_collections_for_visitor($data, $visitorId),
        'active_collection_id' => $activeCollectionId,
    ];
}

/** @param array<string, mixed> $data */
function efpic_visitor_create_collection_record(array &$data, string $visitorId, string $name): string
{
    $collectionId = 'c_' . efpic_random_hex(12);
    $now = gmdate('c');
    $data['collections'][$collectionId] = [
        'id' => $collectionId,
        'visitor_id' => $visitorId,
        'name' => $name,
        'image_tokens' => [],
        'created_at' => $now,
        'updated_at' => $now,
    ];

    return $collectionId;
}

/**
 * @return array{collection: array<string, mixed>}
 */
function efpic_visitor_collection_rename(
    array $config,
    string $slug,
    array &$meta,
    string $visitorId,
    string $collectionId,
    string $name,
): array {
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('Izlases nosaukums nedrīkst būt tukšs');
    }
    $data = efpic_visitor_collections_load($config, $slug);
    $collection = efpic_visitor_get_collection($data, $collectionId);
    $visitor = efpic_visitor_get_visitor($data, $visitorId);
    if ($collection === null || $visitor === null || (string) ($collection['visitor_id'] ?? '') !== $visitorId) {
        throw new RuntimeException('Izlase nav atrasta');
    }
    $oldName = (string) ($collection['name'] ?? '');
    $data['collections'][$collectionId]['name'] = $name;
    $data['collections'][$collectionId]['updated_at'] = gmdate('c');
    efpic_visitor_collections_save($config, $slug, $data);
    efpic_gallery_log_activity(
        $config,
        $slug,
        $meta,
        'visitor_collection_rename',
        ($visitor['name'] ?? '') . ' pārsauca izlasi «' . $oldName . '» par «' . $name . '»',
        'visitor:' . ($visitor['email'] ?? ''),
        ['visitor_id' => $visitorId, 'collection_id' => $collectionId],
    );

    return ['collection' => $data['collections'][$collectionId]];
}

/**
 * @return array{collection: array<string, mixed>, in_collection: bool, count: int}
 */
function efpic_visitor_collection_toggle(
    array $config,
    string $slug,
    array &$meta,
    string $galleryToken,
    string $visitorId,
    string $collectionId,
    string $imageToken,
): array {
    $data = efpic_visitor_collections_load($config, $slug);
    $collection = efpic_visitor_get_collection($data, $collectionId);
    $visitor = efpic_visitor_get_visitor($data, $visitorId);
    if ($collection === null || $visitor === null || (string) ($collection['visitor_id'] ?? '') !== $visitorId) {
        throw new RuntimeException('Izlase nav atrasta');
    }

    $tokens = is_array($collection['image_tokens'] ?? null) ? $collection['image_tokens'] : [];
    $tokens = array_values(array_filter(array_map('strval', $tokens), static fn ($t) => $t !== ''));
    $idx = array_search($imageToken, $tokens, true);
    if ($idx !== false) {
        array_splice($tokens, $idx, 1);
        $in = false;
        $action = 'noņēma';
    } else {
        $tokens[] = $imageToken;
        $in = true;
        $action = 'pievienoja';
    }
    $data['collections'][$collectionId]['image_tokens'] = $tokens;
    $data['collections'][$collectionId]['updated_at'] = gmdate('c');
    efpic_visitor_collections_save($config, $slug, $data);
    efpic_visitor_set_session($galleryToken, $visitorId, $collectionId);
    efpic_gallery_log_activity(
        $config,
        $slug,
        $meta,
        $in ? 'visitor_collection_add' : 'visitor_collection_remove',
        $visitor['name'] . ' ' . $action . ' bildi izlasei «' . ($collection['name'] ?? '') . '»',
        'visitor:' . ($visitor['email'] ?? ''),
        ['visitor_id' => $visitorId, 'collection_id' => $collectionId, 'image_token' => $imageToken],
    );

    return [
        'collection' => $data['collections'][$collectionId],
        'in_collection' => $in,
        'count' => count($tokens),
    ];
}

/**
 * @param list<string> $imageTokens
 * @return array{collection: array<string, mixed>, added: int, count: int}
 */
function efpic_visitor_collection_add_tokens(
    array $config,
    string $slug,
    array &$meta,
    string $galleryToken,
    string $visitorId,
    string $collectionId,
    array $imageTokens,
): array {
    $data = efpic_visitor_collections_load($config, $slug);
    $collection = efpic_visitor_get_collection($data, $collectionId);
    $visitor = efpic_visitor_get_visitor($data, $visitorId);
    if ($collection === null || $visitor === null || (string) ($collection['visitor_id'] ?? '') !== $visitorId) {
        throw new RuntimeException('Izlase nav atrasta');
    }

    $existing = is_array($collection['image_tokens'] ?? null) ? $collection['image_tokens'] : [];
    $existing = array_values(array_filter(array_map('strval', $existing), static fn ($t) => $t !== ''));
    $lookup = array_fill_keys($existing, true);
    $added = 0;
    foreach ($imageTokens as $token) {
        $token = (string) $token;
        if ($token === '' || isset($lookup[$token])) {
            continue;
        }
        $lookup[$token] = true;
        $existing[] = $token;
        $added++;
    }

    $data['collections'][$collectionId]['image_tokens'] = $existing;
    $data['collections'][$collectionId]['updated_at'] = gmdate('c');
    efpic_visitor_collections_save($config, $slug, $data);
    efpic_visitor_set_session($galleryToken, $visitorId, $collectionId);
    if ($added > 0) {
        efpic_gallery_log_activity(
            $config,
            $slug,
            $meta,
            'visitor_collection_add',
            $visitor['name'] . ' pievienoja ' . $added . ' bildes izlasei «' . ($collection['name'] ?? '') . '»',
            'visitor:' . ($visitor['email'] ?? ''),
            ['visitor_id' => $visitorId, 'collection_id' => $collectionId, 'added' => $added],
        );
    }

    return [
        'collection' => $data['collections'][$collectionId],
        'added' => $added,
        'count' => count($existing),
    ];
}

function efpic_visitor_face_collection_name(): string
{
    return 'Sejas izlase';
}

/**
 * @param list<string> $imageTokens
 * @return array{collection: array<string, mixed>, count: int}
 */
function efpic_visitor_create_face_collection(
    array $config,
    string $slug,
    array &$meta,
    array $ctx,
    string $galleryToken,
    string $visitorId,
    array $imageTokens,
): array {
    $allowed = [];
    foreach (efpic_client_navigable_images($meta, $ctx) as $img) {
        if (!is_array($img)) {
            continue;
        }
        $tok = (string) ($img['token'] ?? '');
        if ($tok !== '') {
            $allowed[$tok] = true;
        }
    }
    $tokens = [];
    $seen = [];
    foreach ($imageTokens as $token) {
        $token = (string) $token;
        if ($token === '' || !isset($allowed[$token]) || isset($seen[$token])) {
            continue;
        }
        $seen[$token] = true;
        $tokens[] = $token;
    }
    if ($tokens === []) {
        throw new InvalidArgumentException('Nav pievienojamu bildes');
    }

    $data = efpic_visitor_collections_load($config, $slug);
    $visitor = efpic_visitor_get_visitor($data, $visitorId);
    if ($visitor === null) {
        throw new RuntimeException('Apmeklētājs nav atrasts');
    }

    $name = efpic_visitor_face_collection_name();
    $collectionId = efpic_visitor_create_collection_record($data, $visitorId, $name);
    $data['collections'][$collectionId]['image_tokens'] = $tokens;
    $data['collections'][$collectionId]['updated_at'] = gmdate('c');
    efpic_visitor_collections_save($config, $slug, $data);
    efpic_visitor_set_session($galleryToken, $visitorId, $collectionId);
    efpic_gallery_log_activity(
        $config,
        $slug,
        $meta,
        'visitor_collection_create',
        ($visitor['name'] ?? '') . ' izveidoja izlasi «' . $name . '» (' . count($tokens) . ' bildes)',
        'visitor:' . ($visitor['email'] ?? ''),
        ['visitor_id' => $visitorId, 'collection_id' => $collectionId, 'count' => count($tokens)],
    );

    return [
        'collection' => $data['collections'][$collectionId],
        'count' => count($tokens),
    ];
}

/** @return array<string, true> */
function efpic_visitor_active_collection_token_map(
    array $config,
    string $slug,
    string $galleryToken,
    array $meta,
    array $ctx,
): array {
    if (!efpic_can_use_public_collection($meta)) {
        return [];
    }
    $session = efpic_visitor_session_state($galleryToken);
    if ($session === null) {
        return [];
    }
    $data = efpic_visitor_collections_load($config, $slug);
    $collection = efpic_visitor_get_collection($data, $session['active_collection_id']);
    if ($collection === null || (string) ($collection['visitor_id'] ?? '') !== $session['visitor_id']) {
        return [];
    }
    $nav = [];
    foreach (efpic_client_navigable_images($meta, $ctx) as $img) {
        $tok = (string) ($img['token'] ?? '');
        if ($tok !== '') {
            $nav[$tok] = true;
        }
    }
    $map = [];
    foreach ($collection['image_tokens'] ?? [] as $tok) {
        $tok = (string) $tok;
        if ($tok !== '' && isset($nav[$tok])) {
            $map[$tok] = true;
        }
    }

    return $map;
}

/** @return list<array<string, mixed>> */
function efpic_visitor_collection_images(array $config, string $slug, array $meta, array $ctx, string $collectionId): array
{
    $data = efpic_visitor_collections_load($config, $slug);
    $collection = efpic_visitor_get_collection($data, $collectionId);
    if ($collection === null) {
        return [];
    }
    $byToken = [];
    foreach (efpic_client_navigable_images($meta, $ctx) as $img) {
        $tok = (string) ($img['token'] ?? '');
        if ($tok !== '') {
            $byToken[$tok] = $img;
        }
    }
    $out = [];
    foreach ($collection['image_tokens'] ?? [] as $tok) {
        $tok = (string) $tok;
        if (isset($byToken[$tok])) {
            $out[] = $byToken[$tok];
        }
    }

    return $out;
}

function efpic_visitor_gallery_continue_url(array $config, string $galleryToken, string $accessToken): string
{
    return efpic_gallery_view_url($config, $galleryToken) . '?vc_access=' . rawurlencode($accessToken);
}

/** @param array<string, mixed> $visitor */
function efpic_visitor_send_continue_email(
    array $config,
    array $meta,
    string $slug,
    string $galleryToken,
    array $visitor,
    bool $isNew,
): void {
    if (!efpic_gallery_email_ready($config)) {
        return;
    }
    $to = (string) ($visitor['email'] ?? '');
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $name = (string) ($visitor['name'] ?? '');
    $galleryName = (string) ($meta['name'] ?? 'Galerija');
    $url = efpic_visitor_gallery_continue_url($config, $galleryToken, (string) ($visitor['access_token'] ?? ''));
    $subject = $isNew
        ? 'Tava izlase — ' . $galleryName
        : 'Turpini veidot izlasi — ' . $galleryName;
    $body = 'Sveiki' . ($name !== '' ? ', ' . $name : '') . "!\n\n";
    if ($isNew) {
        $body .= "Esi izveidojis izlasi galerijā «{$galleryName}».\n";
    } else {
        $body .= "Atgriezies pie izlases galerijā «{$galleryName}».\n";
    }
    $body .= "\nAtver galeriju un turpini atlasīt bildes:\n{$url}\n\n";
    $body .= "Saiti vari izmantot arī citā ierīcē.\n";

    try {
        efpic_gallery_deliver_email($config, $to, $subject, $body);
    } catch (Throwable) {
        /* ignore mail errors */
    }
}

/** @param array<string, mixed> $collection */
function efpic_visitor_collection_zip_fingerprint(array $collection, string $size): string
{
    $tokens = is_array($collection['image_tokens'] ?? null) ? $collection['image_tokens'] : [];
    $tokens = array_map('strval', $tokens);
    sort($tokens);

    return hash(
        'sha256',
        (string) ($collection['id'] ?? '') . '|' . $size . '|' . implode(',', $tokens),
    );
}

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>|null
 */
function efpic_visitor_find_reusable_zip(array $data, string $fingerprint, string $zipDir): ?array
{
    foreach ($data['zip_downloads'] ?? [] as $token => $job) {
        if (!is_array($job)) {
            continue;
        }
        if ((string) ($job['fingerprint'] ?? '') !== $fingerprint) {
            continue;
        }
        $expiresAt = strtotime((string) ($job['expires_at'] ?? ''));
        if ($expiresAt !== false && $expiresAt < time()) {
            continue;
        }
        $file = (string) ($job['file'] ?? '');
        $zipPath = $zipDir . DIRECTORY_SEPARATOR . $file;
        if ($file === '' || !is_file($zipPath) || !efpic_zip_verify_file($zipPath, 1)) {
            continue;
        }

        return array_merge($job, ['id' => (string) $token]);
    }

    return null;
}

/**
 * @return array{ok: bool, download_token?: string, error?: string}
 */
function efpic_visitor_ensure_collection_zip(
    array $config,
    string $slug,
    array $meta,
    array $ctx,
    string $galleryToken,
    string $visitorId,
    string $collectionId,
    string $size,
): array {
    if (!efpic_can_download_collection_zip($meta, $ctx, $size)) {
        return ['ok' => false, 'error' => 'download_disabled'];
    }
    $data = efpic_visitor_collections_load($config, $slug);
    $collection = efpic_visitor_get_collection($data, $collectionId);
    $visitor = efpic_visitor_get_visitor($data, $visitorId);
    if ($collection === null || $visitor === null || (string) ($collection['visitor_id'] ?? '') !== $visitorId) {
        return ['ok' => false, 'error' => 'not_found'];
    }
    $images = efpic_visitor_collection_images($config, $slug, $meta, $ctx, $collectionId);
    if ($images === []) {
        return ['ok' => false, 'error' => 'empty_collection'];
    }

    $found = efpic_find_gallery_by_token($config, $galleryToken);
    if ($found === null) {
        return ['ok' => false, 'error' => 'not_found'];
    }

    $zipDir = efpic_visitor_zips_dir($config, $slug);
    if (!is_dir($zipDir)) {
        mkdir($zipDir, 0755, true);
    }

    $fingerprint = efpic_visitor_collection_zip_fingerprint($collection, $size);
    $existing = efpic_visitor_find_reusable_zip($data, $fingerprint, $zipDir);
    if ($existing !== null) {
        return ['ok' => true, 'download_token' => (string) ($existing['id'] ?? '')];
    }

    $filename = efpic_client_zip_filename(
        $slug,
        $size,
        'collection',
        '',
        (string) ($collection['name'] ?? 'Izlase'),
        (string) ($meta['name'] ?? $slug),
    );
    $downloadToken = efpic_random_hex(20);
    $zipPath = $zipDir . DIRECTORY_SEPARATOR . $downloadToken . '.zip';

    $entryCount = 0;
    $ok = efpic_zip_build_file($zipPath, function (callable $add) use ($config, $meta, $images, $size, $found): void {
        if (efpic_is_delivery_gallery($meta)) {
            efpic_zip_populate_delivery_images($add, $config, $meta, $images, $size, true);
        } else {
            efpic_zip_populate_live_images($add, $found['dir'], $images);
        }
    }, $entryCount);

    if (!$ok || $entryCount === 0 || !efpic_zip_verify_file($zipPath, $entryCount)) {
        @unlink($zipPath);

        return ['ok' => false, 'error' => 'zip_build_failed'];
    }

    $expiresAt = gmdate('c', time() + 72 * 3600);
    $data['zip_downloads'][$downloadToken] = [
        'id' => $downloadToken,
        'collection_id' => $collectionId,
        'visitor_id' => $visitorId,
        'size' => $size,
        'filename' => $filename,
        'file' => $downloadToken . '.zip',
        'fingerprint' => $fingerprint,
        'entry_count' => $entryCount,
        'expires_at' => $expiresAt,
        'created_at' => gmdate('c'),
    ];
    efpic_visitor_collections_save($config, $slug, $data);

    return ['ok' => true, 'download_token' => $downloadToken];
}

/**
 * @return array{ok: bool, collections_prepared?: int, error?: string}
 */
function efpic_visitor_request_all_collections_zip_email(
    array $config,
    string $slug,
    array $meta,
    array $ctx,
    string $galleryToken,
    string $visitorId,
    string $size,
): array {
    $data = efpic_visitor_collections_load($config, $slug);
    $visitor = efpic_visitor_get_visitor($data, $visitorId);
    if ($visitor === null) {
        return ['ok' => false, 'error' => 'not_found'];
    }

    $job = [
        'type' => 'visitor_collections',
        'collection_ids' => efpic_visitor_zip_job_collection_ids($data, $visitorId),
        'prepared' => [],
    ];
    if ($job['collection_ids'] === []) {
        return ['ok' => false, 'error' => 'empty_collection'];
    }

    while (true) {
        $advance = efpic_visitor_zip_advance_job($config, $job, $meta, $ctx);
        if (empty($advance['ok'])) {
            return ['ok' => false, 'error' => (string) ($advance['error'] ?? 'zip_build_failed')];
        }
        if (!empty($advance['done'])) {
            break;
        }
    }

    $prepared = is_array($job['prepared'] ?? null) ? $job['prepared'] : [];
    if ($prepared === []) {
        return ['ok' => false, 'error' => 'empty_collection'];
    }

    efpic_visitor_zip_finalize_job_email($config, $slug, $meta, $visitor, $prepared, $size, $visitorId);

    return ['ok' => true, 'collections_prepared' => count($prepared)];
}

/**
 * @return list<string>
 */
function efpic_visitor_zip_job_collection_ids(array $data, string $visitorId): array
{
    $ids = [];
    foreach (efpic_visitor_collections_for_visitor($data, $visitorId) as $collection) {
        $tokens = is_array($collection['image_tokens'] ?? null) ? $collection['image_tokens'] : [];
        if ($tokens === []) {
            continue;
        }
        $id = (string) ($collection['id'] ?? '');
        if ($id !== '') {
            $ids[] = $id;
        }
    }

    return $ids;
}

/**
 * @param list<string> $collectionIds
 * @return list<array{id: string, name: string, count: int}>
 */
function efpic_visitor_zip_collection_summaries(array $data, array $collectionIds): array
{
    $out = [];
    foreach ($collectionIds as $collectionId) {
        $collection = efpic_visitor_get_collection($data, (string) $collectionId);
        if ($collection === null) {
            continue;
        }
        $tokens = is_array($collection['image_tokens'] ?? null) ? $collection['image_tokens'] : [];
        if ($tokens === []) {
            continue;
        }
        $out[] = [
            'id' => (string) ($collection['id'] ?? ''),
            'name' => (string) ($collection['name'] ?? 'Izlase'),
            'count' => count($tokens),
        ];
    }

    return $out;
}

/**
 * @param list<array{collection: array<string, mixed>, count: int}> $prepared
 * @return list<array{name: string, count: int}>
 */
function efpic_visitor_zip_prepared_summaries(array $prepared): array
{
    $out = [];
    foreach ($prepared as $item) {
        if (!is_array($item)) {
            continue;
        }
        $collection = is_array($item['collection'] ?? null) ? $item['collection'] : [];
        $out[] = [
            'name' => (string) ($collection['name'] ?? 'Izlase'),
            'count' => (int) ($item['count'] ?? 0),
        ];
    }

    return $out;
}

/**
 * @param list<array{name: string, count: int}> $collections
 * @return array{visitor_id: string, visitor_name: string, visitor_email: string, size: string, collections: list<array{name: string, count: int}>}
 */
function efpic_visitor_zip_activity_extra(
    array $visitor,
    string $visitorId,
    string $size,
    array $collections,
): array {
    return [
        'visitor_id' => $visitorId,
        'visitor_name' => (string) ($visitor['name'] ?? ''),
        'visitor_email' => (string) ($visitor['email'] ?? ''),
        'size' => $size,
        'collections' => $collections,
    ];
}

/**
 * @param list<array{name: string, count: int}> $collections
 */
function efpic_visitor_zip_activity_message(array $visitor, string $size, array $collections, string $phase): string
{
    unset($visitor);
    $sizeLabel = efpic_visitor_zip_size_label($size);
    $collectionCount = count($collections);

    if ($phase === 'email_sent') {
        if ($collectionCount === 1) {
            $n = (int) ($collections[0]['count'] ?? 0);

            return 'Nosūtīts e-pasts ar izlasi ' . $sizeLabel . ' (' . $n . ' bildes)';
        }

        return 'Nosūtīts e-pasts ar ' . $collectionCount . ' izlasēm ' . $sizeLabel;
    }

    if ($phase === 'download') {
        if ($collectionCount === 1) {
            $name = (string) ($collections[0]['name'] ?? 'Izlase');

            return 'Lejupielādē izlasi «' . $name . '» (' . $sizeLabel . ')';
        }

        return 'Lejupielādē ' . $collectionCount . ' izlases (' . $sizeLabel . ')';
    }

    if ($collectionCount === 1) {
        $name = (string) ($collections[0]['name'] ?? 'Izlase');
        $n = (int) ($collections[0]['count'] ?? 0);

        return 'Pieprasīja izlasi «' . $name . '» (' . $n . ' bildes, ' . $sizeLabel . ') uz e-pastu';
    }

    return 'Pieprasīja ' . $collectionCount . ' izlases (' . $sizeLabel . ') uz e-pastu';
}

function efpic_visitor_zip_download_notify_dedupe_seconds(): int
{
    return 120;
}

/**
 * @param array<string, mixed> $data
 * @param array<string, mixed> $job
 */
function efpic_visitor_zip_should_log_download_notify(array &$data, string $downloadToken, array &$job): bool
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $now = time();
    $window = efpic_visitor_zip_download_notify_dedupe_seconds();
    $lastAt = (int) ($job['telegram_notify_at'] ?? 0);
    $lastIp = (string) ($job['telegram_notify_ip'] ?? '');
    if ($lastAt > 0 && $lastIp === $ip && ($now - $lastAt) < $window) {
        return false;
    }
    $job['telegram_notify_at'] = $now;
    $job['telegram_notify_ip'] = $ip;
    $data['zip_downloads'][$downloadToken] = $job;

    return true;
}

function efpic_visitor_log_zip_email_request(
    array $config,
    string $slug,
    array &$meta,
    array $visitor,
    string $visitorId,
    string $size,
    array $data,
    array $collectionIds,
    string $activityType = 'visitor_collection_download',
): void {
    $summaries = efpic_visitor_zip_collection_summaries($data, $collectionIds);
    if ($summaries === []) {
        return;
    }
    efpic_gallery_log_activity(
        $config,
        $slug,
        $meta,
        $activityType,
        efpic_visitor_zip_activity_message($visitor, $size, $summaries, 'request'),
        'visitor:' . (string) ($visitor['email'] ?? ''),
        efpic_visitor_zip_activity_extra($visitor, $visitorId, $size, $summaries),
    );
}

/**
 * @param array<string, mixed> $collection
 * @return array{collection: array<string, mixed>, download_url: string, count: int}|null
 */
function efpic_visitor_zip_prepare_collection_item(
    array $config,
    string $slug,
    array $meta,
    array $ctx,
    string $galleryToken,
    string $visitorId,
    array $collection,
    string $size,
): ?array {
    $collectionId = (string) ($collection['id'] ?? '');
    if ($collectionId === '') {
        return null;
    }
    $tokens = is_array($collection['image_tokens'] ?? null) ? $collection['image_tokens'] : [];
    if ($tokens === []) {
        return null;
    }

    if ($collectionId === 'share_all') {
        $result = efpic_visitor_ensure_virtual_collection_zip(
            $config,
            $slug,
            $meta,
            $ctx,
            $galleryToken,
            $visitorId,
            $collection,
            $size,
        );
    } else {
        $result = efpic_visitor_ensure_collection_zip(
            $config,
            $slug,
            $meta,
            $ctx,
            $galleryToken,
            $visitorId,
            $collectionId,
            $size,
        );
    }
    if (empty($result['ok'])) {
        return null;
    }

    $downloadToken = (string) ($result['download_token'] ?? '');
    if ($downloadToken === '') {
        return null;
    }

    $zipPath = efpic_visitor_zips_dir($config, $slug) . DIRECTORY_SEPARATOR . $downloadToken . '.zip';
    if (!efpic_zip_verify_file($zipPath, 1)) {
        return null;
    }

    return [
        'collection' => $collection,
        'download_url' => efpic_visitor_zip_download_url($config, $galleryToken, $downloadToken),
        'download_token' => $downloadToken,
        'count' => count($tokens),
        'zip_bytes' => (int) filesize($zipPath),
    ];
}

function efpic_visitor_zip_download_url(array $config, string $galleryToken, string $downloadToken): string
{
    return efpic_base_url($config) . '/v/g/' . rawurlencode($galleryToken)
        . '/visitor/download/' . rawurlencode($downloadToken);
}

function efpic_visitor_zip_size_label(string $size): string
{
    return efpic_gallery_download_size_label($size);
}

function efpic_visitor_zip_ready_intro(int $collectionCount, string $size): string
{
    $label = efpic_visitor_zip_size_label($size);
    if ($collectionCount === 1) {
        return 'Tava izlase ' . $label . ' izmērā ir gatava lejupielādei.';
    }

    return 'Tavas izlases ' . $label . ' izmērā ir gatavas lejupielādei.';
}

/**
 * @param array<string, mixed> $job
 * @return array{ok: bool, done?: bool, error?: string}
 */
function efpic_visitor_zip_advance_job(array $config, array &$job, array $meta, array $ctx): array
{
    $slug = (string) ($job['slug'] ?? '');
    $galleryToken = (string) ($job['gallery_token'] ?? '');
    $visitorId = (string) ($job['visitor_id'] ?? '');
    $size = (string) ($job['size'] ?? 'web');
    $type = (string) ($job['type'] ?? 'visitor_collections');

    $data = efpic_visitor_collections_load($config, $slug);
    $visitor = efpic_visitor_get_visitor($data, $visitorId);
    if ($visitor === null) {
        return ['ok' => false, 'error' => 'not_found'];
    }

    /** @var list<array{collection: array<string, mixed>, download_url: string, count: int}> $prepared */
    $prepared = is_array($job['prepared'] ?? null) ? $job['prepared'] : [];
    $collectionIds = is_array($job['collection_ids'] ?? null) ? $job['collection_ids'] : [];

    if ($type === 'share_all') {
        if ($prepared !== []) {
            return ['ok' => true, 'done' => true];
        }
        $images = efpic_client_navigable_images($meta, $ctx);
        if ($images === []) {
            return ['ok' => false, 'error' => 'empty_collection'];
        }
        $shareLabel = trim((string) ($ctx['share_label'] ?? ''));
        if ($shareLabel === '') {
            $shareLabel = 'Izlase';
        }
        $virtualCollection = [
            'id' => 'share_all',
            'name' => $shareLabel,
            'visitor_id' => $visitorId,
            'image_tokens' => array_values(array_filter(array_map(
                static fn ($img) => is_array($img) ? (string) ($img['token'] ?? '') : '',
                $images,
            ), static fn ($t) => $t !== '')),
        ];
        $item = efpic_visitor_zip_prepare_collection_item(
            $config,
            $slug,
            $meta,
            $ctx,
            $galleryToken,
            $visitorId,
            $virtualCollection,
            $size,
        );
        if ($item === null) {
            return ['ok' => false, 'error' => 'zip_build_failed'];
        }
        $prepared = [$item];
        $job['prepared'] = $prepared;

        return ['ok' => true, 'done' => true];
    }

    $nextIndex = count($prepared);
    if ($nextIndex >= count($collectionIds)) {
        return ['ok' => true, 'done' => true];
    }

    $collectionId = (string) $collectionIds[$nextIndex];
    $collection = efpic_visitor_get_collection($data, $collectionId);
    if ($collection === null) {
        return ['ok' => false, 'error' => 'not_found'];
    }

    $item = efpic_visitor_zip_prepare_collection_item(
        $config,
        $slug,
        $meta,
        $ctx,
        $galleryToken,
        $visitorId,
        $collection,
        $size,
    );
    if ($item === null) {
        return ['ok' => false, 'error' => 'zip_build_failed'];
    }

    $prepared[] = $item;
    $job['prepared'] = $prepared;
    $job['collections_prepared'] = count($prepared);

    return ['ok' => true, 'done' => count($prepared) >= count($collectionIds)];
}

/**
 * @param list<array{collection: array<string, mixed>, download_url: string, count: int}> $prepared
 */
function efpic_visitor_zip_finalize_job_email(
    array $config,
    string $slug,
    array $meta,
    array $visitor,
    array $prepared,
    string $size,
    string $visitorId,
): void {
    if ($prepared === []) {
        return;
    }
    $zipDir = efpic_visitor_zips_dir($config, $slug);
    foreach ($prepared as $item) {
        $token = (string) ($item['download_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('ZIP nav gatavs');
        }
        $zipPath = $zipDir . DIRECTORY_SEPARATOR . $token . '.zip';
        if (!efpic_zip_verify_file($zipPath, 1)) {
            throw new RuntimeException('ZIP nav gatavs');
        }
    }
    efpic_visitor_send_all_zips_ready_email($config, $meta, $visitor, $prepared, $size);
    $summaries = efpic_visitor_zip_prepared_summaries($prepared);
    efpic_gallery_log_activity(
        $config,
        $slug,
        $meta,
        'visitor_collection_email_sent',
        efpic_visitor_zip_activity_message($visitor, $size, $summaries, 'email_sent'),
        'visitor:' . (string) ($visitor['email'] ?? ''),
        efpic_visitor_zip_activity_extra($visitor, $visitorId, $size, $summaries),
    );
}

/**
 * @return array{ok: bool, collections_prepared?: int, error?: string}
 */
function efpic_visitor_request_share_all_zip_email(
    array $config,
    string $slug,
    array $meta,
    array $ctx,
    string $galleryToken,
    string $visitorId,
    string $size,
): array {
    $data = efpic_visitor_collections_load($config, $slug);
    $visitor = efpic_visitor_get_visitor($data, $visitorId);
    if ($visitor === null) {
        return ['ok' => false, 'error' => 'not_found'];
    }

    $images = efpic_client_navigable_images($meta, $ctx);
    if ($images === []) {
        return ['ok' => false, 'error' => 'empty_collection'];
    }

    $shareLabel = trim((string) ($ctx['share_label'] ?? ''));
    if ($shareLabel === '') {
        $shareLabel = 'Izlase';
    }

    $virtualCollection = [
        'id' => 'share_all',
        'name' => $shareLabel,
        'visitor_id' => $visitorId,
        'image_tokens' => array_values(array_filter(array_map(
            static fn ($img) => is_array($img) ? (string) ($img['token'] ?? '') : '',
            $images,
        ), static fn ($t) => $t !== '')),
    ];

    $item = efpic_visitor_zip_prepare_collection_item(
        $config,
        $slug,
        $meta,
        $ctx,
        $galleryToken,
        $visitorId,
        $virtualCollection,
        $size,
    );
    if ($item === null) {
        return ['ok' => false, 'error' => 'zip_build_failed'];
    }

    $prepared = [$item];
    efpic_visitor_send_all_zips_ready_email($config, $meta, $visitor, $prepared, $size);
    $summaries = [['name' => $shareLabel, 'count' => count($images)]];
    efpic_gallery_log_activity(
        $config,
        $slug,
        $meta,
        'visitor_share_download',
        efpic_visitor_zip_activity_message($visitor, $size, $summaries, 'request'),
        'visitor:' . (string) ($visitor['email'] ?? ''),
        efpic_visitor_zip_activity_extra($visitor, $visitorId, $size, $summaries),
    );

    return ['ok' => true, 'collections_prepared' => 1];
}

/**
 * @param array<string, mixed> $collection
 * @return array{ok: bool, download_token?: string, error?: string}
 */
function efpic_visitor_ensure_virtual_collection_zip(
    array $config,
    string $slug,
    array $meta,
    array $ctx,
    string $galleryToken,
    string $visitorId,
    array $collection,
    string $size,
): array {
    if (!efpic_can_download_size($meta, $ctx, $size)) {
        return ['ok' => false, 'error' => 'download_disabled'];
    }

    $tokens = is_array($collection['image_tokens'] ?? null) ? $collection['image_tokens'] : [];
    if ($tokens === []) {
        return ['ok' => false, 'error' => 'empty_collection'];
    }

    $found = efpic_find_gallery_by_token($config, $galleryToken);
    if ($found === null) {
        return ['ok' => false, 'error' => 'not_found'];
    }

    $zipDir = efpic_visitor_zips_dir($config, $slug);
    if (!is_dir($zipDir)) {
        mkdir($zipDir, 0755, true);
    }

    $fingerprint = efpic_visitor_collection_zip_fingerprint($collection, $size);
    $data = efpic_visitor_collections_load($config, $slug);
    $existing = efpic_visitor_find_reusable_zip($data, $fingerprint, $zipDir);
    if ($existing !== null) {
        return ['ok' => true, 'download_token' => (string) ($existing['id'] ?? '')];
    }

    $byToken = [];
    foreach (efpic_client_navigable_images($meta, $ctx) as $img) {
        if (!is_array($img)) {
            continue;
        }
        $tok = (string) ($img['token'] ?? '');
        if ($tok !== '') {
            $byToken[$tok] = $img;
        }
    }
    $images = [];
    foreach ($tokens as $tok) {
        $tok = (string) $tok;
        if (isset($byToken[$tok])) {
            $images[] = $byToken[$tok];
        }
    }
    if ($images === []) {
        return ['ok' => false, 'error' => 'empty_collection'];
    }

    $scope = (string) ($collection['id'] ?? 'share');
    $filename = efpic_client_zip_filename(
        $slug,
        $size,
        'collection',
        '',
        (string) ($collection['name'] ?? 'Izlase'),
        (string) ($meta['name'] ?? $slug),
    );
    $downloadToken = efpic_random_hex(20);
    $zipPath = $zipDir . DIRECTORY_SEPARATOR . $downloadToken . '.zip';

    $entryCount = 0;
    $ok = efpic_zip_build_file($zipPath, function (callable $add) use ($config, $meta, $images, $size, $found): void {
        if (efpic_is_delivery_gallery($meta)) {
            efpic_zip_populate_delivery_images($add, $config, $meta, $images, $size, true);
        } else {
            efpic_zip_populate_live_images($add, $found['dir'], $images);
        }
    }, $entryCount);

    if (!$ok || $entryCount === 0 || !efpic_zip_verify_file($zipPath, $entryCount)) {
        @unlink($zipPath);

        return ['ok' => false, 'error' => 'zip_build_failed'];
    }

    $expiresAt = gmdate('c', time() + 72 * 3600);
    $data['zip_downloads'][$downloadToken] = [
        'id' => $downloadToken,
        'collection_id' => (string) ($collection['id'] ?? ''),
        'visitor_id' => $visitorId,
        'size' => $size,
        'filename' => $filename,
        'file' => $downloadToken . '.zip',
        'fingerprint' => $fingerprint,
        'entry_count' => $entryCount,
        'expires_at' => $expiresAt,
        'created_at' => gmdate('c'),
    ];
    efpic_visitor_collections_save($config, $slug, $data);

    return ['ok' => true, 'download_token' => $downloadToken];
}

/**
 * @return array{ok: bool, download_token?: string, error?: string}
 */
function efpic_visitor_request_collection_zip_email(
    array $config,
    string $slug,
    array $meta,
    array $ctx,
    string $galleryToken,
    string $visitorId,
    string $collectionId,
    string $size,
): array {
    unset($collectionId);

    return efpic_visitor_request_all_collections_zip_email(
        $config,
        $slug,
        $meta,
        $ctx,
        $galleryToken,
        $visitorId,
        $size,
    );
}

/** @param list<array{collection: array<string, mixed>, download_url: string, count: int}> $prepared */
function efpic_visitor_send_all_zips_ready_email(
    array $config,
    array $meta,
    array $visitor,
    array $prepared,
    string $size,
): void {
    if (!efpic_gallery_email_ready($config)) {
        return;
    }
    $to = (string) ($visitor['email'] ?? '');
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $galleryName = (string) ($meta['name'] ?? 'Galerija');
    $subject = 'Izlases lejupielāde — ' . $galleryName;

    try {
        efpic_visitor_deliver_zip_ready_email($config, $to, $subject, $meta, $visitor, $prepared, $size);
    } catch (Throwable) {
        /* ignore */
    }
}

/** @param list<array{collection: array<string, mixed>, download_url: string, count: int}> $prepared */
function efpic_visitor_deliver_zip_ready_email(
    array $config,
    string $to,
    string $subject,
    array $meta,
    array $visitor,
    array $prepared,
    string $size,
): void {
    $plainBody = efpic_gallery_email_with_signature(
        $config,
        efpic_visitor_zip_email_plain($config, $meta, $visitor, $prepared, $size),
    );
    $htmlPack = efpic_visitor_zip_email_html_pack($config, $meta, $visitor, $prepared, $size);
    efpic_gallery_deliver_rich_email($config, $to, $subject, $plainBody, $htmlPack['html'], $htmlPack['inline']);
}

/** @param list<array{collection: array<string, mixed>, download_url: string, count: int}> $prepared */
function efpic_visitor_zip_email_plain(
    array $config,
    array $meta,
    array $visitor,
    array $prepared,
    string $size,
): string {
    $name = (string) ($visitor['name'] ?? '');
    $greeting = $name !== '' ? 'Sveiki, ' . $name . '!' : 'Sveiki!';
    $body = $greeting . "\n\n";
    $body .= efpic_visitor_zip_ready_intro(count($prepared), $size) . "\n\n";
    $galleryName = (string) ($meta['name'] ?? 'Galerija');
    $body .= 'Galerija: ' . $galleryName . "\n\n";

    foreach ($prepared as $item) {
        $collectionName = (string) ($item['collection']['name'] ?? 'Izlase');
        $line = '«' . $collectionName . '» (' . (int) $item['count'] . ' bildes';
        $zipBytes = (int) ($item['zip_bytes'] ?? 0);
        if ($zipBytes > 0) {
            $line .= ' · ' . efpic_format_bytes($zipBytes);
        }
        $line .= ")\n";
        $body .= $line;
        $body .= $item['download_url'] . "\n\n";
    }
    $body .= "Saites ir derīgas 72 stundas.\n";

    return $body;
}

/**
 * @param list<array{collection: array<string, mixed>, download_url: string, count: int}> $prepared
 * @return array{html: string, inline: list<array{cid: string, path: string, mime: string}>}
 */
function efpic_visitor_zip_email_html_pack(
    array $config,
    array $meta,
    array $visitor,
    array $prepared,
    string $size,
): array {
    $settings = efpic_load_app_settings($config);
    $byline = trim((string) ($settings['gallery_byline'] ?? 'Gallery by EdgarsFoto'));
    $name = htmlspecialchars((string) ($visitor['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $greeting = $name !== '' ? 'Sveiki, ' . $name . '!' : 'Sveiki!';
    $galleryName = htmlspecialchars((string) ($meta['name'] ?? 'Galerija'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $bylineEsc = htmlspecialchars($byline, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $intro = htmlspecialchars(efpic_visitor_zip_ready_intro(count($prepared), $size), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $sizeLabel = htmlspecialchars(efpic_visitor_zip_size_label($size), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $cards = '';
    foreach ($prepared as $item) {
        $collectionName = htmlspecialchars((string) ($item['collection']['name'] ?? 'Izlase'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $count = (int) $item['count'];
        $url = htmlspecialchars($item['download_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $zipBytes = (int) ($item['zip_bytes'] ?? 0);
        $metaLine = $count . ' bildes · ' . $sizeLabel;
        if ($zipBytes > 0) {
            $metaLine .= ' · ' . htmlspecialchars(efpic_format_bytes($zipBytes), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $cards .= '<tr><td style="padding:0 0 12px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#faf9f7;border:1px solid #e8e4df;border-radius:10px;">'
            . '<tr><td style="padding:16px 18px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            . '<td style="vertical-align:middle;padding:0 16px 0 0;">'
            . '<div style="font-size:15px;font-weight:600;color:#1a1a1a;margin:0 0 4px;line-height:1.35;">' . $collectionName . '</div>'
            . '<div style="font-size:13px;color:#6b6560;margin:0;line-height:1.4;">' . $metaLine . '</div>'
            . '</td>'
            . '<td style="vertical-align:middle;width:1px;white-space:nowrap;text-align:right;">'
            . '<a href="' . $url . '" style="display:inline-block;background:#1a1a1a;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:12px 18px;border-radius:8px;line-height:1.2;">Lejupielādēt ZIP</a>'
            . '</td></tr></table>'
            . '</td></tr></table></td></tr>';
    }

    $signatureEmbedded = efpic_email_embed_inline_images($config, efpic_gallery_email_signature_html($config));
    $signatureBlock = '';
    if ($signatureEmbedded['html'] !== '') {
        $signatureBlock = '<tr><td style="padding:20px 28px 24px;border-top:1px solid #e8e4df;font-family:Helvetica,Arial,sans-serif;">'
            . '<div style="font-size:14px;line-height:1.55;color:#333;">' . $signatureEmbedded['html'] . '</div></td></tr>';
    }

    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Izlases lejupielāde</title></head>'
        . '<body style="margin:0;padding:0;background:#f0eeeb;font-family:Georgia,\'Times New Roman\',serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0eeeb;padding:32px 16px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.06);">'
        . '<tr><td style="background:#1a1a1a;padding:28px 28px 24px;text-align:center;">'
        . '<div style="font-size:11px;letter-spacing:0.12em;text-transform:uppercase;color:#b8b0a8;margin:0 0 8px;">' . $bylineEsc . '</div>'
        . '<div style="font-size:22px;font-weight:400;color:#ffffff;margin:0;line-height:1.3;">' . $galleryName . '</div>'
        . '</td></tr>'
        . '<tr><td style="padding:28px 28px 8px;font-family:Helvetica,Arial,sans-serif;">'
        . '<p style="margin:0 0 12px;font-size:16px;color:#1a1a1a;">' . $greeting . '</p>'
        . '<p style="margin:0 0 20px;font-size:15px;line-height:1.55;color:#444;">' . $intro . '</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $cards . '</table>'
        . '<p style="margin:16px 0 0;font-size:12px;color:#8a847c;">Saites ir derīgas 72 stundas.</p>'
        . '</td></tr>'
        . $signatureBlock
        . '</table></td></tr></table></body></html>';

    return ['html' => $html, 'inline' => $signatureEmbedded['inline']];
}

/** @param array<string, mixed> $visitor @param array<string, mixed> $collection */
function efpic_visitor_send_zip_ready_email(
    array $config,
    array $meta,
    array $visitor,
    array $collection,
    string $downloadUrl,
    string $size,
): void {
    if (!efpic_gallery_email_ready($config)) {
        return;
    }
    $to = (string) ($visitor['email'] ?? '');
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $galleryName = (string) ($meta['name'] ?? 'Galerija');
    $collectionName = (string) ($collection['name'] ?? 'Izlase');
    $count = is_array($collection['image_tokens'] ?? null) ? count($collection['image_tokens']) : 0;
    $subject = 'Izlases lejupielāde — ' . $galleryName;
    $prepared = [[
        'collection' => $collection,
        'download_url' => $downloadUrl,
        'count' => $count,
    ]];

    try {
        efpic_visitor_deliver_zip_ready_email($config, $to, $subject, $meta, $visitor, $prepared, $size);
    } catch (Throwable) {
        /* ignore */
    }
}

/** @return array{visitor: array<string, mixed>, collections: list<array<string, mixed>>, active_collection: array<string, mixed>|null, active_tokens: array<string, true>}|null */
function efpic_visitor_public_status(
    array $config,
    string $slug,
    array $meta,
    string $galleryToken,
    array $ctx,
): ?array {
    $session = efpic_visitor_session_state($galleryToken);
    if ($session === null) {
        return null;
    }
    $data = efpic_visitor_collections_load($config, $slug);
    $visitor = efpic_visitor_get_visitor($data, $session['visitor_id']);
    if ($visitor === null) {
        return null;
    }
    $collections = efpic_visitor_collections_for_visitor($data, $session['visitor_id']);
    $active = efpic_visitor_get_collection($data, $session['active_collection_id']);
    if ($active === null && $collections !== []) {
        $active = $collections[0];
        efpic_visitor_set_session($galleryToken, $session['visitor_id'], (string) ($active['id'] ?? ''));
    }

    return [
        'visitor' => [
            'id' => (string) ($visitor['id'] ?? ''),
            'name' => (string) ($visitor['name'] ?? ''),
            'email' => (string) ($visitor['email'] ?? ''),
        ],
        'collections' => array_map(static function (array $collection): array {
            $tokens = is_array($collection['image_tokens'] ?? null) ? $collection['image_tokens'] : [];

            return [
                'id' => (string) ($collection['id'] ?? ''),
                'name' => (string) ($collection['name'] ?? ''),
                'count' => count($tokens),
                'updated_at' => (string) ($collection['updated_at'] ?? ''),
            ];
        }, $collections),
        'active_collection' => $active,
        'active_tokens' => efpic_visitor_active_collection_token_map($config, $slug, $galleryToken, $meta, $ctx),
    ];
}

function efpic_visitor_collection_public_summary(?array $collection): array
{
    if ($collection === null) {
        return ['id' => '', 'name' => '', 'count' => 0];
    }
    $tokens = is_array($collection['image_tokens'] ?? null) ? $collection['image_tokens'] : [];

    return [
        'id' => (string) ($collection['id'] ?? ''),
        'name' => (string) ($collection['name'] ?? ''),
        'count' => count($tokens),
    ];
}

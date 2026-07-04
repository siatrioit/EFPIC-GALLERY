<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/image_dimensions.php';
require_once __DIR__ . '/failiem_client.php';

const EFPIC_FACE_EMBED_DIM = 512;
const EFPIC_FACE_MATCH_THRESHOLD = 0.42;
const EFPIC_FACE_INDEX_BATCH = 1;
const EFPIC_FACE_THUMB_WIDTH = 640;
const EFPIC_FACE_RECLAIM_SEC = 600;
const EFPIC_FACE_SEARCH_TIMEOUT_SEC = 120;
const EFPIC_FACE_SEARCH_POLL_MS = 800;

/** @return array<string, mixed> */
function efpic_face_cfg(array $config): array
{
    $f = $config['face_search'] ?? [];

    return is_array($f) ? $f : [];
}

function efpic_face_match_threshold(array $config): float
{
    $t = (float) (efpic_face_cfg($config)['match_threshold'] ?? EFPIC_FACE_MATCH_THRESHOLD);

    return max(0.25, min(0.75, $t));
}

function efpic_face_queue_dir(array $config): string
{
    return dirname(efpic_storage_path($config)) . DIRECTORY_SEPARATOR . 'face_queue';
}

function efpic_face_index_path(array $config, string $slug): string
{
    return efpic_gallery_dir($config, $slug) . DIRECTORY_SEPARATOR . 'face_index.json';
}

function efpic_face_worker_state_path(array $config): string
{
    return dirname(efpic_storage_path($config)) . DIRECTORY_SEPARATOR . 'face_worker_state.json';
}

function efpic_face_job_path(array $config, string $jobId): string
{
    if (preg_match('/^[a-f0-9]{32}$/', $jobId) !== 1) {
        throw new InvalidArgumentException('Nederīgs face job ID');
    }

    return efpic_face_queue_dir($config) . DIRECTORY_SEPARATOR . $jobId . '.json';
}

function efpic_face_job_selfie_path(array $config, string $jobId): string
{
    return efpic_face_queue_dir($config) . DIRECTORY_SEPARATOR . $jobId . '.selfie.jpg';
}

/** @return array<string, mixed> */
function efpic_gallery_face_search_defaults(): array
{
    return [
        'enabled' => false,
        'provider' => 'local',
        'failiem_upload_hash' => '',
        'status' => 'none',
        'indexed_images' => 0,
        'total_faces' => 0,
        'pending_images' => 0,
        'last_index_at' => '',
        'error' => '',
    ];
}

/** @return array<string, mixed> */
function efpic_gallery_face_search(array $meta): array
{
    $fs = $meta['face_search'] ?? null;

    return is_array($fs) ? array_merge(efpic_gallery_face_search_defaults(), $fs) : efpic_gallery_face_search_defaults();
}

function efpic_gallery_face_search_enabled(array $meta): bool
{
    return !empty(efpic_gallery_face_search($meta)['enabled']);
}

function efpic_face_image_source_key(array $img): string
{
    return efpic_image_dimensions_source_key($img);
}

/** @return array<string, mixed>|null */
function efpic_face_load_index(array $config, string $slug): ?array
{
    $data = efpic_read_json_file(efpic_face_index_path($config, $slug));
    if ($data === null) {
        return null;
    }
    if (!isset($data['entries']) || !is_array($data['entries'])) {
        $data['entries'] = [];
    }

    return $data;
}

function efpic_face_save_index(array $config, string $slug, array $index): void
{
    $index['updated_at'] = gmdate('c');
    efpic_write_json_file(efpic_face_index_path($config, $slug), $index);
}

/** @return array{indexed: int, total_faces: int, pending: int, stale: int} */
function efpic_face_index_stats(array $config, string $slug, array $meta): array
{
    $index = efpic_face_load_index($config, $slug);
    $entries = is_array($index) ? ($index['entries'] ?? []) : [];
    if (!is_array($entries)) {
        $entries = [];
    }

    $indexed = 0;
    $totalFaces = 0;
    $stale = 0;
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $indexed++;
        $faces = $entry['faces'] ?? [];
        if (is_array($faces)) {
            $totalFaces += count($faces);
        }
    }

    $pending = 0;
    foreach ($meta['images'] ?? [] as $img) {
        if (!is_array($img)) {
            continue;
        }
        if (trim((string) ($img['failiem_web']['file_hash'] ?? '')) === '') {
            continue;
        }
        $token = (string) ($img['token'] ?? '');
        if ($token === '') {
            continue;
        }
        $sourceKey = efpic_face_image_source_key($img);
        $stored = is_array($entries[$token] ?? null) ? $entries[$token] : null;
        if ($stored === null) {
            $pending++;
            continue;
        }
        $storedKey = trim((string) ($stored['source_key'] ?? ''));
        if ($storedKey === '' || $storedKey !== $sourceKey) {
            $stale++;
            $pending++;
        }
    }

    return [
        'indexed' => $indexed,
        'total_faces' => $totalFaces,
        'pending' => $pending,
        'stale' => $stale,
    ];
}

function efpic_face_update_gallery_status(array $config, string $slug, array &$meta, ?array $extra = null): void
{
    if (!isset($meta['face_search']) || !is_array($meta['face_search'])) {
        $meta['face_search'] = efpic_gallery_face_search_defaults();
    }
    $stats = efpic_face_index_stats($config, $slug, $meta);
    $meta['face_search']['indexed_images'] = $stats['indexed'];
    $meta['face_search']['total_faces'] = $stats['total_faces'];
    $meta['face_search']['pending_images'] = $stats['pending'];
    if ($extra !== null) {
        foreach ($extra as $k => $v) {
            $meta['face_search'][$k] = $v;
        }
    }
    if ($stats['pending'] <= 0 && ($meta['face_search']['status'] ?? '') === 'indexing') {
        $meta['face_search']['status'] = 'ready';
        $meta['face_search']['last_index_at'] = gmdate('c');
        $meta['face_search']['error'] = '';
    } elseif ($stats['pending'] > 0 && ($meta['face_search']['status'] ?? '') === 'ready') {
        $meta['face_search']['status'] = 'queued';
    }
}

/** @return list<array{token: string, url: string}> */
function efpic_face_pending_images(array $config, string $slug, array $meta, int $limit = EFPIC_FACE_INDEX_BATCH): array
{
    $index = efpic_face_load_index($config, $slug);
    $entries = is_array($index) ? ($index['entries'] ?? []) : [];
    if (!is_array($entries)) {
        $entries = [];
    }

    $out = [];
    foreach ($meta['images'] ?? [] as $img) {
        if (!is_array($img)) {
            continue;
        }
        $token = (string) ($img['token'] ?? '');
        $webHash = trim((string) ($img['failiem_web']['file_hash'] ?? ''));
        if ($token === '' || $webHash === '') {
            continue;
        }
        $sourceKey = efpic_face_image_source_key($img);
        $stored = is_array($entries[$token] ?? null) ? $entries[$token] : null;
        $needs = $stored === null;
        if (!$needs && $stored !== null) {
            $storedKey = trim((string) ($stored['source_key'] ?? ''));
            $needs = $storedKey === '' || $storedKey !== $sourceKey;
        }
        if (!$needs) {
            continue;
        }
        $url = efpic_failiem_thumb_url($config, $webHash, EFPIC_FACE_THUMB_WIDTH);
        $out[] = ['token' => $token, 'url' => $url];
        if (count($out) >= $limit) {
            break;
        }
    }

    return $out;
}

function efpic_face_save_job(array $config, array $job): void
{
    $id = (string) ($job['id'] ?? '');
    if ($id === '') {
        throw new InvalidArgumentException('Job bez ID');
    }
    $dir = efpic_face_queue_dir($config);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $job['updated_at'] = gmdate('c');
    efpic_write_json_file(efpic_face_job_path($config, $id), $job);
}

/** @return array<string, mixed>|null */
function efpic_face_load_job(array $config, string $jobId): ?array
{
    return efpic_read_json_file(efpic_face_job_path($config, $jobId));
}

function efpic_face_enqueue_index_batch(array $config, string $slug, array $meta): ?string
{
    if (!efpic_gallery_face_search_enabled($meta)) {
        return null;
    }
    $pending = efpic_face_pending_images($config, $slug, $meta, EFPIC_FACE_INDEX_BATCH);
    if ($pending === []) {
        return null;
    }

    $jobId = bin2hex(random_bytes(16));
    efpic_face_save_job($config, [
        'id' => $jobId,
        'type' => 'index',
        'status' => 'queued',
        'slug' => $slug,
        'gallery_token' => (string) ($meta['gallery_token'] ?? ''),
        'created_at' => gmdate('c'),
        'attempts' => 0,
        'images' => $pending,
    ]);

    $meta['face_search']['status'] = 'indexing';
    $meta['face_search']['error'] = '';
    efpic_face_update_gallery_status($config, $slug, $meta);
    efpic_save_gallery_meta($config, $slug, $meta);

    return $jobId;
}

function efpic_face_queue_gallery_index(array $config, string $slug, ?array $meta = null): void
{
    $meta ??= efpic_load_gallery_meta($config, $slug);
    if ($meta === null || !efpic_gallery_face_search_enabled($meta)) {
        return;
    }
    if (efpic_gallery_face_search_uses_failiem($meta)) {
        return;
    }
    if (!isset($meta['face_search']) || !is_array($meta['face_search'])) {
        $meta['face_search'] = efpic_gallery_face_search_defaults();
        $meta['face_search']['enabled'] = true;
    }
    $stats = efpic_face_index_stats($config, $slug, $meta);
    if ($stats['pending'] <= 0) {
        efpic_face_update_gallery_status($config, $slug, $meta, ['status' => 'ready']);
        efpic_save_gallery_meta($config, $slug, $meta);

        return;
    }
    if (efpic_face_slug_index_busy($config, $slug)) {
        return;
    }
    efpic_face_enqueue_index_batch($config, $slug, $meta);
}

function efpic_face_worker_touch(array $config, string $event = 'ping'): void
{
    $path = efpic_face_worker_state_path($config);
    $state = efpic_read_json_file($path) ?? [];
    $now = gmdate('c');
    $state['last_seen_at'] = $now;
    if ($event === 'ping') {
        $state['last_ping_at'] = $now;
    } elseif ($event === 'claim') {
        $state['last_claim_at'] = $now;
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    efpic_write_json_file($path, $state);
}

/** @return array{online: bool, status: string, status_label: string, last_seen_at: string, last_seen_ago: string} */
function efpic_face_worker_status(array $config): array
{
    $state = efpic_read_json_file(efpic_face_worker_state_path($config)) ?? [];
    $lastSeenRaw = (string) ($state['last_seen_at'] ?? '');
    $lastSeen = $lastSeenRaw !== '' ? (strtotime($lastSeenRaw) ?: 0) : 0;
    $age = $lastSeen > 0 ? time() - $lastSeen : null;
    $online = $age !== null && $age <= 90;
    $stale = $age !== null && $age <= 300;
    $status = $online ? 'online' : ($stale ? 'stale' : 'offline');
    $statusLabel = match ($status) {
        'online' => 'Face worker aktīvs',
        'stale' => 'Face worker klusums',
        default => 'Face worker bez signāla',
    };

    return [
        'online' => $online,
        'status' => $status,
        'status_label' => $statusLabel,
        'last_seen_at' => $lastSeenRaw,
        'last_seen_ago' => efpic_face_format_ago($age),
        'last_seen_sec' => $age,
    ];
}

/** @return array{queued: int, processing: int, total: int} */
function efpic_face_queue_stats(array $config): array
{
    $dir = efpic_face_queue_dir($config);
    $queued = 0;
    $processing = 0;
    if (is_dir($dir)) {
        foreach (scandir($dir) ?: [] as $entry) {
            if (!str_ends_with($entry, '.json')) {
                continue;
            }
            $job = efpic_read_json_file($dir . DIRECTORY_SEPARATOR . $entry);
            if (!is_array($job)) {
                continue;
            }
            $st = (string) ($job['status'] ?? '');
            if ($st === 'queued') {
                $queued++;
            } elseif ($st === 'processing') {
                $processing++;
            }
        }
    }

    return [
        'queued' => $queued,
        'processing' => $processing,
        'total' => $queued + $processing,
    ];
}

/** @return array{queued: int, processing: int, total: int} */
function efpic_face_queue_stats_for_slug(array $config, string $slug): array
{
    $dir = efpic_face_queue_dir($config);
    $queued = 0;
    $processing = 0;
    if (!is_dir($dir)) {
        return ['queued' => 0, 'processing' => 0, 'total' => 0];
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if (!str_ends_with($entry, '.json')) {
            continue;
        }
        $job = efpic_read_json_file($dir . DIRECTORY_SEPARATOR . $entry);
        if (!is_array($job) || (string) ($job['slug'] ?? '') !== $slug) {
            continue;
        }
        $st = (string) ($job['status'] ?? '');
        if ($st === 'queued') {
            $queued++;
        } elseif ($st === 'processing') {
            $processing++;
        }
    }

    return [
        'queued' => $queued,
        'processing' => $processing,
        'total' => $queued + $processing,
    ];
}

function efpic_face_slug_has_queued_job(array $config, string $slug): bool
{
    return efpic_face_queue_stats_for_slug($config, $slug)['queued'] > 0;
}

function efpic_face_slug_index_busy(array $config, string $slug): bool
{
    $q = efpic_face_queue_stats_for_slug($config, $slug);

    return $q['queued'] > 0 || $q['processing'] > 0;
}

function efpic_face_purge_queue_for_slug(array $config, string $slug): int
{
    $dir = efpic_face_queue_dir($config);
    if (!is_dir($dir)) {
        return 0;
    }
    $removed = 0;
    foreach (scandir($dir) ?: [] as $entry) {
        if (!str_ends_with($entry, '.json')) {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        $job = efpic_read_json_file($path);
        if (!is_array($job) || (string) ($job['slug'] ?? '') !== $slug) {
            continue;
        }
        $jobId = (string) ($job['id'] ?? substr($entry, 0, -5));
        @unlink($path);
        @unlink(efpic_face_job_selfie_path($config, $jobId));
        $removed++;
    }

    return $removed;
}

/** @return array<string, mixed> */
function efpic_face_worker_diagnostic(array $config, ?string $slug = null): array
{
    $worker = efpic_face_worker_status($config);
    $state = efpic_read_json_file(efpic_face_worker_state_path($config)) ?? [];
    $queue = $slug !== null && $slug !== ''
        ? efpic_face_queue_stats_for_slug($config, $slug)
        : efpic_face_queue_stats($config);
    $messages = [];
    $hints = [];
    $recentlyActive = ($worker['last_seen_sec'] ?? null) !== null && $worker['last_seen_sec'] <= 300;
    $nasBusy = $queue['processing'] > 0 && $recentlyActive && $worker['status'] !== 'offline';
    $nasStalled = $queue['total'] > 0 && $worker['status'] === 'offline';

    if ($worker['last_seen_at'] === '') {
        $messages[] = 'Serveris vēl nav saņēmis nevienu signālu no Synology face worker.';
        $hints[] = 'NAS Container Manager: vai efpic-face-worker ir Running?';
        $hints[] = 'Pārbaudi .env — EFPIC_API_TOKEN (tas pats kā render worker) un EFPIC_API_BASE.';
        $hints[] = 'Serverī vajag EFPIC v1.9.128+ (api/face/ping).';
    } elseif ($worker['status'] === 'online') {
        $messages[] = 'NAS face worker ir redzams serverim (pēdējais signāls pirms '
            . $worker['last_seen_ago'] . ').';
    } elseif ($worker['status'] === 'stale') {
        if ($nasBusy) {
            $messages[] = 'NAS apstrādā indeksēšanu (pēdējais signāls pirms ' . $worker['last_seen_ago']
                . ') — viena bilde var aizņemt 2–8 min CPU.';
            $hints[] = 'Worker ņem tikai vienu bildi, pabeidz, tad nākamo. Claim tikai starp bildēm.';
        } else {
            $messages[] = 'NAS pēdējo reizi sazinājās pirms ' . $worker['last_seen_ago']
                . ' — vājš signāls, bet nesen aktīvs.';
            $hints[] = 'Worker parasti sūta claim ik ~15 s — pagaidi un spied «Pārbaudīt» vēlreiz.';
        }
    } else {
        $messages[] = 'NAS nav redzams serverim (pēdējais signāls pirms '
            . $worker['last_seen_ago'] . ').';
        $hints[] = 'Skaties NAS konteinera Logs — vai nav «claim failed»?';
        $hints[] = 'Recreate konteineri pēc .env labojuma.';
        $hints[] = 'Ja DSM kļūst neizmantojams: Stop face worker, indeksē tikai naktī; ekonomijas režīms (v1.9.136+) — 1 CPU, buffalo_s.';
        $hints[] = 'Laikā indeksēšanai apturi arī efpic-render-worker, ja tas darbojas uz tā paša NAS.';
    }

    if ($queue['processing'] > 1) {
        $hints[] = 'Vairāki «apstrādē» jobi = vecie iestrēgušie — tiks atgriezti rindā pēc '
            . intdiv(EFPIC_FACE_RECLAIM_SEC, 60) . ' min vai pēc claim.';
    }

    if ($queue['processing'] > 0) {
        $messages[] = 'Rindā apstrādē: ' . $queue['processing'] . ' job(s).';
    }
    if ($queue['queued'] > 0) {
        if ($nasStalled) {
            $messages[] = 'Gaida rindā: ' . $queue['queued'] . ' job(s) — worker izslēgts, rinda nestrādā.';
        } else {
            $messages[] = 'Gaida rindā: ' . $queue['queued'] . ' job(s) — vajag aktīvu worker.';
        }
    }

    if ($nasStalled) {
        $messages[] = 'Indeksēšanas rinda ir apturēta (NAS worker nav aktīvs).';
        $hints[] = 'Spied «Notīrīt rindu», lai dzēstu neapstrādātos jobus — jau indeksētās bildes paliek.';
        $hints[] = '«Gaida N bildes» = vēl nav indeksa; tas nav bloķējums, ja rinda ir tukša.';
    }

    return [
        'worker' => $worker,
        'queue' => $queue,
        'last_ping_at' => (string) ($state['last_ping_at'] ?? ''),
        'last_claim_at' => (string) ($state['last_claim_at'] ?? ''),
        'nas_visible' => $worker['status'] === 'online' || $worker['status'] === 'stale',
        'nas_online' => $worker['online'],
        'nas_busy' => $nasBusy,
        'nas_stalled' => $nasStalled,
        'messages' => $messages,
        'hints' => $hints,
    ];
}

function efpic_face_format_ago(?int $seconds): string
{
    if ($seconds === null || $seconds < 0) {
        return 'nav datu';
    }
    if ($seconds < 60) {
        return $seconds . ' sek.';
    }
    if ($seconds < 3600) {
        return intdiv($seconds, 60) . ' min';
    }

    return intdiv($seconds, 3600) . ' h ' . intdiv($seconds % 3600, 60) . ' min';
}

/** @return list<string> */
function efpic_face_list_queued_job_ids(array $config): array
{
    $dir = efpic_face_queue_dir($config);
    if (!is_dir($dir)) {
        return [];
    }
    $ids = [];
    foreach (scandir($dir) ?: [] as $entry) {
        if (!str_ends_with($entry, '.json')) {
            continue;
        }
        $job = efpic_read_json_file($dir . DIRECTORY_SEPARATOR . $entry);
        if (!is_array($job) || ($job['status'] ?? '') !== 'queued') {
            continue;
        }
        $ids[] = (string) ($job['id'] ?? substr($entry, 0, -5));
    }

    return $ids;
}

function efpic_face_reclaim_stuck_jobs(array $config, int $maxAgeSec = 1800): int
{
    $dir = efpic_face_queue_dir($config);
    if (!is_dir($dir)) {
        return 0;
    }
    $reclaimed = 0;
    $now = time();
    foreach (scandir($dir) ?: [] as $entry) {
        if (!str_ends_with($entry, '.json')) {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        $job = efpic_read_json_file($path);
        if (!is_array($job) || ($job['status'] ?? '') !== 'processing') {
            continue;
        }
        $claimedAt = strtotime((string) ($job['claimed_at'] ?? '')) ?: 0;
        if ($claimedAt <= 0 || ($now - $claimedAt) < $maxAgeSec) {
            continue;
        }
        $job['status'] = 'queued';
        unset($job['claimed_at']);
        $job['attempts'] = (int) ($job['attempts'] ?? 0) + 1;
        efpic_face_save_job($config, $job);
        $reclaimed++;
    }

    return $reclaimed;
}

/** @return array<string, mixed>|null */
function efpic_face_claim_next_job(array $config): ?array
{
    efpic_face_reclaim_stuck_jobs($config, EFPIC_FACE_RECLAIM_SEC);
    $candidates = [];
    foreach (efpic_face_list_queued_job_ids($config) as $jobId) {
        $job = efpic_face_load_job($config, $jobId);
        if (!is_array($job) || ($job['status'] ?? '') !== 'queued') {
            continue;
        }
        $priority = ($job['type'] ?? '') === 'search' ? 0 : 1;
        $created = strtotime((string) ($job['created_at'] ?? '')) ?: 0;
        $candidates[] = ['priority' => $priority, 'created' => $created, 'job' => $job];
    }
    if ($candidates === []) {
        return null;
    }
    usort($candidates, static fn ($a, $b) => $a['priority'] <=> $b['priority'] ?: $a['created'] <=> $b['created']);
    $job = $candidates[0]['job'];
    $job['status'] = 'processing';
    $job['claimed_at'] = gmdate('c');
    efpic_face_save_job($config, $job);

    $base = rtrim(efpic_base_url($config), '/');
    $jobId = (string) ($job['id'] ?? '');
    $payload = [
        'ok' => true,
        'job' => [
            'id' => $jobId,
            'type' => (string) ($job['type'] ?? 'index'),
            'slug' => (string) ($job['slug'] ?? ''),
            'threshold' => efpic_face_match_threshold($config),
        ],
    ];
    if (($job['type'] ?? '') === 'search') {
        $payload['job']['selfie_url'] = $base . '/api/face/jobs/' . $jobId . '/selfie';
    } else {
        $images = [];
        foreach ($job['images'] ?? [] as $img) {
            if (!is_array($img)) {
                continue;
            }
            $token = (string) ($img['token'] ?? '');
            if ($token === '') {
                continue;
            }
            $images[] = [
                'token' => $token,
                'url' => $base . '/api/face/jobs/' . $jobId . '/image/' . $token,
            ];
        }
        $payload['job']['images'] = $images;
    }

    return $payload;
}

/** @param list<float> $a @param list<float> $b */
function efpic_face_cosine_similarity(array $a, array $b): float
{
    if (count($a) !== count($b) || $a === []) {
        return 0.0;
    }
    $dot = 0.0;
    $na = 0.0;
    $nb = 0.0;
    foreach ($a as $i => $v) {
        $bv = $b[$i];
        $dot += $v * $bv;
        $na += $v * $v;
        $nb += $bv * $bv;
    }
    if ($na <= 0.0 || $nb <= 0.0) {
        return 0.0;
    }

    return $dot / (sqrt($na) * sqrt($nb));
}

/** @return list<float> */
function efpic_face_unpack_embedding(string $packed): array
{
    if ($packed === '') {
        return [];
    }
    $raw = base64_decode($packed, true);
    if ($raw === false || strlen($raw) % 4 !== 0) {
        return [];
    }
    $count = (int) (strlen($raw) / 4);
    if ($count !== EFPIC_FACE_EMBED_DIM) {
        return [];
    }
    $vals = unpack('f' . $count, $raw);
    if (!is_array($vals)) {
        return [];
    }

    return array_values(array_map('floatval', $vals));
}

/** @param list<float> $embedding */
function efpic_face_pack_embedding(array $embedding): string
{
    $bin = pack('f*', ...$embedding);

    return base64_encode($bin);
}

/**
 * @param list<array{v: string}> $queryFaces
 * @return list<string> image tokens sorted by best match
 */
function efpic_face_search_tokens(
    array $config,
    string $slug,
    array $queryFaces,
    float $threshold,
    ?array $allowedTokens = null,
): array {
    $index = efpic_face_load_index($config, $slug);
    if ($index === null) {
        return [];
    }
    $entries = $index['entries'] ?? [];
    if (!is_array($entries)) {
        return [];
    }

    $queries = [];
    foreach ($queryFaces as $face) {
        if (!is_array($face)) {
            continue;
        }
        $emb = efpic_face_unpack_embedding((string) ($face['v'] ?? ''));
        if ($emb !== []) {
            $queries[] = $emb;
        }
    }
    if ($queries === []) {
        return [];
    }

    $matches = [];
    foreach ($entries as $token => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $token = (string) $token;
        if ($allowedTokens !== null && !isset($allowedTokens[$token])) {
            continue;
        }
        $best = 0.0;
        foreach ($entry['faces'] ?? [] as $face) {
            if (!is_array($face)) {
                continue;
            }
            $emb = efpic_face_unpack_embedding((string) ($face['v'] ?? ''));
            if ($emb === []) {
                continue;
            }
            foreach ($queries as $query) {
                $best = max($best, efpic_face_cosine_similarity($query, $emb));
            }
        }
        if ($best >= $threshold) {
            $matches[$token] = $best;
        }
    }
    arsort($matches, SORT_NUMERIC);

    return array_keys($matches);
}

/** @param list<array{token: string, faces: list<array<string, mixed>>}> $results */
function efpic_face_apply_index_results(array $config, string $slug, array $meta, array $results): int
{
    $index = efpic_face_load_index($config, $slug) ?? [
        'version' => 1,
        'model' => 'insightface-buffalo_l',
        'dim' => EFPIC_FACE_EMBED_DIM,
        'entries' => [],
    ];
    if (!isset($index['entries']) || !is_array($index['entries'])) {
        $index['entries'] = [];
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

    $updated = 0;
    foreach ($results as $row) {
        if (!is_array($row)) {
            continue;
        }
        $token = (string) ($row['token'] ?? '');
        if ($token === '' || !isset($byToken[$token])) {
            continue;
        }
        $img = $byToken[$token];
        $faces = [];
        foreach ($row['faces'] ?? [] as $face) {
            if (!is_array($face)) {
                continue;
            }
            $packed = trim((string) ($face['v'] ?? ''));
            if ($packed === '') {
                continue;
            }
            $faces[] = [
                'v' => $packed,
                'bbox' => is_array($face['bbox'] ?? null) ? $face['bbox'] : [],
            ];
        }
        $index['entries'][$token] = [
            'source_key' => efpic_face_image_source_key($img),
            'faces' => $faces,
        ];
        $updated++;
    }

    if ($updated > 0) {
        efpic_face_save_index($config, $slug, $index);
        efpic_face_update_gallery_status($config, $slug, $meta);
        efpic_save_gallery_meta($config, $slug, $meta);
    }

    return $updated;
}

function efpic_face_enqueue_search(
    array $config,
    string $slug,
    string $galleryToken,
    string $selfieTmpPath,
): string {
    $jobId = bin2hex(random_bytes(16));
    $dir = efpic_face_queue_dir($config);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $dest = efpic_face_job_selfie_path($config, $jobId);
    if (!@copy($selfieTmpPath, $dest)) {
        throw new RuntimeException('Neizdevās saglabāt selfiju');
    }
    efpic_face_save_job($config, [
        'id' => $jobId,
        'type' => 'search',
        'status' => 'queued',
        'slug' => $slug,
        'gallery_token' => $galleryToken,
        'created_at' => gmdate('c'),
        'attempts' => 0,
        'threshold' => efpic_face_match_threshold($config),
        'result' => null,
    ]);

    return $jobId;
}

/** @return array{status: string, tokens: list<string>, count: int, error: string} */
function efpic_face_search_job_public_state(array $config, array $job): array
{
    $status = (string) ($job['status'] ?? 'queued');
    $result = $job['result'] ?? null;
    if ($status === 'complete' && is_array($result)) {
        $tokens = is_array($result['tokens'] ?? null) ? $result['tokens'] : [];

        return [
            'status' => 'complete',
            'tokens' => array_values(array_map('strval', $tokens)),
            'count' => count($tokens),
            'error' => '',
        ];
    }
    if ($status === 'failed') {
        return [
            'status' => 'failed',
            'tokens' => [],
            'count' => 0,
            'error' => (string) ($job['error'] ?? 'Meklēšana neizdevās'),
        ];
    }
    $created = strtotime((string) ($job['created_at'] ?? '')) ?: time();
    if (time() - $created > EFPIC_FACE_SEARCH_TIMEOUT_SEC) {
        return [
            'status' => 'failed',
            'tokens' => [],
            'count' => 0,
            'error' => 'Meklēšana pārāk ilga — pārbaudi, vai face worker darbojas.',
        ];
    }

    return ['status' => 'pending', 'tokens' => [], 'count' => 0, 'error' => ''];
}

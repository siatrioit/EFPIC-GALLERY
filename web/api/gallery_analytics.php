<?php

declare(strict_types=1);

const EFPIC_ANALYTICS_ONLINE_SECONDS = 120;
const EFPIC_ANALYTICS_SESSION_SECONDS = 1800;
const EFPIC_ANALYTICS_DEFAULT_DAYS = 14;

function efpic_analytics_dir(array $config): string
{
    return dirname(efpic_storage_path($config)) . DIRECTORY_SEPARATOR . 'analytics';
}

function efpic_analytics_galleries_dir(array $config): string
{
    return efpic_analytics_dir($config) . DIRECTORY_SEPARATOR . 'galleries';
}

function efpic_analytics_gallery_path(array $config, string $galleryToken): string
{
    $safe = preg_replace('/[^a-f0-9]/', '', strtolower($galleryToken));
    if ($safe === '') {
        throw new InvalidArgumentException('Nederīgs galerijas tokens');
    }

    return efpic_analytics_galleries_dir($config) . DIRECTORY_SEPARATOR . $safe . '.json';
}

/** @return array<string, mixed> */
function efpic_analytics_empty_gallery_record(string $galleryToken): array
{
    return [
        'gallery_token' => $galleryToken,
        'slug' => '',
        'name' => '',
        'deleted_at' => null,
        'totals' => [
            'unique_visitors' => 0,
            'album_views' => 0,
            'session_views' => 0,
            'downloads' => 0,
            'gallery_dl_web' => 0,
            'gallery_dl_print' => 0,
            'image_dl' => 0,
            'phone' => 0,
            'tablet' => 0,
            'desktop' => 0,
        ],
        'daily' => [],
        'visitors' => [],
        'presence' => [],
        'sessions' => [],
        'last_visit_at' => null,
    ];
}

function efpic_analytics_ensure_dirs(array $config): void
{
    $dir = efpic_analytics_galleries_dir($config);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

function efpic_analytics_detect_device(): string
{
    $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') {
        return 'desktop';
    }
    if (preg_match('/ipad|tablet|playbook|kindle|silk\/|sm-t\d/i', $ua) === 1) {
        return 'tablet';
    }
    if (preg_match('/android/i', $ua) === 1 && !preg_match('/mobile/i', $ua)) {
        return 'tablet';
    }
    if (preg_match('/mobile|iphone|ipod|android|blackberry|iemobile|opera mini|webos/i', $ua) === 1) {
        return 'phone';
    }

    return 'desktop';
}

function efpic_analytics_client_ip(): string
{
    return trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
}

function efpic_analytics_admin_ips_path(array $config): string
{
    return efpic_analytics_dir($config) . DIRECTORY_SEPARATOR . 'admin_ips.json';
}

function efpic_analytics_register_admin_ip(array $config): void
{
    $ip = efpic_analytics_client_ip();
    if ($ip === '') {
        return;
    }
    efpic_analytics_ensure_dirs($config);
    $path = efpic_analytics_admin_ips_path($config);
    $ips = [];
    if (is_file($path)) {
        $raw = efpic_read_json_file($path);
        if (is_array($raw)) {
            $ips = $raw;
        }
    }
    $ips[$ip] = time();
    $cutoff = time() - 86400 * 30;
    foreach ($ips as $storedIp => $ts) {
        if (!is_int($ts) || $ts < $cutoff) {
            unset($ips[$storedIp]);
        }
    }
    efpic_write_json_file($path, $ips);
}

function efpic_analytics_should_skip_tracking(array $config): bool
{
    $ip = efpic_analytics_client_ip();
    if ($ip === '') {
        return false;
    }
    $path = efpic_analytics_admin_ips_path($config);
    if (!is_file($path)) {
        return false;
    }
    $ips = efpic_read_json_file($path);
    if (!is_array($ips)) {
        return false;
    }
    $last = (int) ($ips[$ip] ?? 0);
    if ($last <= 0) {
        return false;
    }

    return (time() - $last) < 86400;
}

/** @param array<string, mixed> $totals */
function efpic_analytics_normalize_device_totals(array &$totals): void
{
    if (!isset($totals['phone'])) {
        $totals['phone'] = (int) ($totals['mobile'] ?? 0);
    }
    if (!isset($totals['tablet'])) {
        $totals['tablet'] = 0;
    }
    if (!isset($totals['desktop'])) {
        $totals['desktop'] = (int) ($totals['unknown'] ?? 0);
    }
    unset($totals['mobile'], $totals['unknown']);
}

function efpic_analytics_visitor_id(): string
{
    $name = 'efpic_vid';
    $existing = (string) ($_COOKIE[$name] ?? '');
    if (preg_match('/^[a-f0-9]{32}$/', $existing) === 1) {
        return $existing;
    }
    $id = bin2hex(random_bytes(16));
    setcookie($name, $id, [
        'expires' => time() + 86400 * 400,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[$name] = $id;

    return $id;
}

function efpic_analytics_is_new_session(string $galleryToken, string $visitorId): bool
{
    $name = 'efpic_gs_' . substr(hash('sha256', $galleryToken), 0, 12);
    $now = time();
    $raw = (string) ($_COOKIE[$name] ?? '');
    $parts = explode(':', $raw, 2);
    $sid = $parts[0] ?? '';
    $last = isset($parts[1]) ? (int) $parts[1] : 0;
    if ($sid !== '' && $last > 0 && ($now - $last) < EFPIC_ANALYTICS_SESSION_SECONDS) {
        setcookie($name, $sid . ':' . $now, [
            'expires' => time() + 86400 * 30,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[$name] = $sid . ':' . $now;

        return false;
    }
    $sid = bin2hex(random_bytes(8));
    setcookie($name, $sid . ':' . $now, [
        'expires' => time() + 86400 * 30,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[$name] = $sid . ':' . $now;

    return true;
}

function efpic_analytics_today_key(): string
{
    return date('Y-m-d');
}

/** @param callable(array<string, mixed>): void $mutator */
function efpic_analytics_update_gallery(array $config, string $galleryToken, callable $mutator): void
{
    efpic_analytics_ensure_dirs($config);
    $path = efpic_analytics_gallery_path($config, $galleryToken);
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $fp = fopen($path, 'c+');
    if ($fp === false) {
        throw new RuntimeException('Nevar atvērt analītikas failu');
    }
    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('Nevar bloķēt analītikas failu');
        }
        rewind($fp);
        $raw = stream_get_contents($fp);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            $data = efpic_analytics_empty_gallery_record($galleryToken);
        }
        $mutator($data);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Analītikas serializācijas kļūda');
        }
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, $json . "\n");
        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }
}

/** @return array<string, mixed>|null */
function efpic_analytics_load_gallery(array $config, string $galleryToken): ?array
{
    if ($galleryToken === '') {
        return null;
    }
    $path = efpic_analytics_gallery_path($config, $galleryToken);
    if (!is_file($path)) {
        return null;
    }
    $data = efpic_read_json_file($path);

    return is_array($data) ? $data : null;
}

function efpic_analytics_sync_gallery_meta(array &$record, array $meta, string $slug): void
{
    $record['gallery_token'] = (string) ($meta['gallery_token'] ?? $record['gallery_token'] ?? '');
    $record['slug'] = $slug;
    $record['name'] = (string) ($meta['name'] ?? $record['name'] ?? $slug);
    if (!efpic_gallery_is_active($meta)) {
        $record['deleted_at'] = (string) ($meta['deleted_at'] ?? $record['deleted_at'] ?? gmdate('c'));
    } else {
        $record['deleted_at'] = null;
    }
}

function efpic_analytics_seed_from_meta(array &$record, array $meta): void
{
    $legacyViews = (int) (($meta['analytics']['views'] ?? 0));
    $legacyDownloads = (int) (($meta['analytics']['downloads'] ?? 0));
    if ($legacyViews > (int) ($record['totals']['album_views'] ?? 0)) {
        $record['totals']['album_views'] = $legacyViews;
    }
    if ($legacyDownloads > (int) ($record['totals']['downloads'] ?? 0)) {
        $record['totals']['downloads'] = $legacyDownloads;
    }
}

/** @param array<string, mixed> $record */
function efpic_analytics_prune_presence(array &$record): void
{
    $now = time();
    $presence = $record['presence'] ?? [];
    if (!is_array($presence)) {
        $presence = [];
    }
    foreach ($presence as $vid => $ts) {
        if (!is_int($ts)) {
            $ts = (int) $ts;
        }
        if (($now - $ts) > EFPIC_ANALYTICS_ONLINE_SECONDS) {
            unset($presence[$vid]);
        }
    }
    $record['presence'] = $presence;
}

function efpic_analytics_record_view(array $config, string $slug, array $meta): void
{
    if (efpic_analytics_should_skip_tracking($config)) {
        return;
    }
    $galleryToken = (string) ($meta['gallery_token'] ?? '');
    if ($galleryToken === '') {
        return;
    }

    $visitorId = efpic_analytics_visitor_id();
    $device = efpic_analytics_detect_device();
    $isNewSession = efpic_analytics_is_new_session($galleryToken, $visitorId);
    $today = efpic_analytics_today_key();
    $nowIso = gmdate('c');
    $now = time();

    efpic_analytics_update_gallery($config, $galleryToken, static function (array &$record) use (
        $meta,
        $slug,
        $visitorId,
        $device,
        $isNewSession,
        $today,
        $nowIso,
        $now
    ): void {
        efpic_analytics_sync_gallery_meta($record, $meta, $slug);
        efpic_analytics_seed_from_meta($record, $meta);
        efpic_analytics_prune_presence($record);

        if (!isset($record['totals']) || !is_array($record['totals'])) {
            $record['totals'] = efpic_analytics_empty_gallery_record($record['gallery_token'])['totals'];
        }
        efpic_analytics_normalize_device_totals($record['totals']);
        if (!isset($record['daily']) || !is_array($record['daily'])) {
            $record['daily'] = [];
        }
        if (!isset($record['visitors']) || !is_array($record['visitors'])) {
            $record['visitors'] = [];
        }
        if (!isset($record['daily'][$today]) || !is_array($record['daily'][$today])) {
            $record['daily'][$today] = [
                'unique' => 0,
                'album_views' => 0,
                'session_views' => 0,
                'downloads' => 0,
                'visitor_ids' => [],
            ];
        }

        $day = &$record['daily'][$today];
        if (!isset($day['visitor_ids']) || !is_array($day['visitor_ids'])) {
            $day['visitor_ids'] = [];
        }

        $record['totals']['album_views'] = (int) ($record['totals']['album_views'] ?? 0) + 1;
        $day['album_views'] = (int) ($day['album_views'] ?? 0) + 1;

        if ($isNewSession) {
            $record['totals']['session_views'] = (int) ($record['totals']['session_views'] ?? 0) + 1;
            $day['session_views'] = (int) ($day['session_views'] ?? 0) + 1;
        }

        $isNewVisitor = !isset($record['visitors'][$visitorId]);
        if ($isNewVisitor) {
            $record['visitors'][$visitorId] = [
                'device' => $device,
                'first_at' => $nowIso,
                'last_at' => $nowIso,
            ];
            $record['totals']['unique_visitors'] = (int) ($record['totals']['unique_visitors'] ?? 0) + 1;
            $record['totals'][$device] = (int) ($record['totals'][$device] ?? 0) + 1;
        } else {
            $record['visitors'][$visitorId]['last_at'] = $nowIso;
        }

        if (!isset($day['visitor_ids'][$visitorId])) {
            $day['visitor_ids'][$visitorId] = true;
            $day['unique'] = (int) ($day['unique'] ?? 0) + 1;
        }

        $record['presence'][$visitorId] = $now;
        $record['last_visit_at'] = $nowIso;
    });
}

/** @return list<string> */
function efpic_analytics_download_counter_keys(string $type, string $detail): array
{
    if ($type === 'download_image') {
        return ['image_dl'];
    }
    if ($type !== 'download_zip') {
        return [];
    }
    if (stripos($detail, 'Visa galerija') === false) {
        return [];
    }
    if (preg_match('/\((web|full|both)\)/i', $detail, $m) !== 1) {
        return [];
    }
    $size = strtolower($m[1]);
    if ($size === 'web') {
        return ['gallery_dl_web'];
    }
    if ($size === 'full') {
        return ['gallery_dl_print'];
    }

    return ['gallery_dl_web', 'gallery_dl_print'];
}

/** @param array<string, mixed> $totals */
function efpic_analytics_normalize_download_totals(array &$totals): void
{
    foreach (['gallery_dl_web', 'gallery_dl_print', 'image_dl'] as $key) {
        if (!isset($totals[$key])) {
            $totals[$key] = 0;
        }
    }
}

function efpic_analytics_record_download(array $config, string $slug, array $meta, string $type = '', string $detail = ''): void
{
    if (efpic_analytics_should_skip_tracking($config)) {
        return;
    }
    $galleryToken = (string) ($meta['gallery_token'] ?? '');
    if ($galleryToken === '') {
        return;
    }
    $today = efpic_analytics_today_key();
    $counterKeys = efpic_analytics_download_counter_keys($type, $detail);

    efpic_analytics_update_gallery($config, $galleryToken, static function (array &$record) use ($meta, $slug, $today, $counterKeys): void {
        efpic_analytics_sync_gallery_meta($record, $meta, $slug);
        if (!isset($record['totals']) || !is_array($record['totals'])) {
            $record['totals'] = efpic_analytics_empty_gallery_record($record['gallery_token'])['totals'];
        }
        efpic_analytics_normalize_device_totals($record['totals']);
        efpic_analytics_normalize_download_totals($record['totals']);
        if (!isset($record['daily']) || !is_array($record['daily'])) {
            $record['daily'] = [];
        }
        if (!isset($record['daily'][$today]) || !is_array($record['daily'][$today])) {
            $record['daily'][$today] = [
                'unique' => 0,
                'album_views' => 0,
                'session_views' => 0,
                'downloads' => 0,
                'visitor_ids' => [],
            ];
        }
        $record['totals']['downloads'] = (int) ($record['totals']['downloads'] ?? 0) + 1;
        $record['daily'][$today]['downloads'] = (int) ($record['daily'][$today]['downloads'] ?? 0) + 1;
        foreach ($counterKeys as $key) {
            $record['totals'][$key] = (int) ($record['totals'][$key] ?? 0) + 1;
        }
    });
}

function efpic_analytics_mark_gallery_deleted(array $config, string $galleryToken, string $slug, string $name): void
{
    if ($galleryToken === '') {
        return;
    }
    efpic_analytics_update_gallery($config, $galleryToken, static function (array &$record) use ($galleryToken, $slug, $name): void {
        $record['gallery_token'] = $galleryToken;
        $record['slug'] = $slug;
        $record['name'] = $name;
        $record['deleted_at'] = gmdate('c');
    });
}

function efpic_analytics_mark_gallery_restored(array $config, string $galleryToken): void
{
    if ($galleryToken === '') {
        return;
    }
    efpic_analytics_update_gallery($config, $galleryToken, static function (array &$record): void {
        $record['deleted_at'] = null;
    });
}

function efpic_analytics_ensure_gallery_record(array $config, string $slug, array $meta): void
{
    $galleryToken = (string) ($meta['gallery_token'] ?? '');
    if ($galleryToken === '') {
        return;
    }
    efpic_analytics_update_gallery($config, $galleryToken, static function (array &$record) use ($meta, $slug): void {
        efpic_analytics_sync_gallery_meta($record, $meta, $slug);
        efpic_analytics_seed_from_meta($record, $meta);
    });
}

function efpic_analytics_archive_before_purge(array $config, string $slug, array $meta): void
{
    $galleryToken = (string) ($meta['gallery_token'] ?? '');
    if ($galleryToken === '') {
        return;
    }
    efpic_analytics_update_gallery($config, $galleryToken, static function (array &$record) use ($meta, $slug): void {
        efpic_analytics_sync_gallery_meta($record, $meta, $slug);
        efpic_analytics_seed_from_meta($record, $meta);
        $record['deleted_at'] = gmdate('c');
        $record['purged_at'] = gmdate('c');
    });
}

/** @return list<array<string, mixed>> */
function efpic_analytics_list_gallery_records(array $config): array
{
    $dir = efpic_analytics_galleries_dir($config);
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach (scandir($dir) ?: [] as $file) {
        if (!str_ends_with($file, '.json')) {
            continue;
        }
        $data = efpic_read_json_file($dir . DIRECTORY_SEPARATOR . $file);
        if (!is_array($data)) {
            continue;
        }
        efpic_analytics_prune_presence($data);
        $out[] = $data;
    }
    usort($out, static function (array $a, array $b): int {
        $na = (string) ($a['name'] ?? '');
        $nb = (string) ($b['name'] ?? '');

        return strnatcasecmp($na, $nb);
    });

    return $out;
}

function efpic_analytics_parse_date_range(?string $from, ?string $to, int $defaultDays = EFPIC_ANALYTICS_DEFAULT_DAYS): array
{
    $toDate = ($to !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1)
        ? $to
        : date('Y-m-d');
    $fromDate = ($from !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1)
        ? $from
        : date('Y-m-d', strtotime($toDate . ' -' . max(1, $defaultDays - 1) . ' days'));
    if ($fromDate > $toDate) {
        [$fromDate, $toDate] = [$toDate, $fromDate];
    }

    return [$fromDate, $toDate];
}

/** @param list<array<string, mixed>> $records */
function efpic_analytics_aggregate(array $records, string $fromDate, string $toDate): array
{
    $summary = [
        'online' => 0,
        'unique_visitors' => 0,
        'phone' => 0,
        'tablet' => 0,
        'desktop' => 0,
        'today' => 0,
        'album_views' => 0,
        'session_views' => 0,
        'downloads' => 0,
        'daily' => [],
        'last_visit_at' => null,
        'galleries' => [],
    ];
    $today = efpic_analytics_today_key();

    $cursor = strtotime($fromDate);
    $end = strtotime($toDate);
    while ($cursor !== false && $cursor <= $end) {
        $key = date('Y-m-d', $cursor);
        $summary['daily'][$key] = [
            'unique' => 0,
            'album_views' => 0,
            'session_views' => 0,
            'downloads' => 0,
        ];
        $cursor = strtotime('+1 day', $cursor);
    }

    foreach ($records as $record) {
        efpic_analytics_prune_presence($record);
        $totals = is_array($record['totals'] ?? null) ? $record['totals'] : [];
        efpic_analytics_normalize_device_totals($totals);
        efpic_analytics_normalize_download_totals($totals);
        $daily = is_array($record['daily'] ?? null) ? $record['daily'] : [];
        $presence = is_array($record['presence'] ?? null) ? $record['presence'] : [];

        $galleryOnline = count($presence);
        $summary['online'] += $galleryOnline;

        $galleryUnique = 0;
        $galleryAlbum = 0;
        $gallerySession = 0;
        $galleryDownloads = 0;
        $galleryToday = 0;

        foreach ($daily as $dayKey => $day) {
            if (!is_array($day) || $dayKey < $fromDate || $dayKey > $toDate) {
                continue;
            }
            if (!isset($summary['daily'][$dayKey])) {
                $summary['daily'][$dayKey] = [
                    'unique' => 0,
                    'album_views' => 0,
                    'session_views' => 0,
                    'downloads' => 0,
                ];
            }
            $summary['daily'][$dayKey]['unique'] += (int) ($day['unique'] ?? 0);
            $summary['daily'][$dayKey]['album_views'] += (int) ($day['album_views'] ?? 0);
            $summary['daily'][$dayKey]['session_views'] += (int) ($day['session_views'] ?? 0);
            $summary['daily'][$dayKey]['downloads'] += (int) ($day['downloads'] ?? 0);

            $galleryUnique += (int) ($day['unique'] ?? 0);
            $galleryAlbum += (int) ($day['album_views'] ?? 0);
            $gallerySession += (int) ($day['session_views'] ?? 0);
            $galleryDownloads += (int) ($day['downloads'] ?? 0);
            if ($dayKey === $today) {
                $galleryToday = (int) ($day['unique'] ?? 0);
            }
        }

        if ($daily === []) {
            $galleryToday = 0;
        }

        $summary['unique_visitors'] += $galleryUnique;
        $summary['album_views'] += $galleryAlbum;
        $summary['session_views'] += $gallerySession;
        $summary['downloads'] += $galleryDownloads;
        $summary['today'] += $galleryToday;
        $summary['phone'] += (int) ($totals['phone'] ?? 0);
        $summary['tablet'] += (int) ($totals['tablet'] ?? 0);
        $summary['desktop'] += (int) ($totals['desktop'] ?? 0);

        $lastVisit = (string) ($record['last_visit_at'] ?? '');
        if ($lastVisit !== '' && ($summary['last_visit_at'] === null || $lastVisit > $summary['last_visit_at'])) {
            $summary['last_visit_at'] = $lastVisit;
        }

        $summary['galleries'][] = [
            'gallery_token' => (string) ($record['gallery_token'] ?? ''),
            'slug' => (string) ($record['slug'] ?? ''),
            'name' => (string) ($record['name'] ?? ''),
            'deleted_at' => $record['deleted_at'] ?? null,
            'online' => $galleryOnline,
            'today' => $galleryToday,
            'unique_visitors' => (int) ($totals['unique_visitors'] ?? 0),
            'album_views' => (int) ($totals['album_views'] ?? 0),
            'session_views' => (int) ($totals['session_views'] ?? 0),
            'downloads' => (int) ($totals['downloads'] ?? 0),
            'gallery_dl_web' => (int) ($totals['gallery_dl_web'] ?? 0),
            'gallery_dl_print' => (int) ($totals['gallery_dl_print'] ?? 0),
            'image_dl' => (int) ($totals['image_dl'] ?? 0),
            'period_unique' => $galleryUnique,
            'period_album_views' => $galleryAlbum,
            'period_session_views' => $gallerySession,
            'period_downloads' => $galleryDownloads,
        ];
    }

    ksort($summary['daily']);

    return $summary;
}

function efpic_analytics_device_percent(int $count, int $total): int
{
    if ($total <= 0) {
        return 0;
    }

    return (int) round($count * 100 / $total);
}

function efpic_analytics_format_last_visit(?string $iso): string
{
    if ($iso === null || $iso === '') {
        return '—';
    }
    $ts = strtotime($iso);
    if ($ts === false) {
        return $iso;
    }

    return date('Y-m-d H:i', $ts);
}

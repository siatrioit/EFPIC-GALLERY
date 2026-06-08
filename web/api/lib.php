<?php

declare(strict_types=1);

function efpic_app_version(): string
{
    static $cached = null;
    if (is_string($cached)) {
        return $cached;
    }

    $path = __DIR__ . '/VERSION';
    if (!is_file($path)) {
        $cached = '0.0.0';

        return $cached;
    }

    $raw = trim((string) file_get_contents($path));
    if ($raw === '' || preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $raw) !== 1) {
        $cached = '0.0.0';

        return $cached;
    }

    $cached = $raw;

    return $cached;
}

function efpic_format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }

    return $bytes . ' B';
}

function efpic_app_version_label(): string
{
    return 'v' . efpic_app_version();
}

function efpic_asset_url(string $path, ?string $base = null): string
{
    $path = '/' . ltrim($path, '/');
    $query = 'v=' . rawurlencode(efpic_app_version());
    if ($base === null || $base === '') {
        return $path . '?' . $query;
    }

    return rtrim($base, '/') . $path . '?' . $query;
}

function efpic_stream_versioned_public_asset(string $filesystemPath, string $contentType): void
{
    header('Content-Type: ' . $contentType . '; charset=utf-8');
    header('Cache-Control: public, max-age=31536000, immutable');
    header('ETag: W/"efpic-' . efpic_app_version() . '"');
    readfile($filesystemPath);
    exit;
}

/** Stream a local file with HTTP Range support (required for HTML5 video/audio). */
function efpic_stream_local_file(string $path, string $contentType): void
{
    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }

    $size = filesize($path);
    if ($size === false) {
        http_response_code(500);
        exit;
    }

    header('Content-Type: ' . $contentType);
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=86400');

    $start = 0;
    $end = $size - 1;
    $httpStatus = 200;

    $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
    if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $m) === 1) {
        if ($m[1] === '' && $m[2] !== '') {
            $suffix = (int) $m[2];
            $start = max(0, $size - $suffix);
            $end = $size - 1;
        } elseif ($m[1] !== '') {
            $start = (int) $m[1];
            $end = $m[2] !== '' ? (int) $m[2] : $size - 1;
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . (string) $size);
            exit;
        }
        if ($end >= $size) {
            $end = $size - 1;
        }
        $httpStatus = 206;
    }

    $length = $end - $start + 1;
    http_response_code($httpStatus);
    if ($httpStatus === 206) {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . (string) $size);
    }
    header('Content-Length: ' . (string) $length);

    $fp = fopen($path, 'rb');
    if ($fp === false) {
        http_response_code(500);
        exit;
    }
    fseek($fp, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($fp)) {
        $chunk = fread($fp, min(8192, $remaining));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
    }
    fclose($fp);
    exit;
}

function efpic_load_config(): array
{
    $path = dirname(__DIR__) . '/config/config.php';
    if (!is_file($path)) {
        $path = dirname(__DIR__) . '/config/config.example.php';
    }
    $cfg = require $path;

    return is_array($cfg) ? $cfg : [];
}

function efpic_base_url(array $config): string
{
    return rtrim((string) ($config['base_url'] ?? ''), '/');
}

function efpic_storage_path(array $config): string
{
    $p = (string) ($config['storage_path'] ?? '');
    if ($p === '') {
        $p = dirname(__DIR__) . '/storage/galleries';
    }

    return rtrim($p, '/\\');
}

function efpic_booth_path(array $config): string
{
    $p = (string) ($config['booth_events_path'] ?? '');
    if ($p === '') {
        $p = dirname(__DIR__) . '/storage/booth_events';
    }

    return rtrim($p, '/\\');
}

function efpic_templates_path(array $config): string
{
    $p = (string) ($config['templates_path'] ?? '');
    if ($p === '') {
        $p = dirname(__DIR__) . '/storage/gallery_templates';
    }

    return rtrim($p, '/\\');
}

function efpic_access_index_path(array $config): string
{
    return dirname(efpic_storage_path($config)) . '/access_index.json';
}

function efpic_app_settings_path(array $config): string
{
    return dirname(efpic_storage_path($config)) . '/app_settings.json';
}

/** @return array{gallery_byline: string, gallery_page_bg: string, gallery_feed_gap: int, gallery_feed_gap_tablet: int, gallery_feed_gap_desktop: int, updated_at: ?string} */
function efpic_app_settings_defaults(): array
{
    return [
        'gallery_byline' => 'Gallery by EdgarsFoto',
        'gallery_page_bg' => '#ffffff',
        'gallery_feed_gap' => 16,
        'gallery_feed_gap_tablet' => 20,
        'gallery_feed_gap_desktop' => 24,
        'updated_at' => null,
    ];
}

function efpic_sanitize_gallery_feed_gap(mixed $value, int $fallback = 16): int
{
    if (!is_numeric($value)) {
        return max(0, min(120, $fallback));
    }

    return max(0, min(120, (int) round((float) $value)));
}

/** @return array<string, string> */
function efpic_gallery_theme_options(): array
{
    return [
        'efpic-modern' => 'Modern',
        'efpic-mood' => 'Mood',
        'efpic-forest' => 'Forest',
        'efpic-classic' => 'Classic',
    ];
}

function efpic_is_valid_gallery_theme(string $theme): bool
{
    return array_key_exists($theme, efpic_gallery_theme_options());
}

function efpic_normalize_gallery_theme(string $theme): string
{
    $theme = strtolower(trim($theme));
    $legacy = [
        'pic-time' => 'efpic-modern',
        'pic_time' => 'efpic-modern',
        'classic' => 'efpic-classic',
        'masonry' => 'efpic-mood',
        'dark' => 'efpic-mood',
    ];
    if (isset($legacy[$theme])) {
        return $legacy[$theme];
    }
    if (efpic_is_valid_gallery_theme($theme)) {
        return $theme;
    }

    return 'efpic-modern';
}

function efpic_is_modern_gallery_theme(string $theme): bool
{
    return efpic_normalize_gallery_theme($theme) === 'efpic-modern';
}

function efpic_is_classic_gallery_theme(string $theme): bool
{
    return efpic_normalize_gallery_theme($theme) === 'efpic-classic';
}

function efpic_uses_mosaic_feed_theme(string $theme): bool
{
    return in_array(efpic_normalize_gallery_theme($theme), ['efpic-modern', 'efpic-mood', 'efpic-forest'], true);
}

/** Intro vāks, pilna platuma galerija un modern skatītājs (visas 4 tēmas). */
function efpic_uses_full_gallery_shell(string $theme): bool
{
    return in_array(efpic_normalize_gallery_theme($theme), ['efpic-modern', 'efpic-mood', 'efpic-forest', 'efpic-classic'], true);
}

/** Fixed mosaic columns per theme; 0 = responsive (see client.js for per-theme ranges). */
function efpic_gallery_theme_mosaic_columns(string $theme): int
{
    return 0;
}

/** Max responsive columns when mosaic_columns is 0. */
function efpic_gallery_theme_mosaic_max_columns(string $theme): int
{
    return match (efpic_normalize_gallery_theme($theme)) {
        'efpic-forest' => 3,
        default => 4,
    };
}

function efpic_uses_mosaic_slideshow_ui(string $theme): bool
{
    return efpic_uses_mosaic_feed_theme($theme);
}

function efpic_gallery_effective_theme(array $meta): string
{
    $clientTheme = (string) ($meta['client_theme'] ?? '');
    if ($clientTheme !== '') {
        return efpic_normalize_gallery_theme($clientTheme);
    }

    return efpic_normalize_gallery_theme((string) ($meta['theme'] ?? 'efpic-modern'));
}

function efpic_theme_default_page_bg(string $theme): string
{
    return match (efpic_normalize_gallery_theme($theme)) {
        'efpic-mood' => '#111111',
        'efpic-forest' => '#f2f6f0',
        'efpic-classic' => '#f0f0f0',
        'efpic-modern' => '#ffffff',
        default => '#ffffff',
    };
}

function efpic_load_app_settings(array $config): array
{
    $defaults = efpic_app_settings_defaults();
    $data = efpic_read_json_file(efpic_app_settings_path($config));
    if (!is_array($data)) {
        return $defaults;
    }

    return array_merge($defaults, $data);
}

function efpic_save_app_settings(array $config, array $settings): void
{
    $merged = array_merge(efpic_app_settings_defaults(), $settings);
    $merged['updated_at'] = gmdate('c');
    efpic_write_json_file(efpic_app_settings_path($config), $merged);
}

function efpic_json_response(int $code, array $data): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function efpic_require_token(array $config): void
{
    $expected = (string) ($config['api_token'] ?? '');
    if ($expected === '' || $expected === 'change-me-long-random-string') {
        efpic_json_response(503, ['ok' => false, 'error' => 'api_not_configured']);
    }
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        efpic_json_response(401, ['ok' => false, 'error' => 'unauthorized']);
    }
    if (!hash_equals($expected, trim($m[1]))) {
        efpic_json_response(401, ['ok' => false, 'error' => 'unauthorized']);
    }
}

function efpic_random_hex(int $bytes): string
{
    return bin2hex(random_bytes($bytes));
}

function efpic_slugify(string $name): string
{
    $s = strtolower(trim($name));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');

    return $s !== '' ? $s : 'gallery';
}

function efpic_read_json_file(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);

    return is_array($data) ? $data : null;
}

function efpic_write_json_file(string $path, array $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('JSON encode failed');
    }
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write ' . $path);
    }
    rename($tmp, $path);
}

function efpic_gallery_dir(array $config, string $slug): string
{
    return efpic_storage_path($config) . DIRECTORY_SEPARATOR . $slug;
}

function efpic_gallery_meta_path(array $config, string $slug): string
{
    return efpic_gallery_dir($config, $slug) . DIRECTORY_SEPARATOR . 'meta.json';
}

function efpic_list_gallery_slugs(array $config): array
{
    $root = efpic_storage_path($config);
    if (!is_dir($root)) {
        return [];
    }
    $out = [];
    foreach (scandir($root) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (is_file($root . DIRECTORY_SEPARATOR . $entry . DIRECTORY_SEPARATOR . 'meta.json')) {
            $out[] = $entry;
        }
    }
    sort($out);

    return $out;
}

function efpic_load_gallery_meta(array $config, string $slug): ?array
{
    return efpic_read_json_file(efpic_gallery_meta_path($config, $slug));
}

function efpic_save_gallery_meta(array $config, string $slug, array $meta): void
{
    efpic_write_json_file(efpic_gallery_meta_path($config, $slug), $meta);
    efpic_rebuild_access_index($config);
}

function efpic_rebuild_access_index(array $config): void
{
    $index = ['galleries' => [], 'images' => []];
    foreach (efpic_list_gallery_slugs($config) as $slug) {
        $meta = efpic_load_gallery_meta($config, $slug);
        if ($meta === null) {
            continue;
        }
        if (!efpic_gallery_is_active($meta)) {
            continue;
        }
        $gt = (string) ($meta['gallery_token'] ?? '');
        if ($gt !== '') {
            $index['galleries'][$gt] = $slug;
        }
        foreach ($meta['images'] ?? [] as $img) {
            if (!is_array($img)) {
                continue;
            }
            $tok = (string) ($img['token'] ?? '');
            if ($tok !== '') {
                $index['images'][$tok] = ['slug' => $slug, 'file' => $img['file'] ?? ''];
            }
        }
    }
    efpic_write_json_file(efpic_access_index_path($config), $index);
}

function efpic_load_access_index(array $config): array
{
    $idx = efpic_read_json_file(efpic_access_index_path($config));
    if ($idx === null) {
        return ['galleries' => [], 'images' => []];
    }

    return $idx;
}

function efpic_gallery_defaults(string $type = 'live'): array
{
    return [
        'type' => $type,
        'name' => '',
        'gallery_token' => efpic_random_hex(24),
        'password_hash' => '',
        'restrict_gallery_from_single_link' => false,
        'theme' => $type === 'delivery' ? 'efpic-modern' : 'efpic-classic',
        'client_theme' => null,
        'status' => 'active',
        'deleted_at' => null,
        'event_date' => null,
        'cover_image_token' => null,
        'cover_from_favorites' => false,
        'cover_layout' => 'right',
        'cover_focal_x' => 50,
        'cover_focal_y' => 50,
        'mood_font_family' => 'serif',
        'mood_date_format' => 'lv',
        'mood_title_font_size' => 'md',
        'mood_date_font_size' => 'md',
        'hero_accent_color' => '#9a9578',
        'page_bg_color' => null,
        'images' => [],
        'slideshow' => [
            'admin' => [
                'enabled' => false,
                'audio_file' => '',
                'interval_sec' => 5,
            ],
            'client' => [
                'enabled' => false,
                'audio_file' => '',
                'interval_sec' => 5,
            ],
        ],
        'videos' => [],
        'scenes' => [
            [
                'id' => 'main',
                'title' => 'Galerija',
                'sort' => 1,
                'header_image_token' => null,
                'hidden_from_guests' => false,
            ],
        ],
        'collections' => [],
        'comments' => [],
        'guests' => [],
        'access_requests' => [],
        'settings' => [
            'allow_client_sharing' => true,
            'client_comments_enabled' => false,
            'favorites_visible_to_guests' => false,
            'allow_access_requests' => false,
            'hide_client_hidden_from_public' => true,
            'expires_at' => null,
            'downloads' => [
                'main_client' => ['web', 'full', 'zip'],
                'guest' => ['web', 'full', 'zip'],
                'public' => ['web', 'full', 'zip'],
            ],
            'disable_public_download_all_web' => false,
            'disable_public_download_all_full' => false,
        ],
        'analytics' => [
            'views' => 0,
            'downloads' => 0,
            'client_first_view_at' => null,
        ],
        'failiem' => [
            'folder_parent_hash' => '',
            'folder_parent_url' => '',
            'folder_full_hash' => '',
            'folder_web_hash' => '',
            'folder_full_url' => '',
            'folder_web_url' => '',
            'pair_suffix_strip' => [],
            'last_sync_at' => null,
            'sync_stats' => null,
        ],
        'client_access' => [
            'email' => '',
            'password_hash' => '',
            'portal_token' => efpic_random_hex(24),
        ],
    ];
}

function efpic_hash_password(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

function efpic_verify_password_hash(string $password, string $hash): bool
{
    if ($hash === '') {
        return false;
    }

    return password_verify($password, $hash);
}

function efpic_is_delivery_gallery(array $meta): bool
{
    return ($meta['type'] ?? '') === 'delivery';
}

function efpic_gallery_status(array $meta): string
{
    return ($meta['status'] ?? 'active') === 'deleted' ? 'deleted' : 'active';
}

function efpic_gallery_is_active(array $meta): bool
{
    return efpic_gallery_status($meta) === 'active';
}

function efpic_soft_delete_gallery(array $config, string $slug): void
{
    $meta = efpic_load_gallery_meta($config, $slug);
    if ($meta === null) {
        throw new RuntimeException('Galerija nav atrasta');
    }
    $meta['status'] = 'deleted';
    $meta['deleted_at'] = gmdate('c');
    efpic_save_gallery_meta($config, $slug, $meta);
}

function efpic_restore_gallery(array $config, string $slug): void
{
    $meta = efpic_load_gallery_meta($config, $slug);
    if ($meta === null) {
        throw new RuntimeException('Galerija nav atrasta');
    }
    $meta['status'] = 'active';
    $meta['deleted_at'] = null;
    efpic_save_gallery_meta($config, $slug, $meta);
}

function efpic_purge_gallery(array $config, string $slug): void
{
    $dir = efpic_gallery_dir($config, $slug);
    if (!is_dir($dir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($dir);
    efpic_rebuild_access_index($config);
}

/** @return array{0: int, 1: string, 2: string} Numeric key + basename for stable natural order. */
function efpic_image_basename_sort_key(array $img): array
{
    $name = (string) ($img['basename'] ?? '');
    if ($name === '' && is_array($img['failiem_full'] ?? null)) {
        $name = (string) ($img['failiem_full']['name'] ?? '');
    }
    if ($name === '' && is_array($img['failiem_web'] ?? null)) {
        $name = (string) ($img['failiem_web']['name'] ?? '');
    }
    $base = pathinfo($name, PATHINFO_FILENAME);
    $base = (string) preg_replace('/_(PRINT|WEB)$/i', '', $base);
    if (preg_match('/(\d+)\s*$/', $base, $m) === 1) {
        return [(int) $m[1], strtolower($base), strtolower($name)];
    }
    $pairKey = (string) ($img['pair_key'] ?? '');
    if ($pairKey !== '' && ctype_digit($pairKey)) {
        return [(int) $pairKey, strtolower($base), strtolower($name)];
    }

    return [PHP_INT_MAX, strtolower($base), strtolower($name)];
}

/** Natural compare for image basenames (EdgarsFoto_PRINT_1002 …). */
function efpic_compare_image_basenames(array $a, array $b): int
{
    return efpic_image_basename_sort_key($a) <=> efpic_image_basename_sort_key($b);
}

function efpic_admin_session_active(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    return !empty($_SESSION['efpic_admin']);
}

/** Atjauno access_index, ja galerija vai bildes nav indeksā. */
function efpic_ensure_gallery_indexed(array $config, string $slug, array $meta): void
{
    $index = efpic_load_access_index($config);
    $gt = (string) ($meta['gallery_token'] ?? '');
    if ($gt !== '' && (($index['galleries'][$gt] ?? '') !== $slug)) {
        efpic_rebuild_access_index($config);

        return;
    }
    foreach ($meta['images'] ?? [] as $img) {
        if (!is_array($img)) {
            continue;
        }
        $tok = (string) ($img['token'] ?? '');
        if ($tok !== '' && !isset($index['images'][$tok])) {
            efpic_rebuild_access_index($config);

            return;
        }
    }
}

function efpic_gallery_settings(array $meta): array
{
    $s = $meta['settings'] ?? [];

    return is_array($s) ? $s : [];
}

function efpic_client_comments_enabled(array $meta): bool
{
    return !empty(efpic_gallery_settings($meta)['client_comments_enabled']);
}

function efpic_gallery_expired(array $meta): bool
{
    $expires = efpic_gallery_settings($meta)['expires_at'] ?? null;
    if ($expires === null || $expires === '') {
        return false;
    }
    $ts = strtotime((string) $expires);

    return $ts !== false && time() > $ts;
}

function efpic_record_gallery_view(array $config, string $slug, array &$meta): void
{
    $a = $meta['analytics'] ?? [];
    if (!is_array($a)) {
        $a = [];
    }
    $a['views'] = (int) ($a['views'] ?? 0) + 1;
    $meta['analytics'] = $a;
    efpic_save_gallery_meta($config, $slug, $meta);
}

<?php

declare(strict_types=1);

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
        'theme' => $type === 'delivery' ? 'pic-time' : 'classic',
        'client_theme' => null,
        'event_date' => null,
        'cover_image_token' => null,
        'hero_accent_color' => '#9a9578',
        'images' => [],
        'slideshow' => [
            'enabled' => false,
            'audio_file' => '',
            'interval_sec' => 5,
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
            'favorites_visible_to_guests' => false,
            'allow_access_requests' => false,
            'hide_client_hidden_from_public' => true,
            'expires_at' => null,
            'downloads' => [
                'main_client' => ['web', 'full', 'zip'],
                'guest' => ['web', 'zip'],
                'public' => ['web', 'zip'],
            ],
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

/** Natural compare for image basenames (EdgarsFoto_PRINT_1002 …). */
function efpic_compare_image_basenames(array $a, array $b): int
{
    $na = (string) ($a['basename'] ?? $a['failiem_full']['name'] ?? '');
    $nb = (string) ($b['basename'] ?? $b['failiem_full']['name'] ?? '');

    return strnatcasecmp($na, $nb);
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

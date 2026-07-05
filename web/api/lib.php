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
    if ($bytes >= 1073741824) {
        return round($bytes / 1073741824, 2) . ' GB';
    }
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

function efpic_gallery_email_template_defaults(): array
{
    return [
        'gallery_ready' => [
            'subject' => 'Jūsu galerija ir gatava — {name}',
            'body' => "Sveiki!\n\nJūsu foto galerija «{name}» ir gatava.\n\n{gallery_block}\n\n{portal_block}\n\nPieejama līdz {expires}.\n\nAr cieņu,\nEdgarsFoto",
        ],
        'expiry_reminder_30' => [
            'subject' => 'Atgādinājums — galerija «{name}» būs pieejama vēl 30 dienas',
            'body' => "Sveiki!\n\nAtgādinām, ka jūsu foto galerija «{name}» būs pieejama vēl apmēram 30 dienas (līdz {expires}).\n\n{gallery_block}\n\n{portal_block}\n\nLejupielādējiet bildes, kamēr galerija ir aktīva.\n\nAr cieņu,\nEdgarsFoto",
        ],
        'expiry_reminder_7' => [
            'subject' => 'Atgādinājums — galerija «{name}» drīz vairs nebūs pieejama',
            'body' => "Sveiki!\n\nJūsu foto galerija «{name}» būs pieejama vēl tikai apmēram 7 dienas (līdz {expires}).\n\n{gallery_block}\n\n{portal_block}\n\nLūdzu, lejupielādējiet vēlamās bildes pēc iespējas ātrāk.\n\nAr cieņu,\nEdgarsFoto",
        ],
    ];
}

function efpic_gallery_whatsapp_template_defaults(): array
{
    return [
        'gallery_ready' => [
            'body' => "Sveiki! Jūsu foto galerija «{name}» ir gatava.\n{gallery_block}\n{portal_block}\nPieejama līdz {expires}.",
        ],
        'expiry_reminder_30' => [
            'body' => "Sveiki! Atgādinām — galerija «{name}» būs pieejama vēl ~30 dienas (līdz {expires}).\n{url}",
        ],
        'expiry_reminder_7' => [
            'body' => "Sveiki! Galerija «{name}» drīz vairs nebūs pieejama (līdz {expires}). Lūdzu lejupielādē bildes.\n{url}",
        ],
    ];
}

/** Cilvēkam lasāms bildes nosaukums (faila vārds). */
function efpic_gallery_image_label(array $img): string
{
    $name = (string) ($img['basename'] ?? '');
    if ($name === '' && is_array($img['failiem_full'] ?? null)) {
        $name = (string) ($img['failiem_full']['name'] ?? '');
    }
    if ($name === '' && is_array($img['failiem_web'] ?? null)) {
        $name = (string) ($img['failiem_web']['name'] ?? '');
    }
    if ($name === '') {
        $tok = (string) ($img['token'] ?? '');

        return $tok !== '' ? $tok : 'bilde';
    }

    return basename($name);
}

/** @return array<string, array<string, mixed>> */
function efpic_gallery_images_by_token(array $meta): array
{
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

    return $byToken;
}

/** @return list<string> */
function efpic_gallery_scene_image_labels(array $meta, string $sceneId): array
{
    $labels = [];
    foreach ($meta['images'] ?? [] as $img) {
        if (!is_array($img)) {
            continue;
        }
        if ((string) ($img['scene_id'] ?? 'main') !== $sceneId) {
            continue;
        }
        $labels[] = efpic_gallery_image_label($img);
    }

    return $labels;
}

/**
 * @param array<string, mixed> $meta
 * @param array<string, mixed> $extra
 * @return list<string>
 */
function efpic_gallery_resolve_activity_image_labels(array $meta, array $extra): array
{
    if (!empty($extra['image_labels']) && is_array($extra['image_labels'])) {
        return array_values(array_unique(array_filter(array_map(
            static fn ($v): string => trim((string) $v),
            $extra['image_labels'],
        ))));
    }

    $labels = [];
    $single = trim((string) ($extra['image_label'] ?? ''));
    if ($single !== '') {
        $labels[] = $single;
    }

    $byToken = efpic_gallery_images_by_token($meta);
    $token = trim((string) ($extra['image_token'] ?? ''));
    if ($token !== '' && isset($byToken[$token])) {
        $labels[] = efpic_gallery_image_label($byToken[$token]);
    }

    $tokens = $extra['image_tokens'] ?? [];
    if (is_array($tokens)) {
        foreach ($tokens as $tok) {
            $tok = trim((string) $tok);
            if ($tok !== '' && isset($byToken[$tok])) {
                $labels[] = efpic_gallery_image_label($byToken[$tok]);
            }
        }
    }

    $sceneId = trim((string) ($extra['scene_id'] ?? ''));
    if ($sceneId !== '' && $labels === []) {
        $labels = efpic_gallery_scene_image_labels($meta, $sceneId);
    }

    return array_values(array_unique(array_filter($labels)));
}

/** @return array<string, mixed> */
function efpic_app_settings_defaults(): array
{
    return [
        'gallery_byline' => 'Gallery by EdgarsFoto',
        'gallery_page_bg' => '#ffffff',
        'gallery_feed_gap' => 16,
        'gallery_feed_gap_tablet' => 20,
        'gallery_feed_gap_desktop' => 24,
        'gallery_email' => [
            'enabled' => false,
            'from' => '',
            'from_name' => 'EdgarsFoto',
            'use_php_mail' => true,
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_secure' => 'tls',
            'smtp_user' => '',
            'smtp_pass' => '',
        ],
        'gallery_whatsapp' => [
            'default_country_code' => '371',
        ],
        'message_templates' => [],
        'site_logo' => '',
        'gallery_email_signature' => '',
        'gallery_email_signature_image' => '',
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
    $existing = efpic_load_app_settings($config);
    $merged = array_replace_recursive($existing, $settings);
    $merged['updated_at'] = gmdate('c');
    efpic_write_json_file(efpic_app_settings_path($config), $merged);
}

function efpic_site_assets_dir(array $config): string
{
    return dirname(efpic_storage_path($config)) . DIRECTORY_SEPARATOR . 'site_assets';
}

/** @param list<string> $allowedExt */
function efpic_store_site_asset(array $config, array $file, array $allowedExt, string $basename): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Augšupielādes kļūda');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new InvalidArgumentException('Nederīgs fails');
    }
    $orig = (string) ($file['name'] ?? '');
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        throw new InvalidArgumentException('Nederīgs faila formāts');
    }
    $dir = efpic_site_assets_dir($config);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $filename = $basename . '.' . $ext;
    $dest = $dir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('Neizdevās saglabāt failu');
    }

    return $filename;
}

function efpic_site_asset_path(array $config, string $filename): string
{
    $filename = basename($filename);
    if ($filename === '') {
        return '';
    }

    return efpic_site_assets_dir($config) . DIRECTORY_SEPARATOR . $filename;
}

function efpic_site_logo_url(array $config): string
{
    $settings = efpic_load_app_settings($config);
    $file = trim((string) ($settings['site_logo'] ?? ''));
    if ($file === '' || !is_file(efpic_site_asset_path($config, $file))) {
        return '';
    }

    return efpic_base_url($config) . '/site/logo';
}

function efpic_site_signature_image_url(array $config): string
{
    $settings = efpic_load_app_settings($config);
    $file = trim((string) ($settings['gallery_email_signature_image'] ?? ''));
    if ($file === '' || !is_file(efpic_site_asset_path($config, $file))) {
        return '';
    }

    return efpic_base_url($config) . '/site/signature';
}

function efpic_client_favicon_tags(array $config): string
{
    $logoUrl = efpic_site_logo_url($config);
    if ($logoUrl === '') {
        return '';
    }

    return '<link rel="icon" href="' . htmlspecialchars($logoUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
}

function efpic_handle_site_asset(array $config, string $kind): void
{
    $settings = efpic_load_app_settings($config);
    $file = match ($kind) {
        'logo' => trim((string) ($settings['site_logo'] ?? '')),
        'signature' => trim((string) ($settings['gallery_email_signature_image'] ?? '')),
        default => '',
    };
    $path = efpic_site_asset_path($config, $file);
    if ($file === '' || !is_file($path)) {
        http_response_code(404);
        exit;
    }
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=86400');
    readfile($path);
    exit;
}

function efpic_signature_host_embedded_images(array $config, string $html): string
{
    if ($html === '' || stripos($html, 'data:image') === false) {
        return $html;
    }

    return preg_replace_callback(
        '/<img\b([^>]*)\bsrc=(["\'])data:image\/([^;]+);base64,([^"\']+)\2([^>]*)>/i',
        static function (array $m) use ($config): string {
            $ext = strtolower($m[3]);
            if ($ext === 'jpeg') {
                $ext = 'jpg';
            }
            if (!in_array($ext, ['png', 'jpg', 'gif', 'webp'], true)) {
                return $m[0];
            }
            $bin = base64_decode($m[4], true);
            if ($bin === false || $bin === '') {
                return $m[0];
            }
            $dir = efpic_site_assets_dir($config);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $name = 'sig_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (file_put_contents($path, $bin) === false) {
                return $m[0];
            }
            $url = htmlspecialchars(efpic_site_asset_public_url($config, $name), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            return '<img' . $m[1] . ' src="' . $url . '"' . $m[5] . '>';
        },
        $html,
    ) ?? $html;
}

function efpic_store_image_bytes_as_site_asset(array $config, string $bytes, string $ext): string
{
    $ext = strtolower($ext);
    if ($ext === 'jpeg') {
        $ext = 'jpg';
    }
    if (!in_array($ext, ['png', 'jpg', 'gif', 'webp'], true) || $bytes === '') {
        return '';
    }
    $dir = efpic_site_assets_dir($config);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $name = 'sig_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $path = $dir . DIRECTORY_SEPARATOR . $name;
    if (file_put_contents($path, $bytes) === false) {
        return '';
    }

    return efpic_site_asset_public_url($config, $name);
}

function efpic_fetch_remote_image_bytes(string $url): ?array
{
    $url = trim($url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return null;
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 12,
            'follow_location' => 1,
            'max_redirects' => 4,
            'user_agent' => 'EFPIC-Gallery/1.0',
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $bytes = @file_get_contents($url, false, $ctx);
    if ($bytes === false || $bytes === '') {
        return null;
    }
    if (strlen($bytes) > 8 * 1024 * 1024) {
        return null;
    }

    $ext = 'jpg';
    if (str_starts_with($bytes, "\x89PNG")) {
        $ext = 'png';
    } elseif (str_starts_with($bytes, 'GIF8')) {
        $ext = 'gif';
    } elseif (str_starts_with($bytes, "RIFF") && substr($bytes, 8, 4) === 'WEBP') {
        $ext = 'webp';
    } elseif (!str_starts_with($bytes, "\xFF\xD8\xFF")) {
        return null;
    }

    return ['bytes' => $bytes, 'ext' => $ext];
}

function efpic_signature_host_remote_images(array $config, string $html): string
{
    if ($html === '' || stripos($html, '<img') === false) {
        return $html;
    }

    $base = rtrim(efpic_base_url($config), '/');

    return preg_replace_callback(
        '/<img\b([^>]*)\bsrc=(["\'])([^"\']+)\2([^>]*)>/i',
        static function (array $m) use ($config, $base): string {
            $src = trim(html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($src === '' || str_starts_with($src, 'data:') || str_starts_with($src, 'cid:')) {
                return $m[0];
            }
            if (str_starts_with($src, $base)) {
                return $m[0];
            }
            if (str_starts_with($src, '/site/')) {
                return $m[0];
            }
            if (efpic_email_resolve_local_image_path($config, $src) !== null) {
                return $m[0];
            }
            $fetched = efpic_fetch_remote_image_bytes($src);
            if ($fetched === null) {
                return $m[0];
            }
            $hosted = efpic_store_image_bytes_as_site_asset($config, $fetched['bytes'], $fetched['ext']);
            if ($hosted === '') {
                return $m[0];
            }
            $url = htmlspecialchars($hosted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            return '<img' . $m[1] . ' src="' . $url . '"' . $m[4] . '>';
        },
        $html,
    ) ?? $html;
}

function efpic_email_cache_remote_image(string $url): ?string
{
    static $cache = [];
    if (isset($cache[$url])) {
        return $cache[$url];
    }
    $fetched = efpic_fetch_remote_image_bytes($url);
    if ($fetched === null) {
        $cache[$url] = null;

        return null;
    }
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'efpic_mail_' . hash('sha256', $url) . '.' . $fetched['ext'];
    if (!is_file($tmp) && file_put_contents($tmp, $fetched['bytes']) === false) {
        $cache[$url] = null;

        return null;
    }
    $cache[$url] = $tmp;

    return $tmp;
}

function efpic_sanitize_email_signature_html(string $html): string
{
    $html = trim($html);
    if ($html === '' || $html === '<br>' || $html === '<p><br></p>' || $html === '<p></p>') {
        return '';
    }

    $html = preg_replace('/<\s*(script|iframe|object|embed|form|input|button|textarea|select)\b[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $html) ?? $html;
    $html = preg_replace('/<\s*(script|iframe|object|embed|form|input|button|textarea|select)\b[^>]*\/?>/i', '', $html) ?? $html;
    $html = preg_replace('/\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
    $html = preg_replace('/javascript:/i', '', $html) ?? $html;
    $html = preg_replace('/expression\s*\(/i', '', $html) ?? $html;
    $html = preg_replace('/@import\b/i', '', $html) ?? $html;

    return trim($html);
}

function efpic_html_to_plain_text(string $html): string
{
    $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
    $html = preg_replace('/<\/(p|div|li|h[1-6])>/i', "\n", $html) ?? $html;
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

    return trim($text);
}

function efpic_site_asset_public_url(array $config, string $filename): string
{
    $filename = basename($filename);
    if ($filename === '') {
        return '';
    }

    return efpic_base_url($config) . '/site/asset/' . rawurlencode($filename);
}

function efpic_handle_site_asset_file(array $config, string $filename): void
{
    $path = efpic_site_asset_path($config, $filename);
    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=86400');
    readfile($path);
    exit;
}

function efpic_store_signature_editor_image(array $config, array $file): string
{
    $name = 'sig_' . bin2hex(random_bytes(8));

    return efpic_store_site_asset($config, $file, ['png', 'jpg', 'jpeg', 'webp', 'gif'], $name);
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

function efpic_transliterate_lv(string $text): string
{
    static $map = [
        'ā' => 'a', 'č' => 'c', 'ē' => 'e', 'ģ' => 'g', 'ī' => 'i', 'ķ' => 'k', 'ļ' => 'l', 'ņ' => 'n', 'š' => 's', 'ū' => 'u', 'ž' => 'z',
        'Ā' => 'A', 'Č' => 'C', 'Ē' => 'E', 'Ģ' => 'G', 'Ī' => 'I', 'Ķ' => 'K', 'Ļ' => 'L', 'Ņ' => 'N', 'Š' => 'S', 'Ū' => 'U', 'Ž' => 'Z',
    ];

    return strtr($text, $map);
}

function efpic_zip_filename_segment(string $text, bool $lowercase = false, string $fallback = 'fails'): string
{
    $text = efpic_transliterate_lv(trim($text));
    $text = preg_replace('/[^a-zA-Z0-9]+/', '-', $text) ?? '';
    $text = trim($text, '-');
    if ($text === '') {
        $text = $fallback;
    }

    return $lowercase ? strtolower($text) : $text;
}

function efpic_zip_size_label(string $size): string
{
    return strtolower($size) === 'full' ? 'PRINT' : 'WEB';
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
    $meta = efpic_read_json_file(efpic_gallery_meta_path($config, $slug));
    if ($meta === null) {
        return null;
    }
    if (efpic_gallery_migrate_password_storage($meta)) {
        efpic_write_json_file(efpic_gallery_meta_path($config, $slug), $meta);
    }

    return $meta;
}

function efpic_save_gallery_meta(array $config, string $slug, array $meta): void
{
    efpic_gallery_migrate_password_storage($meta);
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
        'password' => '',
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
        'mood_font_family' => 'cormorant',
        'intro_all_caps' => false,
        'intro_text_color' => null,
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
            'enable_public_collection' => false,
            'client_portal_sections' => [
                'images' => true,
                'scenes' => true,
                'theme' => true,
                'share' => true,
                'media' => true,
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
            'phone' => '',
            'password' => '',
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

/** @return array{images: bool, scenes: bool, theme: bool, share: bool, media: bool} */
function efpic_client_portal_section_defaults(): array
{
    return [
        'images' => true,
        'scenes' => true,
        'theme' => true,
        'share' => true,
        'media' => true,
    ];
}

/** @return array{images: bool, scenes: bool, theme: bool, share: bool, media: bool} */
function efpic_client_portal_sections(array $meta): array
{
    $defaults = efpic_client_portal_section_defaults();
    $raw = efpic_gallery_settings($meta)['client_portal_sections'] ?? [];
    if (!is_array($raw)) {
        return $defaults;
    }
    $out = [];
    foreach ($defaults as $key => $defaultOn) {
        $out[$key] = array_key_exists($key, $raw) ? !empty($raw[$key]) : $defaultOn;
    }

    return $out;
}

function efpic_client_portal_section_enabled(array $meta, string $section): bool
{
    $sections = efpic_client_portal_sections($meta);

    return !empty($sections[$section]);
}

/** @return list<array{id: string, label: string, section: string}> */
function efpic_client_portal_nav_items(): array
{
    return [
        ['id' => 'admin-tab-images', 'label' => 'Bildes', 'section' => 'images'],
        ['id' => 'admin-tab-scenes', 'label' => 'Sadaļas', 'section' => 'scenes'],
        ['id' => 'admin-tab-theme', 'label' => 'Tēma', 'section' => 'theme'],
        ['id' => 'admin-tab-share', 'label' => 'Kopīgošana', 'section' => 'share'],
        ['id' => 'admin-tab-media', 'label' => 'Slideshow & video', 'section' => 'media'],
    ];
}

function efpic_portal_action_section(string $action): ?string
{
    return match ($action) {
        'toggle_hidden', 'toggle_favorite', 'add_comment' => 'images',
        'save_scenes' => 'scenes',
        'set_theme', 'save_gallery_colors', 'save_cover_theme' => 'theme',
        'save_slideshow', 'save_videos', 'upload_video', 'add_video_embed' => 'media',
        default => null,
    };
}

function efpic_gallery_default_expires_at(): string
{
    return date('Y-m-d', strtotime('+12 months'));
}

function efpic_gallery_expires_at_value(array $meta): ?string
{
    $expires = efpic_gallery_settings($meta)['expires_at'] ?? null;
    if ($expires === null || $expires === '') {
        return null;
    }

    return substr((string) $expires, 0, 10);
}

function efpic_gallery_expires_display(array $meta): string
{
    $date = efpic_gallery_expires_at_value($meta);
    if ($date === null) {
        return '';
    }
    $ts = strtotime($date);
    if ($ts === false) {
        return $date;
    }

    $months = [
        1 => 'janv.', 2 => 'febr.', 3 => 'marts', 4 => 'apr.',
        5 => 'maijs', 6 => 'jūn.', 7 => 'jūl.', 8 => 'aug.',
        9 => 'sept.', 10 => 'okt.', 11 => 'nov.', 12 => 'dec.',
    ];
    $m = (int) date('n', $ts);

    return (int) date('j', $ts) . '. ' . ($months[$m] ?? date('m', $ts)) . ' ' . date('Y', $ts);
}

function efpic_gallery_expired(array $meta): bool
{
    $expires = efpic_gallery_settings($meta)['expires_at'] ?? null;
    if ($expires === null || $expires === '') {
        return false;
    }
    $ts = strtotime((string) $expires);
    if ($ts === false) {
        return false;
    }
    $endOfDay = strtotime(date('Y-m-d', $ts) . ' 23:59:59');

    return time() > ($endOfDay !== false ? $endOfDay : $ts);
}

function efpic_gallery_apply_expires_from_post(array &$meta): bool
{
    if (!array_key_exists('expires_at', $_POST)) {
        return false;
    }
    if (!isset($meta['settings']) || !is_array($meta['settings'])) {
        $meta['settings'] = efpic_gallery_defaults('delivery')['settings'];
    }
    $raw = trim((string) $_POST['expires_at']);
    $old = $meta['settings']['expires_at'] ?? null;
    if ($raw === '') {
        $meta['settings']['expires_at'] = null;
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
        $meta['settings']['expires_at'] = $raw;
    }
    $new = $meta['settings']['expires_at'] ?? null;

    return (string) $old !== (string) ($new ?? '');
}

function efpic_record_gallery_view(array $config, string $slug, array &$meta): void
{
    $a = $meta['analytics'] ?? [];
    if (!is_array($a)) {
        $a = [];
    }
    $firstView = empty($a['client_first_view_at']);
    $a['views'] = (int) ($a['views'] ?? 0) + 1;
    if ($firstView) {
        $a['client_first_view_at'] = gmdate('c');
    }
    $meta['analytics'] = $a;
    efpic_save_gallery_meta($config, $slug, $meta);

    if (function_exists('efpic_gallery_log_activity') && $firstView) {
        efpic_gallery_log_activity(
            $config,
            $slug,
            $meta,
            'gallery_view',
            'Pirmā atvēršana',
            'guest',
            ['first_view' => true],
        );
    }

    if (function_exists('efpic_gallery_process_expiry_reminders')) {
        static $remindersChecked = false;
        if (!$remindersChecked) {
            $remindersChecked = true;
            efpic_gallery_process_expiry_reminders($config);
        }
    }
}

/** Admin on/off slēdzis (vienāds ar Tēma «Nosaukums ar lielajiem burtiem»). */
function efpic_render_admin_toggle(string $label, bool $checked = false, array $options = []): string
{
    $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $name = (string) ($options['name'] ?? '');
    $id = (string) ($options['id'] ?? '');
    $value = (string) ($options['value'] ?? '1');
    $fieldClass = 'admin-toggle-field';
    if (!empty($options['inline'])) {
        $fieldClass .= ' admin-toggle-field--inline';
    }
    $extraClass = trim((string) ($options['class'] ?? ''));
    if ($extraClass !== '') {
        $fieldClass .= ' ' . $extraClass;
    }
    $inputClass = trim((string) ($options['input_class'] ?? ''));
    $inputAttrs = (string) ($options['input_attrs'] ?? '');

    $html = '<label class="' . $esc($fieldClass) . '">';
    $html .= '<span class="admin-toggle-field__label">' . $esc($label) . '</span>';
    $html .= '<span class="admin-toggle">';
    $html .= '<input type="checkbox" value="' . $esc($value) . '"';
    if ($name !== '') {
        $html .= ' name="' . $esc($name) . '"';
    }
    if ($id !== '') {
        $html .= ' id="' . $esc($id) . '"';
    }
    if ($inputClass !== '') {
        $html .= ' class="' . $esc($inputClass) . '"';
    }
    if ($checked) {
        $html .= ' checked';
    }
    if ($inputAttrs !== '') {
        $html .= ' ' . $inputAttrs;
    }
    $html .= '>';
    $html .= '<span class="admin-toggle__track" aria-hidden="true"><span class="admin-toggle__thumb"></span></span>';
    $html .= '</span></label>';

    return $html;
}

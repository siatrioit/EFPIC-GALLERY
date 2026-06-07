<?php

declare(strict_types=1);

const EFPIC_LAYOUT_ASPECT_MIN = 0.62;
const EFPIC_LAYOUT_ASPECT_MAX = 2.45;
const EFPIC_LAYOUT_ASPECT_DEFAULT = 1.5;
const EFPIC_DIMS_BACKFILL_BATCH = 30;
const EFPIC_DIMS_SYNC_BATCH = 30;
const EFPIC_DIMS_VIEW_BATCH = 80;
const EFPIC_DIMS_BACKFILL_SAVE_EVERY = 5;

function efpic_image_has_dimensions(array $img): bool
{
    return ((int) ($img['width'] ?? 0)) > 0 && ((int) ($img['height'] ?? 0)) > 0;
}

/** @return array{width: int, height: int}|null */
function efpic_image_dimensions(array $img): ?array
{
    $w = (int) ($img['width'] ?? 0);
    $h = (int) ($img['height'] ?? 0);
    if ($w <= 0 || $h <= 0) {
        return null;
    }

    return ['width' => $w, 'height' => $h];
}

function efpic_clamp_layout_aspect(float $aspect): float
{
    return max(EFPIC_LAYOUT_ASPECT_MIN, min(EFPIC_LAYOUT_ASPECT_MAX, $aspect));
}

function efpic_image_layout_aspect(array $img): float
{
    $dims = efpic_image_dimensions($img);
    if ($dims === null) {
        return EFPIC_LAYOUT_ASPECT_DEFAULT;
    }

    return efpic_clamp_layout_aspect($dims['width'] / $dims['height']);
}

function efpic_format_layout_aspect(float $aspect): string
{
    return rtrim(rtrim(sprintf('%.6F', efpic_clamp_layout_aspect($aspect)), '0'), '.');
}

/** @return array{width: int, height: int}|null */
function efpic_probe_image_dimensions_from_path(string $path): ?array
{
    if ($path === '' || !is_file($path)) {
        return null;
    }
    $info = @getimagesize($path);
    if ($info === false) {
        return null;
    }

    return ['width' => (int) $info[0], 'height' => (int) $info[1]];
}

/** @return array{width: int, height: int}|null */
function efpic_probe_image_dimensions_from_binary(string $binary): ?array
{
    if ($binary === '') {
        return null;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'efpic_dim_');
    if ($tmp === false) {
        return null;
    }
    try {
        if (@file_put_contents($tmp, $binary) === false) {
            return null;
        }

        return efpic_probe_image_dimensions_from_path($tmp);
    } finally {
        @unlink($tmp);
    }
}

function efpic_fetch_binary_quick(array $config, string $url, int $timeoutSec = 12): ?string
{
    if ($url === '' || !function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }
    $headers = [
        'Accept: image/*,*/*;q=0.8',
        'User-Agent: EFPIC-Gallery/1.0',
    ];
    if (function_exists('efpic_failiem_cfg')) {
        $f = efpic_failiem_cfg($config);
        $apiKey = (string) ($f['api_key'] ?? '');
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }
    }
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => max(3, $timeoutSec),
        CURLOPT_CONNECTTIMEOUT => min(8, max(3, $timeoutSec)),
        CURLOPT_HTTPHEADER => $headers,
    ];
    if (function_exists('efpic_failiem_cfg')) {
        $f = efpic_failiem_cfg($config);
        $user = (string) ($f['user'] ?? '');
        $pass = (string) ($f['pass'] ?? '');
        if ($user !== '' && $pass !== '') {
            $opts[CURLOPT_USERPWD] = $user . ':' . $pass;
        }
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) {
        return null;
    }

    return is_string($body) && $body !== '' ? $body : null;
}

/** @return array{width: int, height: int}|null */
function efpic_probe_image_dimensions_remote(array $config, array $img): ?array
{
    if (!function_exists('efpic_failiem_thumb_url')) {
        require_once __DIR__ . '/failiem_client.php';
    }

    $web = $img['failiem_web'] ?? null;
    if (!is_array($web)) {
        return null;
    }
    $hash = trim((string) ($web['file_hash'] ?? ''));
    if ($hash === '') {
        return null;
    }

    $thumbUrl = efpic_failiem_thumb_url($config, $hash, 360);
    $binary = efpic_fetch_binary_quick($config, $thumbUrl, 8);
    if ($binary !== null) {
        $dims = efpic_probe_image_dimensions_from_binary($binary);
        if ($dims !== null) {
            return $dims;
        }
    }

    $binary = efpic_failiem_fetch_file($config, $hash);
    if ($binary === null) {
        return null;
    }

    return efpic_probe_image_dimensions_from_binary($binary);
}

/** @return array{width: int, height: int}|null */
function efpic_probe_image_dimensions(array $config, array $img, ?string $slug = null, bool $allowRemote = false): ?array
{
    $existing = efpic_image_dimensions($img);
    if ($existing !== null) {
        return $existing;
    }

    $file = trim((string) ($img['file'] ?? ''));
    if ($file !== '' && $slug !== null && $slug !== '') {
        $path = efpic_gallery_dir($config, $slug) . DIRECTORY_SEPARATOR . $file;
        $dims = efpic_probe_image_dimensions_from_path($path);
        if ($dims !== null) {
            return $dims;
        }
    }

    if (!$allowRemote) {
        return null;
    }

    return efpic_probe_image_dimensions_remote($config, $img);
}

function efpic_image_apply_dimensions(array &$img, array $config, ?string $slug, bool $allowRemote = false): bool
{
    if (efpic_image_has_dimensions($img)) {
        return false;
    }
    $dims = efpic_probe_image_dimensions($config, $img, $slug, $allowRemote);
    if ($dims === null) {
        return false;
    }
    $img['width'] = $dims['width'];
    $img['height'] = $dims['height'];

    return true;
}

function efpic_gallery_backfill_image_dimensions(array $config, string $slug, array &$meta, int $limit = 48, bool $allowRemote = false, int $saveEvery = EFPIC_DIMS_BACKFILL_SAVE_EVERY): int
{
    $images = $meta['images'] ?? [];
    if (!is_array($images) || $images === [] || $limit <= 0) {
        return 0;
    }

    $updated = 0;
    $dirty = false;
    foreach ($images as &$img) {
        if (!is_array($img) || $updated >= $limit) {
            continue;
        }
        if (!efpic_image_has_dimensions($img)) {
            if (efpic_image_apply_dimensions($img, $config, $slug, $allowRemote)) {
                $updated++;
                $dirty = true;
                if ($saveEvery > 0 && $updated % $saveEvery === 0) {
                    $meta['images'] = $images;
                    efpic_save_gallery_meta($config, $slug, $meta);
                    $dirty = false;
                }
            }
        }
    }
    unset($img);

    if ($dirty) {
        $meta['images'] = $images;
        efpic_save_gallery_meta($config, $slug, $meta);
    }

    return $updated;
}

/** @return array{total: int, with_dims: int, missing: int} */
function efpic_gallery_image_dimensions_stats(array $meta): array
{
    $total = 0;
    $withDims = 0;
    foreach ($meta['images'] ?? [] as $img) {
        if (!is_array($img)) {
            continue;
        }
        $total++;
        if (efpic_image_has_dimensions($img)) {
            $withDims++;
        }
    }

    return [
        'total' => $total,
        'with_dims' => $withDims,
        'missing' => max(0, $total - $withDims),
    ];
}

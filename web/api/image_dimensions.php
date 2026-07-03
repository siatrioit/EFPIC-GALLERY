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

function efpic_image_clear_dimensions(array &$img): void
{
    unset($img['width'], $img['height'], $img['dimensions_source_key']);
}

function efpic_image_failiem_web_hash(array $img): string
{
    $web = $img['failiem_web'] ?? null;

    return is_array($web) ? trim((string) ($web['file_hash'] ?? '')) : '';
}

function efpic_image_failiem_full_hash(array $img): string
{
    $full = $img['failiem_full'] ?? null;

    return is_array($full) ? trim((string) ($full['file_hash'] ?? '')) : '';
}

function efpic_image_failiem_web_size(array $img): int
{
    $web = $img['failiem_web'] ?? null;

    return is_array($web) ? (int) ($web['size_bytes'] ?? 0) : 0;
}

function efpic_image_failiem_full_size(array $img): int
{
    $full = $img['failiem_full'] ?? null;

    return is_array($full) ? (int) ($full['size_bytes'] ?? 0) : 0;
}

function efpic_image_dimensions_source_key(array $img): string
{
    $webHash = efpic_image_failiem_web_hash($img);
    if ($webHash === '') {
        return '';
    }

    return $webHash
        . '|' . efpic_image_failiem_full_hash($img)
        . '|' . efpic_image_failiem_web_size($img)
        . '|' . efpic_image_failiem_full_size($img);
}

function efpic_image_assign_dimensions(array &$img, int $width, int $height): void
{
    $img['width'] = $width;
    $img['height'] = $height;
    $sourceKey = efpic_image_dimensions_source_key($img);
    if ($sourceKey !== '') {
        $img['dimensions_source_key'] = $sourceKey;
    }
}

function efpic_image_dimensions_stale(array $img): bool
{
    if (!efpic_image_has_dimensions($img)) {
        return false;
    }
    $stored = trim((string) ($img['dimensions_source_key'] ?? ''));
    $current = efpic_image_dimensions_source_key($img);
    if ($current === '') {
        return false;
    }
    if ($stored === '') {
        // Vecā meta — izmēri bez saites uz Failiem fingerprint; pārrēķinām reizi.
        return true;
    }

    return $stored !== $current;
}

/** Vai drīkst saglabāt iepriekšējos izmērus pēc Failiem sync (hash + izmērs nemainījās). */
function efpic_image_should_preserve_dimensions(
    array $prev,
    string $newWebHash,
    string $newFullHash,
    int $newWebSize,
    int $newFullSize,
): bool {
    if (!efpic_image_has_dimensions($prev)) {
        return false;
    }
    $prevWebHash = efpic_image_failiem_web_hash($prev);
    $prevFullHash = efpic_image_failiem_full_hash($prev);
    $prevWebSize = efpic_image_failiem_web_size($prev);
    $prevFullSize = efpic_image_failiem_full_size($prev);

    if ($newWebHash === '' || $prevWebHash === '' || $newWebHash !== $prevWebHash) {
        return false;
    }
    if ($newFullHash !== '' && $prevFullHash !== '' && $newFullHash !== $prevFullHash) {
        return false;
    }
    if ($newWebSize > 0 && $prevWebSize > 0 && $newWebSize !== $prevWebSize) {
        return false;
    }
    if ($newFullSize > 0 && $prevFullSize > 0 && $newFullSize !== $prevFullSize) {
        return false;
    }

    $newSource = $newWebHash . '|' . $newFullHash . '|' . $newWebSize . '|' . $newFullSize;
    $storedSource = trim((string) ($prev['dimensions_source_key'] ?? ''));
    if ($storedSource === '') {
        return false;
    }
    if ($storedSource !== $newSource) {
        return false;
    }

    return true;
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
function efpic_probe_image_dimensions(array $config, array $img, ?string $slug = null, bool $allowRemote = false, bool $ignoreExisting = false): ?array
{
    if (!$ignoreExisting) {
        $existing = efpic_image_dimensions($img);
        if ($existing !== null) {
            return $existing;
        }
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

function efpic_image_apply_dimensions(array &$img, array $config, ?string $slug, bool $allowRemote = false, bool $force = false): bool
{
    if (!$force && efpic_image_has_dimensions($img) && !efpic_image_dimensions_stale($img)) {
        return false;
    }
    if ($force || efpic_image_dimensions_stale($img)) {
        efpic_image_clear_dimensions($img);
    }
    $dims = efpic_probe_image_dimensions($config, $img, $slug, $allowRemote, true);
    if ($dims === null) {
        return false;
    }
    efpic_image_assign_dimensions($img, $dims['width'], $dims['height']);

    return true;
}

function efpic_gallery_backfill_image_dimensions(
    array $config,
    string $slug,
    array &$meta,
    int $limit = 48,
    bool $allowRemote = false,
    int $saveEvery = EFPIC_DIMS_BACKFILL_SAVE_EVERY,
    array $forceTokens = [],
): int {
    $images = $meta['images'] ?? [];
    if (!is_array($images) || $images === [] || $limit <= 0) {
        return 0;
    }

    $priority = [];
    $normal = [];
    foreach ($images as $index => $img) {
        if (!is_array($img)) {
            continue;
        }
        $token = (string) ($img['token'] ?? '');
        if ($token !== '' && isset($forceTokens[$token])) {
            $priority[] = $index;
            continue;
        }
        if (efpic_image_dimensions_stale($img) || !efpic_image_has_dimensions($img)) {
            $normal[] = $index;
        }
    }

    $updated = 0;
    $dirty = false;
    foreach (array_merge($priority, $normal) as $index) {
        if ($updated >= $limit) {
            break;
        }
        if (!isset($images[$index]) || !is_array($images[$index])) {
            continue;
        }
        $img = &$images[$index];
        $token = (string) ($img['token'] ?? '');
        $force = $token !== '' && isset($forceTokens[$token]);
        if ($force || efpic_image_dimensions_stale($img)) {
            efpic_image_clear_dimensions($img);
        }
        if (!efpic_image_has_dimensions($img)) {
            if (efpic_image_apply_dimensions($img, $config, $slug, $allowRemote, $force)) {
                $updated++;
                $dirty = true;
                if ($saveEvery > 0 && $updated % $saveEvery === 0) {
                    $meta['images'] = $images;
                    efpic_save_gallery_meta($config, $slug, $meta);
                    $dirty = false;
                }
            }
        }
        unset($img);
    }

    if ($dirty) {
        $meta['images'] = $images;
        efpic_save_gallery_meta($config, $slug, $meta);
    }

    return $updated;
}

/** Pārrēķina izmērus visām bildēm, kur Failiem fails ir mainījies vai sync prasa piespiedu atjaunošanu. */
function efpic_gallery_reprobe_changed_image_dimensions(
    array $config,
    string $slug,
    array &$meta,
    array $forceTokens = [],
    bool $allowRemote = true,
): int {
    $images = $meta['images'] ?? [];
    if (!is_array($images) || $images === []) {
        return 0;
    }

    @set_time_limit(180);
    $updated = 0;
    $dirty = false;
    foreach ($images as &$img) {
        if (!is_array($img)) {
            continue;
        }
        $token = (string) ($img['token'] ?? '');
        $force = $token !== '' && isset($forceTokens[$token]);
        if (!$force && !efpic_image_dimensions_stale($img)) {
            continue;
        }
        if (efpic_image_apply_dimensions($img, $config, $slug, $allowRemote, true)) {
            $updated++;
            $dirty = true;
        }
    }
    unset($img);

    if ($dirty) {
        $meta['images'] = $images;
        efpic_save_gallery_meta($config, $slug, $meta);
    }

    return $updated;
}

/**
 * Ievāc izmērus visām bildēm, kurām tie vēl trūkst (vairākas kārtas).
 *
 * @return array{updated: int, stats: array{total: int, with_dims: int, missing: int}}
 */
function efpic_gallery_backfill_all_image_dimensions(
    array $config,
    string $slug,
    bool $allowRemote = true,
    int $batchSize = EFPIC_DIMS_SYNC_BATCH,
): array {
    @set_time_limit(0);
    @ignore_user_abort(true);

    $totalUpdated = 0;
    $maxRounds = 5000;
    for ($round = 0; $round < $maxRounds; ++$round) {
        $meta = efpic_load_gallery_meta($config, $slug);
        if ($meta === null) {
            break;
        }
        $stats = efpic_gallery_image_dimensions_stats($meta);
        if ($stats['missing'] <= 0 && ($stats['stale'] ?? 0) <= 0) {
            break;
        }
        $limit = max(1, min($stats['missing'], max($batchSize, EFPIC_DIMS_SYNC_BATCH)));
        $updated = efpic_gallery_backfill_image_dimensions($config, $slug, $meta, $limit, $allowRemote);
        $totalUpdated += $updated;
        if ($updated <= 0) {
            break;
        }
    }

    $meta = efpic_load_gallery_meta($config, $slug);
    $stats = efpic_gallery_image_dimensions_stats(is_array($meta) ? $meta : []);

    return ['updated' => $totalUpdated, 'stats' => $stats];
}

/** @return array{updated: int, stats: array{total: int, with_dims: int, missing: int}} */
function efpic_gallery_force_refresh_all_image_dimensions(
    array $config,
    string $slug,
    bool $allowRemote = true,
    int $batchSize = EFPIC_DIMS_SYNC_BATCH,
): array {
    $meta = efpic_load_gallery_meta($config, $slug);
    if ($meta === null) {
        return ['updated' => 0, 'stats' => ['total' => 0, 'with_dims' => 0, 'missing' => 0]];
    }
    foreach ($meta['images'] ?? [] as &$img) {
        if (is_array($img)) {
            efpic_image_clear_dimensions($img);
        }
    }
    unset($img);
    efpic_save_gallery_meta($config, $slug, $meta);

    return efpic_gallery_backfill_all_image_dimensions($config, $slug, $allowRemote, $batchSize);
}

/** @return array{total: int, with_dims: int, missing: int, stale: int} */
function efpic_gallery_image_dimensions_stats(array $meta): array
{
    $total = 0;
    $withDims = 0;
    $stale = 0;
    foreach ($meta['images'] ?? [] as $img) {
        if (!is_array($img)) {
            continue;
        }
        $total++;
        if (efpic_image_has_dimensions($img)) {
            $withDims++;
            if (efpic_image_dimensions_stale($img)) {
                $stale++;
            }
        }
    }

    return [
        'total' => $total,
        'with_dims' => $withDims,
        'missing' => max(0, $total - $withDims),
        'stale' => $stale,
    ];
}

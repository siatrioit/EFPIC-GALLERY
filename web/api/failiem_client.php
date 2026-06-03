<?php

declare(strict_types=1);

function efpic_failiem_cfg(array $config): array
{
    $f = $config['failiem'] ?? [];

    return is_array($f) ? $f : [];
}

function efpic_failiem_parse_folder_hash(string $input): string
{
    $input = trim($input);
    if ($input === '') {
        return '';
    }
    if (preg_match('#/u/([a-z0-9]+)#i', $input, $m)) {
        return strtolower($m[1]);
    }
    if (preg_match('#hash=([a-z0-9]+)#i', $input, $m)) {
        return strtolower($m[1]);
    }

    return preg_match('/^[a-z0-9]{6,32}$/i', $input) ? strtolower($input) : '';
}

function efpic_failiem_api_base(array $config): string
{
    return rtrim((string) (efpic_failiem_cfg($config)['api_base'] ?? 'https://api.files.fm'), '/');
}

function efpic_failiem_cdn_base(array $config): string
{
    return rtrim((string) (efpic_failiem_cfg($config)['cdn_base'] ?? 'https://failiem.lv'), '/');
}

/** @return list<string> */
function efpic_failiem_strip_suffixes(array $config, ?array $metaFailiem = null): array
{
    if ($metaFailiem !== null && !empty($metaFailiem['pair_suffix_strip']) && is_array($metaFailiem['pair_suffix_strip'])) {
        return array_map('strval', $metaFailiem['pair_suffix_strip']);
    }
    $f = efpic_failiem_cfg($config);
    $list = $f['pair_suffix_strip'] ?? ['_WEB', '_PRINT', '_web', '_print'];

    return is_array($list) ? array_map('strval', $list) : ['_WEB', '_PRINT'];
}

/** @return array<int, array{hash: string, name: string, size_bytes: int, mime_type: string}> */
function efpic_failiem_list_folder(array $config, string $folderHash): array
{
    $folderHash = efpic_failiem_parse_folder_hash($folderHash);
    if ($folderHash === '') {
        throw new InvalidArgumentException('Nederīga mapes saite vai hash');
    }

    $url = efpic_failiem_api_base($config)
        . '/api/get_file_list_for_upload.php?hash='
        . rawurlencode($folderHash)
        . '&include_folders=1';

    $data = efpic_failiem_http_get($config, $url);
    if (!is_array($data)) {
        throw new RuntimeException('Failiem atbilde nav masīvs');
    }

    $files = [];
    foreach ($data as $item) {
        if (!is_array($item) || ($item['type'] ?? '') !== 'File') {
            continue;
        }
        $mime = (string) ($item['mime_type'] ?? 'image/jpeg');
        if ($mime !== '' && !str_starts_with($mime, 'image/')) {
            continue;
        }
        $hash = (string) ($item['hash'] ?? '');
        if ($hash === '') {
            continue;
        }
        $files[] = [
            'hash' => $hash,
            'name' => (string) ($item['name'] ?? ''),
            'size_bytes' => (int) ($item['Size'] ?? $item['size'] ?? 0),
            'mime_type' => $mime,
        ];
    }

    return $files;
}

function efpic_failiem_normalize_basename(string $filename, array $stripSuffixes): string
{
    $base = pathinfo($filename, PATHINFO_FILENAME);
    foreach ($stripSuffixes as $suffix) {
        if ($suffix === '') {
            continue;
        }
        $len = strlen($suffix);
        if ($len > 0 && str_ends_with(strtoupper($base), strtoupper($suffix))) {
            $base = substr($base, 0, -$len);
        }
    }
    if (preg_match('/(\d+)\s*$/', $base, $m)) {
        return $m[1];
    }

    return strtolower($base);
}

/**
 * @param array<int, array<string, mixed>> $fullFiles
 * @param array<int, array<string, mixed>> $webFiles
 * @return array{paired: list<array>, orphans_full: list<array>, orphans_web: list<array>}
 */
function efpic_failiem_pair_files(array $fullFiles, array $webFiles, array $stripSuffixes): array
{
    $webByKey = [];
    foreach ($webFiles as $w) {
        $key = efpic_failiem_normalize_basename((string) $w['name'], $stripSuffixes);
        if ($key !== '') {
            $webByKey[$key] = $w;
        }
    }

    $paired = [];
    $orphansFull = [];
    $usedWeb = [];

    foreach ($fullFiles as $f) {
        $key = efpic_failiem_normalize_basename((string) $f['name'], $stripSuffixes);
        if ($key !== '' && isset($webByKey[$key])) {
            $paired[] = [
                'key' => $key,
                'basename' => (string) $f['name'],
                'full' => $f,
                'web' => $webByKey[$key],
            ];
            $usedWeb[$key] = true;
        } else {
            $orphansFull[] = $f;
        }
    }

    $orphansWeb = [];
    foreach ($webFiles as $w) {
        $key = efpic_failiem_normalize_basename((string) $w['name'], $stripSuffixes);
        if ($key === '' || !isset($usedWeb[$key])) {
            $orphansWeb[] = $w;
        }
    }

    return [
        'paired' => $paired,
        'orphans_full' => $orphansFull,
        'orphans_web' => $orphansWeb,
    ];
}

function efpic_failiem_thumb_url(array $config, string $fileHash, int $width = 720): string
{
    $api = efpic_failiem_api_base($config);
    if ($width > 0 && $width <= 420) {
        return $api . '/thumb.php?i=' . rawurlencode($fileHash);
    }

    return $api . '/thumb_show.php?i=' . rawurlencode($fileHash);
}

function efpic_failiem_download_url(array $config, string $fileHash): string
{
    $candidates = efpic_failiem_download_url_candidates($config, $fileHash);

    return $candidates[0];
}

/** @return list<string> */
function efpic_failiem_download_url_candidates(array $config, string $fileHash): array
{
    $hash = rawurlencode($fileHash);

    return [
        efpic_failiem_cdn_base($config) . '/down.php?i=' . $hash,
        efpic_failiem_api_base($config) . '/down.php?i=' . $hash,
    ];
}

/** Lejupielādē faila saturu no Failiem pēc file hash. */
function efpic_failiem_fetch_file(array $config, string $fileHash): ?string
{
    foreach (efpic_failiem_download_url_candidates($config, $fileHash) as $url) {
        $data = efpic_failiem_fetch_binary($config, $url);
        if ($data !== null && $data !== '') {
            return $data;
        }
    }

    return null;
}

/** Lejupielādē faila saturu no Failiem (curl, ja file_get_contents bloķēts). */
function efpic_failiem_fetch_binary(array $config, string $url): ?string
{
    if (function_exists('curl_init')) {
        $f = efpic_failiem_cfg($config);
        $headers = [
            'Accept: */*',
            'User-Agent: EFPIC-Gallery/1.0',
        ];
        $apiKey = (string) ($f['api_key'] ?? '');
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
        ];
        $user = (string) ($f['user'] ?? '');
        $pass = (string) ($f['pass'] ?? '');
        if ($user !== '' && $pass !== '') {
            $opts[CURLOPT_USERPWD] = $user . ':' . $pass;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            return null;
        }

        return $body;
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 600,
            'follow_location' => 1,
            'header' => "User-Agent: EFPIC-Gallery/1.0\r\nAccept: */*\r\n",
        ],
    ]);
    $data = @file_get_contents($url, false, $ctx);

    return $data === false ? null : $data;
}

function efpic_failiem_folder_zip_url(array $config, string $folderHash): string
{
    return efpic_failiem_api_base($config)
        . '/server_scripts/zip/zip_streamer/upload_zip_streamer.php?uhash='
        . rawurlencode($folderHash);
}

/** @return list<string> */
function efpic_failiem_file_hashes_from_images(array $images, string $size): array
{
    $sizeKey = $size === 'full' ? 'full' : 'web';
    $hashes = [];
    foreach ($images as $img) {
        if (!is_array($img)) {
            continue;
        }
        $hash = efpic_delivery_file_hash($img, $sizeKey);
        if ($hash !== '') {
            $hashes[] = $hash;
        }
    }

    return $hashes;
}

/**
 * Failiem atlasīto failu ZIP (download_selected_zip.php → upload_zip_streamer.php).
 *
 * @param list<string> $fileHashes
 */
function efpic_failiem_selected_zip_url(array $config, string $folderHash, array $fileHashes, bool $webSize = false): ?string
{
    $folderHash = efpic_failiem_parse_folder_hash($folderHash);
    if ($folderHash === '') {
        return null;
    }

    $fileHashes = array_values(array_filter(
        array_map(static fn ($h): string => trim((string) $h), $fileHashes),
        static fn (string $h): bool => $h !== ''
    ));
    if (count($fileHashes) < 2) {
        return null;
    }

    if (!function_exists('curl_init')) {
        return null;
    }

    $parts = ['upload_hash=' . rawurlencode($folderHash)];
    foreach ($fileHashes as $hash) {
        $parts[] = 'selected_items%5Bfiles%5D%5B%5D=' . rawurlencode($hash);
    }

    $f = efpic_failiem_cfg($config);
    $url = efpic_failiem_cdn_base($config)
        . '/server_scripts/zip/zip_streamer/download_selected_zip.php';
    $headers = [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
        'User-Agent: EFPIC-Gallery/1.0',
    ];
    $apiKey = (string) ($f['api_key'] ?? '');
    if ($apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => implode('&', $parts),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ];
    $user = (string) ($f['user'] ?? '');
    $pass = (string) ($f['pass'] ?? '');
    if ($user !== '' && $pass !== '') {
        $opts[CURLOPT_USERPWD] = $user . ':' . $pass;
    }

    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code < 200 || $code >= 300) {
        return null;
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded) || ($decoded['status'] ?? '') !== 'ok') {
        return null;
    }

    $data = $decoded['data'] ?? null;
    if (!is_array($data)) {
        return null;
    }

    $key = trim((string) ($data['selected_download_key'] ?? ''));
    $host = trim((string) ($data['file_host'] ?? ''));
    if ($key === '' || $host === '') {
        return null;
    }

    $host = preg_replace('#^https?://#i', '', rtrim($host, '/'));
    $zipUrl = 'https://' . $host
        . '/server_scripts/zip/zip_streamer/upload_zip_streamer.php?uhash='
        . rawurlencode($folderHash)
        . '&selected_download_key='
        . rawurlencode($key);
    if ($webSize) {
        $zipUrl .= '&img_as_websize';
    }

    return $zipUrl;
}

function efpic_failiem_delivery_folder_hash(array $meta, string $size): string
{
    $failiem = $meta['failiem'] ?? [];
    if (!is_array($failiem)) {
        return '';
    }
    if ($size === 'full') {
        return efpic_failiem_parse_folder_hash((string) ($failiem['folder_full_hash'] ?? ''))
            ?: efpic_failiem_parse_folder_hash((string) ($failiem['folder_full_url'] ?? ''));
    }

    return efpic_failiem_parse_folder_hash((string) ($failiem['folder_web_hash'] ?? ''))
        ?: efpic_failiem_parse_folder_hash((string) ($failiem['folder_web_url'] ?? ''));
}

/** Vai drīkst lietot Failiem mapes ZIP (visa mape, bez filtra un slēptajām). */
function efpic_can_failiem_folder_zip(array $meta, array $ctx): bool
{
    if (($meta['type'] ?? '') !== 'delivery') {
        return false;
    }
    if (is_array($ctx['share_image_tokens'] ?? null)) {
        return false;
    }
    foreach ($meta['images'] ?? [] as $img) {
        if (is_array($img) && !empty($img['client_hidden'])) {
            return false;
        }
    }

    return true;
}

/** Straumē Failiem sagatavoto ZIP uz izvadi (klientam fetch ar gaidīšanu). */
function efpic_failiem_stream_folder_zip(array $config, string $folderHash, string $downloadName): bool
{
    $folderHash = efpic_failiem_parse_folder_hash($folderHash);
    if ($folderHash === '') {
        return false;
    }

    $url = efpic_failiem_folder_zip_url($config, $folderHash);
    if (function_exists('curl_init')) {
        $f = efpic_failiem_cfg($config);
        $headers = ['Accept: application/zip'];
        $apiKey = (string) ($f['api_key'] ?? '');
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }

        $opts = [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk): int {
                echo $chunk;
                if (function_exists('ob_get_level') && ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();

                return strlen($chunk);
            },
        ];
        $user = (string) ($f['user'] ?? '');
        $pass = (string) ($f['pass'] ?? '');
        if ($user !== '' && $pass !== '') {
            $opts[CURLOPT_USERPWD] = $user . ':' . $pass;
        }

        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $code >= 200 && $code < 300;
    }

    $ctx = stream_context_create(['http' => ['timeout' => 600, 'follow_location' => 1]]);
    $fp = @fopen($url, 'rb', false, $ctx);
    if ($fp === false) {
        return false;
    }
    while (!feof($fp)) {
        $chunk = fread($fp, 65536);
        if ($chunk === false) {
            break;
        }
        echo $chunk;
        flush();
    }
    fclose($fp);

    return true;
}

function efpic_failiem_redirect_media(array $config, string $fileHash, bool $thumb, int $width = 720): void
{
    $url = $thumb
        ? efpic_failiem_thumb_url($config, $fileHash, $width)
        : efpic_failiem_download_url($config, $fileHash);
    header('Location: ' . $url, true, 302);
    exit;
}

/** @return mixed */
function efpic_failiem_http_get(array $config, string $url)
{
    $f = efpic_failiem_cfg($config);
    $headers = ['Accept: application/json'];

    $apiKey = (string) ($f['api_key'] ?? '');
    $user = (string) ($f['user'] ?? '');
    $pass = (string) ($f['pass'] ?? '');

    if ($apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl init failed');
    }

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($user !== '' && $pass !== '') {
        $opts[CURLOPT_USERPWD] = $user . ':' . $pass;
    }

    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code < 200 || $code >= 300) {
        throw new RuntimeException('Failiem HTTP ' . $code . ($err !== '' ? ': ' . $err : ''));
    }

    $decoded = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Failiem JSON: ' . json_last_error_msg());
    }

    return $decoded;
}

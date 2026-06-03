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
    return efpic_failiem_cdn_base($config) . '/down.php?i=' . rawurlencode($fileHash);
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

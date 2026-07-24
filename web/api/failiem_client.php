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

function efpic_failiem_is_video_file(string $mime, string $name): bool
{
    if ($mime !== '' && str_starts_with($mime, 'video/')) {
        return true;
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    return in_array($ext, ['mp4', 'mov', 'webm', 'm4v', 'avi', 'mkv'], true);
}

/** @return array<int, array{hash: string, name: string, size_bytes: int, mime_type: string}> */
function efpic_failiem_list_video_folder(array $config, string $folderHash): array
{
    $folderHash = efpic_failiem_parse_folder_hash($folderHash);
    if ($folderHash === '') {
        throw new InvalidArgumentException('Nederīga video mapes saite vai hash');
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
        $name = (string) ($item['name'] ?? '');
        $mime = (string) ($item['mime_type'] ?? '');
        if (!efpic_failiem_is_video_file($mime, $name)) {
            continue;
        }
        $hash = (string) ($item['hash'] ?? '');
        if ($hash === '') {
            continue;
        }
        $files[] = [
            'hash' => $hash,
            'name' => $name,
            'size_bytes' => (int) ($item['Size'] ?? $item['size'] ?? 0),
            'mime_type' => $mime !== '' ? $mime : 'video/mp4',
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

/** @return list<string> */
function efpic_failiem_file_hashes_from_videos(array $videos): array
{
    $hashes = [];
    foreach ($videos as $video) {
        if (!is_array($video) || ($video['kind'] ?? '') !== 'failiem') {
            continue;
        }
        $hash = (string) ($video['failiem']['file_hash'] ?? '');
        if ($hash !== '') {
            $hashes[] = $hash;
        }
    }

    return $hashes;
}

function efpic_failiem_video_folder_hash(array $meta): string
{
    $failiem = $meta['failiem'] ?? [];
    if (!is_array($failiem)) {
        return '';
    }

    return efpic_failiem_parse_folder_hash((string) ($failiem['folder_video_hash'] ?? ''))
        ?: efpic_failiem_parse_folder_hash((string) ($failiem['folder_video_url'] ?? ''));
}

function efpic_failiem_cookie_phpsessid(string $cookieFile): string
{
    if ($cookieFile === '' || !is_readable($cookieFile)) {
        return '';
    }

    $lines = file($cookieFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return '';
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (str_starts_with($line, '#HttpOnly_')) {
            $line = substr($line, strlen('#HttpOnly_'));
        } elseif ($line[0] === '#') {
            continue;
        }
        $parts = preg_split('/\s+/', $line);
        if (count($parts) >= 7 && $parts[5] === 'PHPSESSID') {
            return (string) $parts[6];
        }
    }

    return '';
}

/**
 * @return array{ok: true, url: string}|array{ok: false, error: string}
 */
function efpic_failiem_selected_zip_prepare(
    array $config,
    string $folderHash,
    array $fileHashes
): array {
    $url = efpic_failiem_selected_zip_url($config, $folderHash, $fileHashes);
    if ($url !== null) {
        return ['ok' => true, 'url' => $url];
    }

    return [
        'ok' => false,
        'error' => 'Failiem neatgrieza ZIP saiti. Pārbaudi savienojumu ar failiem.lv.',
    ];
}

/**
 * Reģistrē atlasīto failu ZIP Failiem un atgriež straumes URL + sīkdatņu failu.
 *
 * @param list<string> $fileHashes
 * @return array{stream_url: string, cookie_file: string}|null
 */
function efpic_failiem_register_selected_zip(
    array $config,
    string $folderHash,
    array $fileHashes
): ?array {
    @set_time_limit(0);
    @ignore_user_abort(true);

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

    // Failiem UI: POST upload_hash + selected_items[files][] ar atlasīto failu hash.
    // Atbilde: selected_download_key + file_host → straume upload_zip_streamer.php?selected_download_key=…
    $postBody = 'upload_hash=' . rawurlencode($folderHash);
    foreach ($fileHashes as $hash) {
        $postBody .= '&selected_items%5Bfiles%5D%5B%5D=' . rawurlencode($hash);
    }

    $f = efpic_failiem_cfg($config);
    $bases = array_values(array_unique(array_filter([
        efpic_failiem_cdn_base($config),
        'https://failiem.lv',
        efpic_failiem_api_base($config),
    ])));
    $endpoint = '/server_scripts/zip/zip_streamer/download_selected_zip.php';
    $headers = [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
        'User-Agent: EFPIC-Gallery/1.0',
    ];
    $apiKey = (string) ($f['api_key'] ?? '');
    if ($apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    $cookieFile = sys_get_temp_dir() . '/efpic_failiem_' . bin2hex(random_bytes(6)) . '.cookies';
    @file_put_contents($cookieFile, '');
    $user = (string) ($f['user'] ?? '');
    $pass = (string) ($f['pass'] ?? '');

    foreach ($bases as $base) {
        $url = rtrim($base, '/') . $endpoint;
        $ch = curl_init($url);
        if ($ch === false) {
            continue;
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postBody,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 45,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_COOKIEFILE => $cookieFile,
        ];
        if ($user !== '' && $pass !== '') {
            $opts[CURLOPT_USERPWD] = $user . ':' . $pass;
        }

        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $phpSessId = efpic_failiem_cookie_phpsessid($cookieFile);

        if ($body === false || $code < 200 || $code >= 300) {
            continue;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || ($decoded['status'] ?? '') !== 'ok') {
            continue;
        }

        $data = $decoded['data'] ?? null;
        if (!is_array($data)) {
            continue;
        }

        $key = trim((string) ($data['selected_download_key'] ?? ''));
        $host = trim((string) ($data['file_host'] ?? ''));
        if ($key === '' || $host === '' || $phpSessId === '') {
            continue;
        }

        $host = preg_replace('#^https?://#i', '', rtrim($host, '/'));
        $zipUrl = 'https://' . $host
            . '/server_scripts/zip/zip_streamer/upload_zip_streamer.php?uhash='
            . rawurlencode($folderHash)
            . '&selected_download_key='
            . rawurlencode($key);
        $zipUrl .= '&PHPSESSID=' . rawurlencode($phpSessId);

        return ['stream_url' => $zipUrl, 'cookie_file' => $cookieFile];
    }

    @unlink($cookieFile);

    return null;
}

function efpic_failiem_prepared_zip_session_key(string $galleryToken, string $scope, string $size, string $viewerScope = 'public'): string
{
    return 'efpic_failiem_zip_' . hash('sha256', $galleryToken . '|' . $scope . '|' . $size . '|' . $viewerScope);
}

/** @param array{stream_url: string, cookie_file: string} $reg */
function efpic_failiem_stash_prepared_zip(
    string $galleryToken,
    string $scope,
    string $size,
    array $reg,
    string $filename,
    string $viewerScope = 'public'
): void {
    efpic_client_session_start();
    $cookiePath = sys_get_temp_dir() . '/efpic_zip_ck_' . bin2hex(random_bytes(8)) . '.txt';
    $cookieSrc = $reg['cookie_file'];
    if (is_readable($cookieSrc)) {
        @copy($cookieSrc, $cookiePath);
        @unlink($cookieSrc);
    }
    $_SESSION[efpic_failiem_prepared_zip_session_key($galleryToken, $scope, $size, $viewerScope)] = [
        'stream_url' => $reg['stream_url'],
        'cookie_path' => $cookiePath,
        'filename' => $filename,
        'expires' => time() + 900,
    ];
}

function efpic_failiem_take_prepared_zip(string $galleryToken, string $scope, string $size, string $viewerScope = 'public'): ?array
{
    efpic_client_session_start();
    $key = efpic_failiem_prepared_zip_session_key($galleryToken, $scope, $size, $viewerScope);
    $data = $_SESSION[$key] ?? null;
    unset($_SESSION[$key]);
    if (!is_array($data) || (int) ($data['expires'] ?? 0) < time()) {
        if (is_array($data) && is_string($data['cookie_path'] ?? null)) {
            @unlink($data['cookie_path']);
        }

        return null;
    }

    return $data;
}

/**
 * Straumē no sagatavotas Failiem ZIP sesijas; HTTP galvenes tikai pēc pirmā baita.
 */
function efpic_failiem_stream_prepared_zip(array $config, array $prepared): bool
{
    $streamUrl = (string) ($prepared['stream_url'] ?? '');
    $cookieFile = (string) ($prepared['cookie_path'] ?? '');
    $filename = (string) ($prepared['filename'] ?? 'galerija.zip');
    if ($streamUrl === '' || $cookieFile === '' || !is_readable($cookieFile)) {
        return false;
    }

    $safeName = str_replace(['"', "\r", "\n"], '', $filename);
    $f = efpic_failiem_cfg($config);
    $curlHeaders = ['Accept: application/zip'];
    $apiKey = (string) ($f['api_key'] ?? '');
    if ($apiKey !== '') {
        $curlHeaders[] = 'Authorization: Bearer ' . $apiKey;
    }

    $headersSent = false;
    $totalBytes = 0;
    $ok = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($streamUrl);
        if ($ch !== false) {
            $opts = [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_HTTPHEADER => $curlHeaders,
                CURLOPT_COOKIEJAR => $cookieFile,
                CURLOPT_COOKIEFILE => $cookieFile,
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (
                    &$headersSent,
                    &$totalBytes,
                    $safeName
                ): int {
                    $len = strlen($chunk);
                    if ($len > 0) {
                        if (!$headersSent) {
                            while (ob_get_level() > 0) {
                                ob_end_clean();
                            }
                            header('Content-Type: application/zip');
                            header('Content-Disposition: attachment; filename="' . $safeName . '"');
                            header('Cache-Control: no-cache, no-store');
                            header('X-Accel-Buffering: no');
                            $headersSent = true;
                        }
                        echo $chunk;
                        if (function_exists('ob_get_level') && ob_get_level() > 0) {
                            @ob_flush();
                        }
                        flush();
                        $totalBytes += $len;
                    }

                    return $len;
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
            $ok = $code >= 200 && $code < 300 && $totalBytes > 0;
        }
    }

    @unlink($cookieFile);

    return $ok;
}

/**
 * Straumē atlasīto failu ZIP no Failiem (ar reģistrācijas sesiju).
 *
 * @param list<string> $fileHashes
 */
function efpic_failiem_stream_selected_zip(
    array $config,
    string $folderHash,
    array $fileHashes,
    string $filename = 'galerija.zip'
): bool {
    $reg = efpic_failiem_register_selected_zip($config, $folderHash, $fileHashes);
    if ($reg === null) {
        return false;
    }

    $prepared = [
        'stream_url' => $reg['stream_url'],
        'cookie_path' => $reg['cookie_file'],
        'filename' => $filename,
    ];

    return efpic_failiem_stream_prepared_zip($config, $prepared);
}

/**
 * Lejupielādē Failiem atlasīto ZIP uz disku (e-pasta / visitor_zips plūsmai).
 * Tas pats mehānisms, ko izmanto pārlūka lejupielāde — bez katras bildes atsevišķas ielādes PHP atmiņā.
 *
 * @param list<string> $fileHashes
 */
function efpic_failiem_download_selected_zip_to_file(
    array $config,
    string $folderHash,
    array $fileHashes,
    string $destPath,
): bool {
    $build = [];
    $guard = 0;
    while ($guard < 120) {
        $guard++;
        $step = efpic_failiem_download_selected_zip_step(
            $config,
            $folderHash,
            $fileHashes,
            $destPath,
            $build,
            0, // bez laika limita — viens gabals (sync ceļš)
        );
        if (empty($step['ok'])) {
            return false;
        }
        if (!empty($step['done'])) {
            return true;
        }
    }

    return false;
}

/**
 * Failiem selected-ZIP uz disku pa soļiem (resume caur .part).
 * Laika budžets ļauj rindai / poll nepārsniegt HTTP timeout; nākamais solis turpina.
 *
 * @param list<string> $fileHashes
 * @param array<string, mixed> $build zip_build (mutēts: failiem_* lauki)
 * @return array{ok: bool, done?: bool, bytes?: int, error?: string}
 */
function efpic_failiem_download_selected_zip_step(
    array $config,
    string $folderHash,
    array $fileHashes,
    string $destPath,
    array &$build,
    int $timeBudgetSec = 90,
): array {
    @set_time_limit(0);
    @ignore_user_abort(true);

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'curl_missing'];
    }

    $dir = dirname($destPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $partPath = $destPath . '.part';
    $cookiePath = $destPath . '.cookies';

    $streamUrl = trim((string) ($build['failiem_stream_url'] ?? ''));
    if ($streamUrl === '' || !is_readable($cookiePath)) {
        $reg = efpic_failiem_register_selected_zip($config, $folderHash, $fileHashes);
        if ($reg === null) {
            return ['ok' => false, 'error' => 'failiem_register_failed'];
        }
        $srcCookie = (string) ($reg['cookie_file'] ?? '');
        if ($srcCookie === '' || !is_readable($srcCookie)) {
            @unlink($srcCookie);

            return ['ok' => false, 'error' => 'failiem_cookie_missing'];
        }
        @copy($srcCookie, $cookiePath);
        @unlink($srcCookie);
        $streamUrl = (string) ($reg['stream_url'] ?? '');
        if ($streamUrl === '') {
            @unlink($cookiePath);

            return ['ok' => false, 'error' => 'failiem_stream_missing'];
        }
        $build['failiem_stream_url'] = $streamUrl;
        $build['failiem_bytes'] = is_file($partPath) ? (int) filesize($partPath) : 0;
        $build['mode'] = 'failiem';
    }

    $resumeFrom = is_file($partPath) ? (int) filesize($partPath) : 0;
    if ($resumeFrom <= 0 && is_file($partPath)) {
        @unlink($partPath);
        $resumeFrom = 0;
    }

    $fp = @fopen($partPath, $resumeFrom > 0 ? 'ab' : 'wb');
    if ($fp === false) {
        return ['ok' => false, 'error' => 'part_open_failed'];
    }

    $f = efpic_failiem_cfg($config);
    $curlHeaders = ['Accept: application/zip', 'User-Agent: EFPIC-Gallery/1.0'];
    $apiKey = (string) ($f['api_key'] ?? '');
    if ($apiKey !== '') {
        $curlHeaders[] = 'Authorization: Bearer ' . $apiKey;
    }

    $writtenThisStep = 0;
    $ch = curl_init($streamUrl);
    if ($ch === false) {
        fclose($fp);

        return ['ok' => false, 'error' => 'curl_init_failed'];
    }

    $opts = [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 45,
        CURLOPT_HTTPHEADER => $curlHeaders,
        CURLOPT_COOKIEJAR => $cookiePath,
        CURLOPT_COOKIEFILE => $cookiePath,
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($fp, &$writtenThisStep): int {
            $len = strlen($chunk);
            if ($len === 0) {
                return 0;
            }
            $n = fwrite($fp, $chunk);
            if ($n === false || $n < $len) {
                return 0;
            }
            $writtenThisStep += $n;

            return $len;
        },
    ];
    if ($timeBudgetSec > 0) {
        $opts[CURLOPT_TIMEOUT] = $timeBudgetSec;
    } else {
        $opts[CURLOPT_TIMEOUT] = 0;
    }
    if ($resumeFrom > 0) {
        $opts[CURLOPT_RESUME_FROM_LARGE] = (int) $resumeFrom;
    }
    $user = (string) ($f['user'] ?? '');
    $pass = (string) ($f['pass'] ?? '');
    if ($user !== '' && $pass !== '') {
        $opts[CURLOPT_USERPWD] = $user . ':' . $pass;
    }
    curl_setopt_array($ch, $opts);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = (int) curl_errno($ch);
    curl_close($ch);
    fclose($fp);

    $totalBytes = is_file($partPath) ? (int) filesize($partPath) : 0;
    $build['failiem_bytes'] = $totalBytes;

    // Timeout / partial — turpinām nākamajā solī, ja kaut kas pierakstījās.
    $timedOut = $errno === 28 || (defined('CURLE_OPERATION_TIMEDOUT') && $errno === CURLE_OPERATION_TIMEDOUT);

    if ($resumeFrom > 0 && $code === 200 && $writtenThisStep > 0) {
        // Serveris ignorēja Range un atsāka no 0 → part bojāts.
        @unlink($partPath);
        unset($build['failiem_stream_url'], $build['failiem_bytes']);
        @unlink($cookiePath);

        return ['ok' => false, 'error' => 'failiem_range_unsupported'];
    }

    $okPartial = ($code === 206 || $code === 200) && $totalBytes > 100;
    if (!$okPartial && !($timedOut && $totalBytes > $resumeFrom)) {
        if (!$timedOut) {
            @unlink($partPath);
            @unlink($cookiePath);
            unset($build['failiem_stream_url'], $build['failiem_bytes']);

            return ['ok' => false, 'error' => 'failiem_download_failed'];
        }

        // Timeout bez progress — mēģinās no jauna nākamajā solī ar jaunu reģistrāciju.
        unset($build['failiem_stream_url']);

        return ['ok' => true, 'done' => false, 'bytes' => $totalBytes];
    }

    // Vai lejupielāde pabeigta? Ja timeout un vēl rakstījās — nav done.
    if ($timedOut || ($timeBudgetSec > 0 && $errno !== 0 && $totalBytes > 100)) {
        return ['ok' => true, 'done' => false, 'bytes' => $totalBytes];
    }

    if ($code !== 200 && $code !== 206) {
        return ['ok' => true, 'done' => false, 'bytes' => $totalBytes];
    }

    // Pabeigts: .part → dest
    if (is_file($destPath)) {
        @unlink($destPath);
    }
    if (!@rename($partPath, $destPath)) {
        if (@copy($partPath, $destPath)) {
            @unlink($partPath);
        } else {
            @unlink($partPath);
            @unlink($cookiePath);

            return ['ok' => false, 'error' => 'failiem_rename_failed'];
        }
    }
    @unlink($cookiePath);
    unset($build['failiem_stream_url'], $build['failiem_bytes']);

    if (!is_file($destPath) || (int) filesize($destPath) < 100) {
        @unlink($destPath);

        return ['ok' => false, 'error' => 'failiem_zip_invalid'];
    }

    return ['ok' => true, 'done' => true, 'bytes' => (int) filesize($destPath)];
}

/**
 * Lejupielādē Failiem failu tieši uz disku (bez ielādes PHP atmiņā).
 */
function efpic_failiem_fetch_file_to_path(array $config, string $fileHash, string $destPath): bool
{
    @set_time_limit(0);
    foreach (efpic_failiem_download_url_candidates($config, $fileHash) as $url) {
        if (efpic_failiem_fetch_binary_to_path($config, $url, $destPath)) {
            return true;
        }
    }

    return false;
}

function efpic_failiem_fetch_binary_to_path(array $config, string $url, string $destPath): bool
{
    $dir = dirname($destPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $part = $destPath . '.part';
    if (!function_exists('curl_init')) {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 600,
                'follow_location' => 1,
                'header' => "User-Agent: EFPIC-Gallery/1.0\r\nAccept: */*\r\n",
            ],
        ]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false || $data === '') {
            return false;
        }

        return @file_put_contents($destPath, $data) !== false;
    }

    $fp = @fopen($part, 'wb');
    if ($fp === false) {
        return false;
    }
    $f = efpic_failiem_cfg($config);
    $headers = ['Accept: */*', 'User-Agent: EFPIC-Gallery/1.0'];
    $apiKey = (string) ($f['api_key'] ?? '');
    if ($apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }
    $ch = curl_init($url);
    if ($ch === false) {
        fclose($fp);
        @unlink($part);

        return false;
    }
    $opts = [
        CURLOPT_FILE => $fp,
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
    $ok = curl_exec($ch) !== false;
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    if (!$ok || $code < 200 || $code >= 300 || !is_file($part) || filesize($part) < 1) {
        @unlink($part);

        return false;
    }
    if (is_file($destPath)) {
        @unlink($destPath);
    }
    if (!@rename($part, $destPath)) {
        $copied = @copy($part, $destPath);
        @unlink($part);
        if (!$copied) {
            return false;
        }
    }

    return true;
}

/**
 * Failiem atlasīto failu ZIP saite (pārlūkam). Ieteicams efpic_failiem_stream_selected_zip.
 *
 * @param list<string> $fileHashes
 */
function efpic_failiem_selected_zip_url(array $config, string $folderHash, array $fileHashes): ?string
{
    $reg = efpic_failiem_register_selected_zip($config, $folderHash, $fileHashes);
    if ($reg === null) {
        return null;
    }
    @unlink($reg['cookie_file']);

    return $reg['stream_url'];
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
    if (function_exists('efpic_gallery_has_client_content_filtering')
        && efpic_gallery_has_client_content_filtering($meta)) {
        return false;
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

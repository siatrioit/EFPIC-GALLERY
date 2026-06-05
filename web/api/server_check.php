<?php

declare(strict_types=1);

/**
 * Vienreizēja servera pārbaude slideshow MP4 renderēšanai (admin).
 *
 * @return list<array{label: string, value: string, status: string, note: string}>
 */
function efpic_server_check_rows(array $config): array
{
    $rows = [];

    $rows[] = efpic_server_check_row('PHP', PHP_VERSION, 'ok', '');

    $disabledRaw = (string) ini_get('disable_functions');
    $disabled = array_filter(array_map('trim', explode(',', $disabledRaw)));
    $shellFns = ['exec', 'shell_exec', 'proc_open', 'popen'];
    $blocked = array_values(array_intersect($shellFns, $disabled));
    if ($blocked === []) {
        $rows[] = efpic_server_check_row(
            'Shell funkcijas',
            'exec, shell_exec, proc_open, popen — pieejamas',
            'ok',
            ''
        );
    } else {
        $rows[] = efpic_server_check_row(
            'Shell funkcijas',
            'Aizliegtas: ' . implode(', ', $blocked),
            'fail',
            'FFmpeg parasti nevar palaist bez exec/shell_exec.'
        );
    }

    $maxExec = (int) ini_get('max_execution_time');
    $rows[] = efpic_server_check_row(
        'max_execution_time',
        $maxExec === 0 ? '0 (bez limita)' : (string) $maxExec . ' sek.',
        $maxExec === 0 || $maxExec >= 120 ? 'ok' : 'warn',
        $maxExec > 0 && $maxExec < 120 ? 'Slideshow render vajag vismaz ~120 sek. vai 0 (bez limita).' : ''
    );

    $memory = (string) ini_get('memory_limit');
    $rows[] = efpic_server_check_row(
        'memory_limit',
        $memory !== '' ? $memory : '(nav)',
        'ok',
        'Ieteicams vismaz 256M gariem video.'
    );

    $storagePath = (string) ($config['storage_path'] ?? '');
    if ($storagePath === '') {
        $rows[] = efpic_server_check_row('storage_path', '(nav config)', 'fail', '');
    } else {
        $exists = is_dir($storagePath);
        $writable = $exists && is_writable($storagePath);
        $rows[] = efpic_server_check_row(
            'storage_path',
            $storagePath,
            $exists && $writable ? 'ok' : 'fail',
            !$exists ? 'Mape neeksistē.' : (!$writable ? 'Nav rakstāms.' : '')
        );
        if ($exists) {
            $free = @disk_free_space($storagePath);
            if ($free !== false) {
                $rows[] = efpic_server_check_row(
                    'Brīva vieta (storage)',
                    efpic_server_check_format_bytes((int) $free),
                    $free >= 512 * 1024 * 1024 ? 'ok' : 'warn',
                    $free < 512 * 1024 * 1024 ? 'Maz brīvas vietas lieliem MP4.' : ''
                );
            }
        }
    }

    $tmp = sys_get_temp_dir();
    $rows[] = efpic_server_check_row(
        'Temp mape',
        $tmp,
        is_dir($tmp) && is_writable($tmp) ? 'ok' : 'warn',
        !is_writable($tmp) ? 'FFmpeg var nevarēt rakstīt pagaidu failus.' : ''
    );

    $ffmpeg = efpic_server_check_ffmpeg();
    $rows[] = efpic_server_check_row(
        'FFmpeg',
        $ffmpeg['summary'],
        $ffmpeg['status'],
        $ffmpeg['note']
    );

    if ($ffmpeg['status'] === 'ok' && $ffmpeg['path'] !== '') {
        $codecs = efpic_server_check_ffmpeg_codecs($ffmpeg['path']);
        $rows[] = efpic_server_check_row(
            'FFmpeg libx264 + AAC',
            $codecs['summary'],
            $codecs['status'],
            $codecs['note']
        );
    }

    $rows[] = efpic_server_check_row(
        'Cron / fona rinda',
        'Manuāli pārbaudi cPanel → Cron Jobs',
        'warn',
        'Automātiskai slideshow rindai vajadzīgs cron vai līdzīgs mehānisms.'
    );

    return $rows;
}

/** @return array{ready: bool, summary: string} */
function efpic_server_check_slideshow_ready(array $config): array
{
    $rows = efpic_server_check_rows($config);
    $fail = 0;
    foreach ($rows as $row) {
        if ($row['status'] === 'fail') {
            $fail++;
        }
    }

    if ($fail === 0) {
        return [
            'ready' => true,
            'summary' => 'Serveris izskatās piemērots slideshow MP4 ģenerēšanai (pārbaudi vēl cron).',
        ];
    }

    return [
        'ready' => false,
        'summary' => 'Slideshow renderēšanai trūkst ' . $fail . ' kritiska(i) nosacījuma(i) — skat. sarkanos ierakstus.',
    ];
}

function efpic_server_check_render_page(array $config): void
{
    $ready = efpic_server_check_slideshow_ready($config);
    $rows = efpic_server_check_rows($config);

    $body = '<div class="admin-panel">';
    $body .= '<p class="admin-warn"><strong>Vienreizēja pārbaude.</strong> Pēc rezultāta izdzēs '
        . '<code>public/admin/server_check.php</code> un <code>api/server_check.php</code>.</p>';

    $body .= '<p class="' . ($ready['ready'] ? 'admin-ok' : 'err') . '">'
        . efpic_admin_esc($ready['summary']) . '</p>';

    $body .= '<div class="admin-table-wrap"><table class="admin-table admin-server-check-table">';
    $body .= '<thead><tr><th>Pārbaude</th><th>Rezultāts</th><th>Statuss</th><th>Piezīme</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        if ($row['status'] === 'ok') {
            $badge = 'OK';
        } elseif ($row['status'] === 'warn') {
            $badge = 'Brīdinājums';
        } else {
            $badge = 'Kļūda';
        }
        $body .= '<tr class="admin-server-check-row admin-server-check-row--' . efpic_admin_esc($row['status']) . '">';
        $body .= '<td>' . efpic_admin_esc($row['label']) . '</td>';
        $body .= '<td><code class="admin-server-check-value">' . efpic_admin_esc($row['value']) . '</code></td>';
        $body .= '<td>' . efpic_admin_esc($badge) . '</td>';
        $body .= '<td class="muted">' . efpic_admin_esc($row['note']) . '</td>';
        $body .= '</tr>';
    }
    $body .= '</tbody></table></div>';
    $body .= '<p class="muted">Laiks: ' . efpic_admin_esc(gmdate('Y-m-d H:i:s') . ' UTC') . '</p>';
    $body .= '</div>';

    efpic_admin_layout(
        'Servera pārbaude',
        $body,
        '',
        'Servera pārbaude',
        'Slideshow MP4 render — FFmpeg, PHP un resursi.',
        $config
    );
}

/**
 * @return array{label: string, value: string, status: string, note: string}
 */
function efpic_server_check_row(string $label, string $value, string $status, string $note): array
{
    return [
        'label' => $label,
        'value' => $value,
        'status' => $status,
        'note' => $note,
    ];
}

/** @return array{status: string, summary: string, note: string, path: string} */
function efpic_server_check_ffmpeg(): array
{
    if (!efpic_server_check_shell_allowed()) {
        return [
            'status' => 'fail',
            'summary' => 'Nevar pārbaudīt (shell aizliegts)',
            'note' => '',
            'path' => '',
        ];
    }

    $candidates = ['ffmpeg', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg'];
    foreach ($candidates as $bin) {
        $cmd = escapeshellarg($bin) . ' -version 2>&1';
        $out = efpic_server_check_shell($cmd);
        if ($out !== null && stripos($out, 'ffmpeg version') !== false) {
            $first = trim(strtok($out, "\n") ?: $out);

            return [
                'status' => 'ok',
                'summary' => $first,
                'note' => 'Ceļš: ' . $bin,
                'path' => $bin,
            ];
        }
    }

    return [
        'status' => 'fail',
        'summary' => 'Nav atrasts (ffmpeg -version)',
        'note' => 'Jautā hosterim vai instalē FFmpeg.',
        'path' => '',
    ];
}

/** @return array{status: string, summary: string, note: string} */
function efpic_server_check_ffmpeg_codecs(string $ffmpegPath): array
{
    $cmd = escapeshellarg($ffmpegPath) . ' -hide_banner -codecs 2>&1';
    $out = efpic_server_check_shell($cmd);
    if ($out === null) {
        return [
            'status' => 'warn',
            'summary' => 'Nevar nolasīt codec sarakstu',
            'note' => '',
        ];
    }

    $hasX264 = stripos($out, 'libx264') !== false;
    $hasAac = stripos($out, 'aac') !== false;
    if ($hasX264 && $hasAac) {
        return [
            'status' => 'ok',
            'summary' => 'libx264 un AAC — atrasts',
            'note' => '',
        ];
    }

    $missing = [];
    if (!$hasX264) {
        $missing[] = 'libx264';
    }
    if (!$hasAac) {
        $missing[] = 'AAC';
    }

    return [
        'status' => 'warn',
        'summary' => 'Trūkst: ' . implode(', ', $missing),
        'note' => '1080p MP4 var neizdoties.',
    ];
}

function efpic_server_check_shell_allowed(): bool
{
    $disabledRaw = (string) ini_get('disable_functions');
    $disabled = array_filter(array_map('trim', explode(',', $disabledRaw)));

    return !in_array('exec', $disabled, true) && !in_array('shell_exec', $disabled, true);
}

function efpic_server_check_shell(string $command): ?string
{
    if (!efpic_server_check_shell_allowed()) {
        return null;
    }

    $disabledRaw = (string) ini_get('disable_functions');
    $disabled = array_filter(array_map('trim', explode(',', $disabledRaw)));

    if (!in_array('exec', $disabled, true) && function_exists('exec')) {
        $lines = [];
        $code = 0;
        @exec($command, $lines, $code);

        return implode("\n", $lines);
    }

    if (!in_array('shell_exec', $disabled, true) && function_exists('shell_exec')) {
        $out = @shell_exec($command);

        return is_string($out) ? $out : null;
    }

    return null;
}

function efpic_server_check_format_bytes(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return round($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }

    return round($bytes / 1024, 0) . ' KB';
}

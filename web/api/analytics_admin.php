<?php

declare(strict_types=1);

require_once __DIR__ . '/gallery_analytics.php';

function efpic_admin_analytics_stat_card(string $value, string $label, string $modifier = ''): string
{
    $cls = 'admin-analytics-stat' . ($modifier !== '' ? ' admin-analytics-stat--' . $modifier : '');

    return '<div class="' . $cls . '"><p class="admin-analytics-stat__value">' . efpic_admin_esc($value)
        . '</p><p class="admin-analytics-stat__label">' . efpic_admin_esc($label) . '</p></div>';
}

/** @param array<string, array<string, int>> $daily */
function efpic_admin_analytics_render_chart(array $daily, string $metric = 'unique'): string
{
    if ($daily === []) {
        return '<p class="muted">Nav datu izvēlētajam periodam.</p>';
    }
    $max = 1;
    foreach ($daily as $day) {
        $max = max($max, (int) ($day[$metric] ?? 0));
    }

    $bars = '';
    foreach ($daily as $dayKey => $day) {
        $val = (int) ($day[$metric] ?? 0);
        $pct = $max > 0 ? max(4, (int) round($val / $max * 100)) : 0;
        $label = substr((string) $dayKey, 5);
        $bars .= '<div class="admin-analytics-chart__col">';
        $bars .= '<span class="admin-analytics-chart__value">' . $val . '</span>';
        $bars .= '<div class="admin-analytics-chart__bar" style="height:' . $pct . '%" title="' . efpic_admin_esc($dayKey . ': ' . $val) . '"></div>';
        $bars .= '<span class="admin-analytics-chart__label">' . efpic_admin_esc($label) . '</span>';
        $bars .= '</div>';
    }

    return '<div class="admin-analytics-chart" role="img" aria-label="Dienas statistika">'
        . '<div class="admin-analytics-chart__bars">' . $bars . '</div></div>';
}

/** @param array<string, mixed> $summary */
function efpic_admin_render_analytics_dashboard(array $config, array $summary, string $fromDate, string $toDate, bool $compact = false): string
{
    $deviceTotal = (int) $summary['mobile'] + (int) $summary['desktop'] + (int) $summary['unknown'];
    $mobilePct = efpic_analytics_device_percent((int) $summary['mobile'], $deviceTotal);
    $desktopPct = efpic_analytics_device_percent((int) $summary['desktop'], $deviceTotal);
    $unknownPct = efpic_analytics_device_percent((int) $summary['unknown'], $deviceTotal);

    $html = '';
    if (!$compact) {
        $html .= '<div class="admin-analytics-head">';
        $html .= '<p class="admin-analytics-head__meta"><strong>' . (int) $summary['online'] . '</strong> tiešsaistē · ';
        $html .= '<strong>' . (int) $summary['unique_visitors'] . '</strong> apmeklētāji periodā</p>';
        $html .= '</div>';

        $html .= '<form method="get" class="admin-analytics-filters">';
        if (!$compact) {
            $html .= '<input type="hidden" name="page" value="global">';
        }
        $html .= '<label>No<input type="date" name="from" value="' . efpic_admin_esc($fromDate) . '"></label>';
        $html .= '<label>Līdz<input type="date" name="to" value="' . efpic_admin_esc($toDate) . '"></label>';
        $html .= '<button type="submit" class="btn primary">Rādīt</button>';
        $html .= '</form>';

        $html .= '<p class="muted admin-analytics-period">Periods: ' . efpic_admin_esc($fromDate) . ' — ' . efpic_admin_esc($toDate);
        $html .= ' · Pēdējais apmeklējums: ' . efpic_admin_esc(efpic_analytics_format_last_visit($summary['last_visit_at'] ?? null));
        $html .= ' · Tiešsaistē = aktīvi pēdējās ' . (int) (EFPIC_ANALYTICS_ONLINE_SECONDS / 60) . ' min</p>';
    }

    $html .= '<div class="admin-analytics-stats">';
    $html .= efpic_admin_analytics_stat_card((string) (int) $summary['online'], 'Tiešsaistē', 'online');
    $html .= efpic_admin_analytics_stat_card((string) (int) $summary['unique_visitors'], 'Unikālie apmeklētāji');
    $html .= efpic_admin_analytics_stat_card((string) (int) $summary['mobile'] . ' - ' . $mobilePct . '%', 'Viedtālrunis');
    $html .= efpic_admin_analytics_stat_card((string) (int) $summary['desktop'] . ' - ' . $desktopPct . '%', 'Dators');
    $html .= efpic_admin_analytics_stat_card((string) (int) $summary['unknown'] . ' - ' . $unknownPct . '%', 'Nezināms');
    $html .= efpic_admin_analytics_stat_card((string) (int) $summary['today'], 'Šodien');
    $html .= efpic_admin_analytics_stat_card((string) (int) $summary['album_views'], 'Albuma skatījumi');
    $html .= efpic_admin_analytics_stat_card((string) (int) $summary['session_views'], 'Sesiju skatījumi');
    $html .= efpic_admin_analytics_stat_card((string) (int) $summary['downloads'], 'Lejupielādes');
    $html .= '</div>';

    $chartTitle = $compact
        ? 'Izvēlētais periods — unikālie apmeklētāji'
        : 'Pēdējās dienas — unikālie apmeklētāji';
    $html .= '<section class="admin-analytics-section">';
    $html .= '<h2 class="admin-analytics-section__title">' . efpic_admin_esc($chartTitle) . '</h2>';
    $html .= efpic_admin_analytics_render_chart(is_array($summary['daily'] ?? null) ? $summary['daily'] : [], 'unique');
    $html .= '</section>';

    if (!$compact) {
        $html .= '<section class="admin-analytics-section">';
        $html .= '<h2 class="admin-analytics-section__title">Tiešsaistē pa galerijām</h2>';
        $onlineRows = '';
        foreach (is_array($summary['galleries'] ?? null) ? $summary['galleries'] : [] as $gallery) {
            if ((int) ($gallery['online'] ?? 0) <= 0) {
                continue;
            }
            $onlineRows .= '<tr><td>' . efpic_admin_esc((string) ($gallery['name'] ?? '')) . '</td>';
            $onlineRows .= '<td>' . (int) ($gallery['online'] ?? 0) . '</td></tr>';
        }
        if ($onlineRows === '') {
            $html .= '<p class="muted">Pašlaik neviens nav galerijās.</p>';
        } else {
            $html .= '<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Galerija</th><th>Tiešsaistē</th></tr></thead>';
            $html .= '<tbody>' . $onlineRows . '</tbody></table></div>';
        }
        $html .= '</section>';

        $html .= '<section class="admin-analytics-section">';
        $html .= '<h2 class="admin-analytics-section__title">Galeriju pārskats <span class="muted">('
            . count(is_array($summary['galleries'] ?? null) ? $summary['galleries'] : []) . ' galerijas)</span></h2>';
        $rows = '';
        foreach (is_array($summary['galleries'] ?? null) ? $summary['galleries'] : [] as $gallery) {
            $name = (string) ($gallery['name'] ?? '—');
            $slug = (string) ($gallery['slug'] ?? '');
            $deleted = !empty($gallery['deleted_at']);
            $nameCell = $deleted || $slug === ''
                ? '<span class="muted">' . efpic_admin_esc($name) . '</span>'
                : '<a href="delivery_edit.php?slug=' . rawurlencode($slug) . '">' . efpic_admin_esc($name) . '</a>';
            if ($deleted) {
                $nameCell .= ' <span class="admin-analytics-deleted">(dzēsta)</span>';
            }
            $rows .= '<tr>';
            $rows .= '<td>' . $nameCell . '</td>';
            $rows .= '<td>' . (int) ($gallery['online'] ?? 0) . '</td>';
            $rows .= '<td>' . (int) ($gallery['today'] ?? 0) . '</td>';
            $rows .= '<td>' . (int) ($gallery['unique_visitors'] ?? 0) . '</td>';
            $rows .= '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="muted">Vēl nav apmeklējumu datu.</td></tr>';
        }
        $html .= '<div class="admin-table-wrap"><table class="admin-table"><thead><tr>';
        $html .= '<th>Galerija</th><th>Tiešsaistē</th><th>Šodien</th><th>Kopā apmeklētāji</th>';
        $html .= '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
        $html .= '</section>';
    }

    return $html;
}

function efpic_admin_analytics_page(array $config): void
{
    [$fromDate, $toDate] = efpic_analytics_parse_date_range(
        isset($_GET['from']) ? (string) $_GET['from'] : null,
        isset($_GET['to']) ? (string) $_GET['to'] : null,
    );
    $records = efpic_analytics_list_gallery_records($config);
    $summary = efpic_analytics_aggregate($records, $fromDate, $toDate);

    $body = '<section class="admin-analytics-page">';
    $body .= efpic_admin_render_analytics_dashboard($config, $summary, $fromDate, $toDate, false);
    $body .= '</section>';

    efpic_admin_layout(
        'Analītika',
        $body,
        'analytics',
        'Apmeklējumu statistika',
        'Statistika saglabājas arī pēc galerijas dzēšanas.',
        $config,
    );
}

function efpic_admin_render_gallery_analytics_panel(array $config, array $meta, string $slug): string
{
    $galleryToken = (string) ($meta['gallery_token'] ?? '');
    if ($galleryToken === '') {
        return '';
    }

    $record = efpic_analytics_load_gallery($config, $galleryToken);
    if ($record === null) {
        efpic_analytics_ensure_gallery_record($config, $slug, $meta);
        $record = efpic_analytics_load_gallery($config, $galleryToken);
    }
    if ($record === null) {
        return '<p class="muted">Analītikas dati vēl nav pieejami.</p>';
    }

    [$fromDate, $toDate] = efpic_analytics_parse_date_range(null, null, 14);
    $summary = efpic_analytics_aggregate([$record], $fromDate, $toDate);
    if ($summary['galleries'] !== []) {
        $summary['galleries'][0]['name'] = (string) ($meta['name'] ?? $slug);
    }

    $html = '<fieldset class="admin-fieldset-full admin-analytics-gallery" id="admin-fs-analytics">';
    $html .= '<legend>Analītika</legend>';
    $html .= '<p class="muted">Pēdējās 14 dienas. Kopējā statistika pieejama sadaļā <a href="analytics.php">Analītika</a>.';
    if (!empty($record['deleted_at']) || !efpic_gallery_is_active($meta)) {
        $html .= ' <strong>Statistika saglabāta arī pēc dzēšanas.</strong>';
    }
    $html .= '</p>';
    $html .= efpic_admin_render_analytics_dashboard($config, $summary, $fromDate, $toDate, true);
    $html .= '</fieldset>';

    return $html;
}

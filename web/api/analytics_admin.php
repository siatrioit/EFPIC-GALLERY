<?php

declare(strict_types=1);

require_once __DIR__ . '/gallery_analytics.php';

/** @param array<string, mixed> $gallery */
function efpic_admin_analytics_gallery_name_cell(array $gallery): string
{
    $name = (string) ($gallery['name'] ?? '—');
    $slug = (string) ($gallery['slug'] ?? '');
    $deleted = !empty($gallery['deleted_at']);
    $date = substr((string) ($gallery['event_date'] ?? ''), 0, 10);
    $datePrefix = $date !== ''
        ? '<span class="admin-analytics-gallery-date">' . efpic_admin_esc($date) . '</span> '
        : '';
    $nameCell = $deleted || $slug === ''
        ? $datePrefix . '<span class="muted">' . efpic_admin_esc($name) . '</span>'
        : $datePrefix . '<a href="delivery_edit.php?slug=' . rawurlencode($slug) . '">' . efpic_admin_esc($name) . '</a>';
    if ($deleted) {
        $nameCell .= ' <span class="admin-analytics-deleted">(dzēsta)</span>';
    }

    return $nameCell;
}

function efpic_admin_handle_analytics_actions(array $config): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['analytics_action'])) {
        return;
    }
    $action = (string) $_POST['analytics_action'];
    if ($action !== 'delete_stats') {
        return;
    }
    efpic_admin_require_delete_confirm();
    $galleryToken = trim((string) ($_POST['gallery_token'] ?? ''));
    if ($galleryToken === '') {
        throw new InvalidArgumentException('Nav norādīta galerija.');
    }
    if (!efpic_analytics_delete_gallery($config, $galleryToken)) {
        throw new InvalidArgumentException('Analītikas dati nav atrasti.');
    }
    header('Location: analytics.php?stats_deleted=1');
    exit;
}

/** @param array<string, mixed> $record */
function efpic_admin_render_analytics_delete_section(string $galleryToken, array $record): string
{
    $galleryName = (string) ($record['name'] ?? 'galerija');
    $date = substr((string) ($record['event_date'] ?? ''), 0, 10);
    $label = ($date !== '' ? $date . ' ' : '') . $galleryName;

    $html = '<section class="admin-analytics-section admin-analytics-danger">';
    $html .= '<h2 class="admin-analytics-section__title">Dzēst statistiku</h2>';
    $html .= '<p class="muted">Neatgriezeniski izdzēš <strong>' . efpic_admin_esc($label)
        . '</strong> apmeklējumu statistiku. Pati galerija netiek dzēsta.</p>';
    $html .= '<form method="post" class="admin-analytics-delete-form" id="admin-analytics-delete-form">';
    $html .= '<input type="hidden" name="analytics_action" value="delete_stats">';
    $html .= '<input type="hidden" name="gallery_token" value="' . efpic_admin_esc($galleryToken) . '">';
    $html .= '<input type="hidden" name="confirm_delete" id="analytics_confirm_delete" value="">';
    $html .= '<button type="submit" class="btn admin-analytics-delete-btn" data-confirm-delete="1">Dzēst statistiku</button>';
    $html .= '</form></section>';

    return $html;
}

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
function efpic_admin_render_analytics_filters(
    string $fromDate,
    string $toDate,
    string $galleryToken = '',
    bool $embeddedInForm = false,
): string {
    if ($embeddedInForm) {
        $html = '<div class="admin-analytics-filters admin-analytics-filters--embedded"';
        if ($galleryToken !== '') {
            $html .= ' data-analytics-gallery="' . efpic_admin_esc($galleryToken) . '"';
        }
        $html .= '>';
    } else {
        $html = '<form method="get" class="admin-analytics-filters"';
        $html .= $galleryToken !== '' ? ' action="analytics.php"' : '';
        $html .= '>';
        if ($galleryToken !== '') {
            $html .= '<input type="hidden" name="gallery" value="' . efpic_admin_esc($galleryToken) . '">';
        }
    }
    $html .= '<label>No<input type="date" name="from" value="' . efpic_admin_esc($fromDate) . '"></label>';
    $html .= '<label>Līdz<input type="date" name="to" value="' . efpic_admin_esc($toDate) . '"></label>';
    if ($embeddedInForm) {
        $html .= '<button type="button" class="btn primary" data-analytics-apply="1">Rādīt</button>';
        $html .= '</div>';
    } else {
        $html .= '<button type="submit" class="btn primary">Rādīt</button>';
        $html .= '</form>';
    }

    return $html;
}

/** @param array<string, mixed> $summary */
function efpic_admin_render_analytics_dashboard(
    array $config,
    array $summary,
    string $fromDate,
    string $toDate,
    bool $globalSections = true,
    string $galleryToken = '',
    bool $embeddedInForm = false,
): string {
    $deviceTotal = (int) $summary['phone'] + (int) $summary['tablet'] + (int) $summary['desktop'];
    $phonePct = efpic_analytics_device_percent((int) $summary['phone'], $deviceTotal);
    $tabletPct = efpic_analytics_device_percent((int) $summary['tablet'], $deviceTotal);
    $desktopPct = efpic_analytics_device_percent((int) $summary['desktop'], $deviceTotal);

    $html = '<div class="admin-analytics-head">';
    $html .= '<p class="admin-analytics-head__meta"><strong>' . (int) $summary['online'] . '</strong> tiešsaistē · ';
    $html .= '<strong>' . (int) $summary['unique_visitors'] . '</strong> apmeklētāji periodā</p>';
    $html .= '</div>';

    $html .= efpic_admin_render_analytics_filters($fromDate, $toDate, $galleryToken, $embeddedInForm);

    $html .= '<p class="muted admin-analytics-period">Periods: ' . efpic_admin_esc($fromDate) . ' — ' . efpic_admin_esc($toDate);
    $html .= ' · Pēdējais apmeklējums: ' . efpic_admin_esc(efpic_analytics_format_last_visit($summary['last_visit_at'] ?? null));
    $html .= ' · Tiešsaistē = aktīvi pēdējās ' . (int) (EFPIC_ANALYTICS_ONLINE_SECONDS / 60) . ' min';
    if ($galleryToken === '') {
        $html .= ' · Admin IP netiek skaitīts 24 h pēc pēdējās pieslēgšanās';
    }
    $html .= '</p>';

    $html .= '<div class="admin-analytics-stats">';
    $html .= efpic_admin_analytics_stat_card((string) (int) $summary['online'], 'Tiešsaistē', 'online');
    $html .= efpic_admin_analytics_stat_card((string) (int) $summary['unique_visitors'], 'Unikālie apmeklētāji');
    $html .= efpic_admin_analytics_stat_card((string) (int) $summary['phone'] . ' - ' . $phonePct . '%', 'Viedtālrunis');
    $html .= efpic_admin_analytics_stat_card((string) (int) $summary['tablet'] . ' - ' . $tabletPct . '%', 'Planšete');
    $html .= efpic_admin_analytics_stat_card((string) (int) $summary['desktop'] . ' - ' . $desktopPct . '%', 'Dators');
    $html .= efpic_admin_analytics_stat_card((string) (int) $summary['today'], 'Šodien');
    $html .= efpic_admin_analytics_stat_card((string) (int) $summary['album_views'], 'Albuma skatījumi');
    $html .= efpic_admin_analytics_stat_card((string) (int) $summary['session_views'], 'Sesiju skatījumi');
    $html .= efpic_admin_analytics_stat_card((string) (int) $summary['downloads'], 'Lejupielādes');
    $html .= '</div>';

    $chartTitle = $globalSections
        ? 'Pēdējās dienas — unikālie apmeklētāji'
        : 'Izvēlētais periods — unikālie apmeklētāji';
    $html .= '<section class="admin-analytics-section">';
    $html .= '<h2 class="admin-analytics-section__title">' . efpic_admin_esc($chartTitle) . '</h2>';
    $html .= efpic_admin_analytics_render_chart(is_array($summary['daily'] ?? null) ? $summary['daily'] : [], 'unique');
    $html .= '</section>';

    if ($globalSections) {
        $html .= '<section class="admin-analytics-section">';
        $html .= '<h2 class="admin-analytics-section__title">Tiešsaistē pa galerijām</h2>';
        $onlineRows = '';
        foreach (is_array($summary['galleries'] ?? null) ? $summary['galleries'] : [] as $gallery) {
            if ((int) ($gallery['online'] ?? 0) <= 0) {
                continue;
            }
            $onlineRows .= '<tr><td>' . efpic_admin_analytics_gallery_name_cell($gallery) . '</td>';
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
            $token = (string) ($gallery['gallery_token'] ?? '');
            $rows .= '<tr>';
            $rows .= '<td>' . efpic_admin_analytics_gallery_name_cell($gallery) . '</td>';
            $rows .= '<td>' . (int) ($gallery['online'] ?? 0) . '</td>';
            $rows .= '<td>' . (int) ($gallery['today'] ?? 0) . '</td>';
            $rows .= '<td>' . (int) ($gallery['unique_visitors'] ?? 0) . '</td>';
            $rows .= '<td>' . (int) ($gallery['gallery_dl_web'] ?? 0) . '</td>';
            $rows .= '<td>' . (int) ($gallery['gallery_dl_print'] ?? 0) . '</td>';
            $rows .= '<td>' . (int) ($gallery['image_dl'] ?? 0) . '</td>';
            $rows .= '<td>';
            if ($token !== '') {
                $rows .= '<a href="analytics.php?gallery=' . rawurlencode($token) . '">Analītika</a>';
            } else {
                $rows .= '<span class="muted">—</span>';
            }
            $rows .= '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="8" class="muted">Vēl nav apmeklējumu datu.</td></tr>';
        }
        $html .= '<div class="admin-table-wrap"><table class="admin-table admin-analytics-galleries-table"><thead><tr>';
        $html .= '<th>Galerija</th><th>Tiešsaistē</th><th>Šodien</th><th>Kopā apmeklētāji</th>';
        $html .= '<th>Galerijas lejup. WEB</th><th>Galerijas lejup. PRINT</th><th>Bilžu lejupielāde</th><th></th>';
        $html .= '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
        $html .= '</section>';
    }

    return $html;
}

/** @return array{0: ?array<string, mixed>, 1: string} */
function efpic_admin_analytics_resolve_gallery_record(array $config, string $galleryToken): array
{
    $galleryToken = preg_replace('/[^a-f0-9]/', '', strtolower($galleryToken)) ?? '';
    if ($galleryToken === '') {
        return [null, ''];
    }
    $record = efpic_analytics_load_gallery($config, $galleryToken);
    if ($record !== null) {
        efpic_analytics_enrich_record($config, $record);
        return [$record, (string) ($record['name'] ?? '')];
    }
    foreach (efpic_list_gallery_slugs($config) as $slug) {
        $meta = efpic_load_gallery_meta($config, $slug);
        if ($meta === null) {
            continue;
        }
        if (hash_equals((string) ($meta['gallery_token'] ?? ''), $galleryToken)) {
            efpic_analytics_ensure_gallery_record($config, $slug, $meta);

            return [efpic_analytics_load_gallery($config, $galleryToken), (string) ($meta['name'] ?? $slug)];
        }
    }

    return [$record, (string) ($record['name'] ?? 'Dzēsta galerija')];
}

function efpic_admin_analytics_page(array $config): void
{
    [$fromDate, $toDate] = efpic_analytics_parse_date_range(
        isset($_GET['from']) ? (string) $_GET['from'] : null,
        isset($_GET['to']) ? (string) $_GET['to'] : null,
    );
    $galleryToken = trim((string) ($_GET['gallery'] ?? ''));

    if ($galleryToken !== '') {
        [$record, $galleryName] = efpic_admin_analytics_resolve_gallery_record($config, $galleryToken);
        if ($record === null) {
            $body = '<p class="err">Galerijas analītika nav atrasta.</p>';
            efpic_admin_layout('Analītika', $body, 'analytics', 'Analītika', '', $config);

            return;
        }
        $summary = efpic_analytics_aggregate([$record], $fromDate, $toDate);
        $body = '<section class="admin-analytics-page">';
        if (isset($_GET['error']) && trim((string) $_GET['error']) !== '') {
            $body .= '<p class="err">' . efpic_admin_esc((string) $_GET['error']) . '</p>';
        }
        $body .= '<p class="muted"><a href="analytics.php">← Kopējā analītika</a></p>';
        $body .= efpic_admin_render_analytics_dashboard($config, $summary, $fromDate, $toDate, false, $galleryToken);
        $body .= efpic_admin_render_analytics_delete_section($galleryToken, $record);
        $body .= '</section>';
        $eventDate = substr((string) ($record['event_date'] ?? ''), 0, 10);
        $displayName = $eventDate !== '' ? $eventDate . ' ' . $galleryName : $galleryName;
        efpic_admin_layout(
            'Analītika — ' . $displayName,
            $body,
            'analytics',
            $displayName,
            'Galerijas apmeklējumu statistika. Dati saglabājas arī pēc dzēšanas.',
            $config,
        );

        return;
    }

    $records = efpic_analytics_list_gallery_records($config);
    $summary = efpic_analytics_aggregate($records, $fromDate, $toDate);

    $body = '<section class="admin-analytics-page">';
    if (!empty($_GET['stats_deleted'])) {
        $body .= '<p class="admin-flash">Galerijas statistika izdzēsta.</p>';
    }
    if (isset($_GET['error']) && trim((string) $_GET['error']) !== '') {
        $body .= '<p class="err">' . efpic_admin_esc((string) $_GET['error']) . '</p>';
    }
    $body .= efpic_admin_render_analytics_dashboard($config, $summary, $fromDate, $toDate, true, '');
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

function efpic_admin_render_gallery_analytics_tab(array $config, array $meta, string $slug): string
{
    $galleryToken = (string) ($meta['gallery_token'] ?? '');
    if ($galleryToken === '') {
        return '<p class="muted">Analītika būs pieejama pēc galerijas izveides.</p>';
    }

    efpic_analytics_ensure_gallery_record($config, $slug, $meta);
    $record = efpic_analytics_load_gallery($config, $galleryToken);
    if ($record === null) {
        return '<p class="muted">Analītikas dati vēl nav pieejami.</p>';
    }

    [$fromDate, $toDate] = efpic_analytics_parse_date_range(null, null, 14);
    $summary = efpic_analytics_aggregate([$record], $fromDate, $toDate);

    $html = '<div class="admin-analytics-gallery-tab">';
    $html .= '<p class="muted">Pilna analītika ar datumu filtru: ';
    $html .= '<a href="analytics.php?gallery=' . rawurlencode($galleryToken) . '">Atvērt galerijas analītiku</a>.';
    if (!empty($record['deleted_at']) || !efpic_gallery_is_active($meta)) {
        $html .= ' <strong>Statistika saglabāta arī pēc dzēšanas.</strong>';
    }
    $html .= '</p>';
    $html .= efpic_admin_render_analytics_dashboard($config, $summary, $fromDate, $toDate, false, $galleryToken, true);
    $html .= '</div>';

    return $html;
}

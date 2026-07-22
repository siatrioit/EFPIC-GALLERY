<?php

declare(strict_types=1);

require_once __DIR__ . '/handlers.php';
require_once __DIR__ . '/gallery_assets.php';
require_once __DIR__ . '/slideshow_render.php';
require_once __DIR__ . '/face_handlers.php';
require_once __DIR__ . '/analytics_admin.php';

function efpic_admin_session_start(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function efpic_admin_logged_in(): bool
{
    efpic_admin_session_start();

    return !empty($_SESSION['efpic_admin']);
}

function efpic_admin_require_login(array $config): void
{
    if (efpic_admin_logged_in()) {
        if (!function_exists('efpic_analytics_register_admin_ip')) {
            require_once __DIR__ . '/gallery_analytics.php';
        }
        efpic_analytics_register_admin_ip($config);

        return;
    }
    $pass = (string) ($config['dashboard_password'] ?? '');
    if ($pass === '' || $pass === 'change-me-strong-password') {
        http_response_code(503);
        echo 'Admin parole nav konfigurēta (config.php dashboard_password)';
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
        if (hash_equals($pass, (string) $_POST['admin_password'])) {
            $_SESSION['efpic_admin'] = true;
            if (!function_exists('efpic_analytics_register_admin_ip')) {
                require_once __DIR__ . '/gallery_analytics.php';
            }
            efpic_analytics_register_admin_ip($config);
            header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/admin/'));
            exit;
        }
        efpic_admin_render_login(true);

        return;
    }

    efpic_admin_render_login(false);
    exit;
}

function efpic_admin_render_login(bool $failed): void
{
    http_response_code($failed ? 401 : 200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="lv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Admin — EdgarsFoto</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="' . efpic_admin_esc(efpic_asset_url('/client/assets/client.css')) . '">';
    echo '<link rel="stylesheet" href="' . efpic_admin_esc(efpic_asset_url('/admin/assets/admin.css')) . '"></head><body class="page-auth admin-login">';
    echo '<div class="auth-card"><h1>EdgarsFoto</h1><p class="muted" style="margin:0 0 20px">Fotogrāfa panelis</p>';
    if ($failed) {
        echo '<p class="err">Nepareiza parole.</p>';
    }
    echo '<form method="post" class="stack"><label>Parole<input type="password" name="admin_password" required autofocus></label>';
    echo '<button type="submit" class="btn primary">Ieiet</button></form></div></body></html>';
    exit;
}

function efpic_admin_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function efpic_admin_icon_settings(): string
{
    return '<svg class="admin-nav-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">'
        . '<path fill="currentColor" d="M19.14 12.94c.04-.31.06-.63.06-.94s-.02-.63-.06-.94l2.03-1.58a.49.49 0 0 0 .12-.61l-1.92-3.32a.488.488 0 0 0-.59-.22l-2.39.96a7.17 7.17 0 0 0-1.63-.94l-.36-2.54a.484.484 0 0 0-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.56-1.63.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87a.489.489 0 0 0 .12.61l2.03 1.58c-.04.31-.06.63-.06.94s.02.63.06.94l-2.03 1.58a.49.49 0 0 0-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.04.7 1.63.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.63-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32a.49.49 0 0 0-.12-.61l-2.01-1.58zM12 15.6A3.6 3.6 0 1 1 12 8.4a3.6 3.6 0 0 1 0 7.2z"/>'
        . '</svg>';
}

function efpic_admin_layout(
    string $title,
    string $body,
    string $active = '',
    ?string $pageHeading = null,
    ?string $pageLead = null,
    array $config = [],
    string $headExtra = '',
    string $footExtra = '',
): void {
    header('Content-Type: text/html; charset=utf-8');
    $heading = $pageHeading ?? $title;
    echo '<!DOCTYPE html><html lang="lv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . efpic_admin_esc($title) . ' — EdgarsFoto</title>';
    echo efpic_client_favicon_tags($config);
    echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">';
    echo efpic_gallery_intro_fonts_google_link_tags();
    echo '<link rel="stylesheet" href="' . efpic_admin_esc(efpic_asset_url('/client/assets/client.css')) . '">';
    echo '<link rel="stylesheet" href="' . efpic_admin_esc(efpic_asset_url('/admin/assets/admin.css')) . '">';
    if ($headExtra !== '') {
        echo $headExtra;
    }
    echo '</head><body class="admin-body">';
    echo '<div class="admin-shell">';
    echo '<button type="button" class="admin-sidebar-reopen" id="adminSidebarReopen" hidden aria-label="Atvērt izvēlni">';
    echo '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z"/></svg>';
    echo '</button>';
    echo '<aside class="admin-sidebar" id="adminSidebar" aria-label="Galvenā izvēlne">';
    echo '<div class="admin-sidebar-head">';
    echo '<a class="admin-brand" href="index.php">EdgarsFoto</a>';
    echo '<button type="button" class="admin-sidebar-hide" id="adminSidebarHide" aria-label="Paslēpt izvēlni">';
    echo '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M15.41 7.41 14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>';
    echo '</button></div>';
    echo '<nav class="admin-nav">';
    $deletedCount = 0;
    if ($config !== []) {
        foreach (efpic_list_gallery_slugs($config) as $slug) {
            $m = efpic_read_json_file(efpic_gallery_meta_path($config, $slug));
            if ($m !== null && efpic_is_delivery_gallery($m) && efpic_gallery_status($m) === 'deleted') {
                $deletedCount++;
            }
        }
    }
    echo '<a href="index.php"' . ($active === 'list' ? ' class="active"' : '') . '>Galerijas</a>';
    echo '<a href="delivery_new.php"' . ($active === 'new' ? ' class="active"' : '') . '>Jauna Galerija</a>';
    echo '<a href="analytics.php"' . ($active === 'analytics' ? ' class="active"' : '') . '>Analītika</a>';
    echo '<a href="index.php?view=deleted"' . ($active === 'deleted' ? ' class="active"' : '') . '>Dzēstās galerijas';
    if ($deletedCount > 0) {
        echo ' <span class="admin-nav-badge">' . $deletedCount . '</span>';
    }
    echo '</a>';
    echo '</nav>';
    echo '<div class="admin-sidebar-foot">';
    echo '<a href="settings.php" class="admin-sidebar-foot-link' . ($active === 'settings' ? ' active' : '') . '">';
    echo efpic_admin_icon_settings() . '<span>Iestatījumi</span></a>';
    echo '<div class="admin-sidebar-foot-exit">';
    echo '<a class="admin-sidebar-foot-link admin-logout" href="index.php?logout=1"><span>Iziet</span></a>';
    echo '<span class="admin-app-version" title="EFPIC Gallery">' . efpic_admin_esc(efpic_app_version_label()) . '</span>';
    echo '</div>';
    echo '</div></aside>';
    echo '<div class="admin-workspace">';
    echo '<header class="admin-page-head"><h1>' . efpic_admin_esc($heading) . '</h1>';
    if ($pageLead !== null && $pageLead !== '') {
        echo '<p class="admin-lead">' . $pageLead . '</p>';
    }
    echo '</header>';
    echo '<main class="admin-main">' . $body . '</main></div></div>';
    echo '<script src="' . efpic_admin_esc(efpic_asset_url('/admin/assets/cover-theme.js')) . '" defer></script>';
    echo '<script src="' . efpic_admin_esc(efpic_asset_url('/admin/assets/admin.js')) . '" defer></script>';
    if ($footExtra !== '') {
        echo $footExtra;
    }
    echo '</body></html>';
    exit;
}

/** @return array<string, int> */
function efpic_admin_scene_image_counts(array $meta): array
{
    $counts = [];
    foreach ($meta['images'] ?? [] as $img) {
        if (!is_array($img)) {
            continue;
        }
        $sid = (string) ($img['scene_id'] ?? 'main');
        $counts[$sid] = ($counts[$sid] ?? 0) + 1;
    }

    return $counts;
}

function efpic_admin_gallery_video_count(array $meta): int
{
    $stats = $meta['failiem']['sync_stats'] ?? null;
    if (is_array($stats) && array_key_exists('video_count', $stats)) {
        return max(0, (int) $stats['video_count']);
    }

    $count = 0;
    foreach ($meta['videos'] ?? [] as $video) {
        if (is_array($video)) {
            $count++;
        }
    }

    return $count;
}

function efpic_admin_format_sync_datetime_lv(string $iso): string
{
    $iso = trim($iso);
    if ($iso === '' || $iso === '—') {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($iso);

        return $dt->setTimezone(new DateTimeZone('Europe/Riga'))->format('Y-m-d H:i');
    } catch (Throwable) {
        return $iso;
    }
}

function efpic_admin_gallery_expires_sort_key(array $meta): string
{
    $value = efpic_gallery_expires_at_value($meta);

    return $value !== null && $value !== '' ? $value : '9999-12-31';
}

function efpic_admin_gallery_list_sort_next_order(string $column, string $currentSort, string $currentOrder): string
{
    if ($column !== $currentSort) {
        return $column === 'name' ? 'asc' : 'desc';
    }

    return $currentOrder === 'asc' ? 'desc' : 'asc';
}

function efpic_admin_gallery_list_compare_items(array $a, array $b, string $sort): int
{
    $ma = $a['meta'];
    $mb = $b['meta'];

    $cmp = match ($sort) {
        'name' => strnatcasecmp((string) ($ma['name'] ?? ''), (string) ($mb['name'] ?? '')),
        'date' => strcmp((string) ($ma['event_date'] ?? ''), (string) ($mb['event_date'] ?? '')),
        'panel' => (int) efpic_client_portal_enabled($ma) <=> (int) efpic_client_portal_enabled($mb),
        'images' => count($ma['images'] ?? []) <=> count($mb['images'] ?? []),
        'video' => efpic_admin_gallery_video_count($ma) <=> efpic_admin_gallery_video_count($mb),
        'sync' => strcmp(
            (string) ($ma['failiem']['last_sync_at'] ?? ''),
            (string) ($mb['failiem']['last_sync_at'] ?? ''),
        ),
        'expires' => strcmp(
            efpic_admin_gallery_expires_sort_key($ma),
            efpic_admin_gallery_expires_sort_key($mb),
        ),
        default => 0,
    };

    if ($cmp === 0 && $sort !== 'name') {
        $cmp = strnatcasecmp((string) ($ma['name'] ?? ''), (string) ($mb['name'] ?? ''));
    }

    return $cmp;
}

function efpic_admin_gallery_list_sort_th(
    string $column,
    string $label,
    string $currentSort,
    string $currentOrder,
    callable $baseQs,
): string {
    $nextOrder = efpic_admin_gallery_list_sort_next_order($column, $currentSort, $currentOrder);
    $active = $column === $currentSort;
    $arrow = $active ? ($currentOrder === 'asc' ? ' ↑' : ' ↓') : '';

    return '<th class="admin-gallery-list__sortable' . ($active ? ' is-active' : '') . '">'
        . '<a href="' . efpic_admin_esc($baseQs(['sort' => $column, 'order' => $nextOrder])) . '">'
        . efpic_admin_esc($label) . $arrow . '</a></th>';
}

function efpic_admin_color_field(string $name, string $label, string $value): string
{
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
        $value = '#ffffff';
    }
    $value = strtolower($value);

    return '<label class="admin-color-field">' . efpic_admin_esc($label)
        . '<span class="admin-color-control">'
        . '<span class="admin-color-swatch" style="background-color:' . efpic_admin_esc($value) . ';" aria-hidden="true"></span>'
        . '<input type="color" class="admin-color-input" name="' . efpic_admin_esc($name) . '" value="' . efpic_admin_esc($value) . '">'
        . '<code class="admin-color-value">' . efpic_admin_esc($value) . '</code>'
        . '</span></label>';
}


function efpic_admin_render_failiem_fieldset(array $failiem): string
{
    $html = '<fieldset class="admin-fieldset-full" id="admin-fs-failiem"><legend>Failiem.lv mapes</legend>';
    $html .= '<p class="muted admin-fieldset-full">Pilns izmērs (PRINT) un web (mazāks). Video — atsevišķā mapē. Piem. https://failiem.lv/u/…</p>';
    $html .= '<div class="admin-form-row admin-form-row--failiem">';
    $html .= '<label>Galvenā mape (AI meklēšanai, opcija)<input name="folder_parent_url" value="'
        . efpic_admin_esc((string) ($failiem['folder_parent_url'] ?? '')) . '" placeholder="https://failiem.lv/u/3989fkmbt7"></label>';
    $html .= '<label>Mapes pilns<input name="folder_full_url" value="' . efpic_admin_esc((string) ($failiem['folder_full_url'] ?? '')) . '"></label>';
    $html .= '<label>Mapes web<input name="folder_web_url" value="' . efpic_admin_esc((string) ($failiem['folder_web_url'] ?? '')) . '"></label>';
    $html .= '<label>Mapes video<input name="folder_video_url" value="' . efpic_admin_esc((string) ($failiem['folder_video_url'] ?? '')) . '" placeholder="https://failiem.lv/u/…"></label>';
    $html .= '</div></fieldset>';

    return $html;
}

function efpic_admin_render_new_gallery_scenes_fieldset(string $sceneTitle): string
{
    $html = '<fieldset class="admin-fieldset-full" id="admin-fs-scenes"><legend>Galerijas sadaļas</legend>';
    $html .= '<label>Pirmās sadaļas virsraksts<input name="scene_title" value="' . efpic_admin_esc($sceneTitle) . '"></label>';
    $html .= '<p class="muted">Pēc izveides varēs pievienot vairākas sadaļas.</p></fieldset>';

    return $html;
}

function efpic_admin_render_theme_fieldset(array $config, array $formMeta): string
{
    $html = '<fieldset class="admin-fieldset-full" id="admin-fs-theme"><legend>Dizains</legend>';
    $html .= '<input type="hidden" name="theme" value="efpic-base">';
    $html .= efpic_render_design_template_controls($config, $formMeta);
    $html .= efpic_render_design_palette_picker($config, $formMeta);
    $html .= efpic_render_cover_theme_controls($config, $formMeta, false);
    $html .= '</fieldset>';

    return $html;
}

function efpic_admin_render_scenes_fieldset(array $meta): string
{
    $scenes = efpic_gallery_scene_options($meta);
    $counts = efpic_admin_scene_image_counts($meta);
    $scenesJson = json_encode(array_map(static function ($s) use ($counts) {
        return [
            'id' => $s['id'],
            'title' => $s['title'],
            'count' => $counts[$s['id']] ?? 0,
        ];
    }, $scenes), JSON_UNESCAPED_UNICODE);

    $html = '<fieldset class="admin-fieldset-full admin-scenes-panel admin-scenes-panel--above-images" id="admin-fs-scenes"><legend>Galerijas sadaļas</legend>';
    $html .= '<p class="muted">Klients publiskajā saitē redz sadaļu izvēlni. Secību maini ar ⋮⋮ (velc) vai ↑↓. «Atlasīt bildes» — izvēlas visas bildes šajā sadaļā zemāk.</p>';
    $html .= '<input type="hidden" name="scenes_json" id="scenes_json" value="' . efpic_admin_esc($scenesJson) . '">';
    $html .= '<div id="admin-scenes-editor" class="admin-scenes-editor" data-scenes="' . efpic_admin_esc($scenesJson) . '"></div>';
    $html .= '<button type="button" class="btn admin-btn-inline" id="admin-add-scene">+ Pievienot sadaļu</button>';
    $html .= '</fieldset>';

    return $html;
}

function efpic_admin_render_image_scene_toolbar(array $meta): string
{
    $scenes = efpic_gallery_scene_options($meta);
    $counts = efpic_admin_scene_image_counts($meta);
    $total = count($meta['images'] ?? []);
    $shareIndex = efpic_share_sets_token_index($meta);
    $inShareCount = count($shareIndex);
    $adminFavCount = efpic_count_favorites($meta, 'admin');
    $clientFavCount = efpic_count_favorites($meta, 'client');
    $likesTotal = efpic_gallery_total_likes($meta);
    $likedImages = 0;
    foreach ($meta['images'] ?? [] as $img) {
        if (is_array($img) && efpic_image_likes_count($img) > 0) {
            $likedImages++;
        }
    }

    $html = '<div class="admin-image-bulk-bar" id="admin-image-bulk-bar">';
    $html .= '<datalist id="admin-scene-datalist">';
    foreach ($scenes as $scene) {
        $html .= '<option value="' . efpic_admin_esc($scene['title']) . '"></option>';
    }
    $html .= '</datalist>';
    $html .= '<p class="admin-image-bulk-lead">Klikšķis uz bildes — atlase kopīgošanai; <kbd>Shift</kbd> — diapazons. '
        . 'Sadaļu maini pie bildes (lauks «Sadaļa»). Kopīgošanu veido cilnē <strong>Kopīgošana</strong>. '
        . 'Sirsniņas: <strong id="admin-likes-total">' . $likesTotal . '</strong>.</p>';
    $html .= '<div class="admin-image-bulk-row">';
    $html .= '<span class="admin-pick-count" id="admin-pick-count" aria-live="polite">0 atlasītas</span>';
    $html .= '<button type="button" class="btn admin-btn-inline" id="admin-select-visible-images">Atlasīt redzamās</button>';
    $html .= '<button type="button" class="btn admin-btn-inline" id="admin-select-all-images">Atlasīt visas</button>';
    $html .= '<button type="button" class="btn admin-btn-inline" id="admin-clear-image-selection">Noņemt atlasi</button>';
    $html .= '<button type="submit" class="btn admin-btn-inline" name="rebaseline_scene_sort" value="1" formnovalidate>Kārtot pēc nosaukuma (sadaļās)</button>';
    $html .= '</div>';
    $html .= '<div class="admin-scene-filter" id="admin-scene-filter" role="group" aria-label="Filtrēt bildes">';
    $html .= '<button type="button" class="btn admin-scene-filter-btn is-active" data-scene-filter="all">Visas (' . $total . ')</button>';
    $html .= '<button type="button" class="btn admin-scene-filter-btn" data-scene-filter="in-share">Kopīgotās (' . $inShareCount . ')</button>';
    foreach ($scenes as $scene) {
        $n = (int) ($counts[$scene['id']] ?? 0);
        $html .= '<button type="button" class="btn admin-scene-filter-btn" data-scene-filter="' . efpic_admin_esc($scene['id']) . '">'
            . efpic_admin_esc($scene['title']) . ' (' . $n . ')</button>';
    }
    $html .= '<button type="button" class="btn admin-scene-filter-btn" data-scene-filter="admin-fav">★ Manas (' . $adminFavCount . ')</button>';
    $html .= '<button type="button" class="btn admin-scene-filter-btn" data-scene-filter="client-fav">★ Klienta (' . $clientFavCount . ')</button>';
    $html .= '<button type="button" class="btn admin-scene-filter-btn" data-scene-filter="liked">♥ Sirsniņas (' . $likedImages . ')</button>';
    $html .= '</div></div>';

    return $html;
}

function efpic_admin_share_created_by_label(array $guest): string
{
    $by = (string) ($guest['created_by'] ?? 'admin');

    return $by === 'client' ? 'Klienta panelis' : 'Fotogrāfa panelis';
}

function efpic_admin_short_display_url(string $url, int $maxLen = 52): string
{
    if (strlen($url) <= $maxLen) {
        return $url;
    }

    return substr($url, 0, $maxLen - 1) . '…';
}

function efpic_admin_link_icon_copy(): string
{
    return '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg>';
}

function efpic_admin_link_icon_share(): string
{
    return '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92s2.92-1.31 2.92-2.92-1.31-2.92-2.92-2.92z"/></svg>';
}

function efpic_admin_render_link_row(string $url): string
{
    $display = efpic_admin_short_display_url($url);
    $html = '<span class="admin-link-row">';
    $html .= '<a class="admin-link-row__url" href="' . efpic_admin_esc($url) . '" target="_blank" rel="noopener" title="'
        . efpic_admin_esc($url) . '">' . efpic_admin_esc($display) . '</a>';
    $html .= '<span class="admin-link-row__actions">';
    $html .= '<button type="button" class="admin-link-btn admin-link-copy" data-copy-url="' . efpic_admin_esc($url)
        . '" title="Kopēt saiti" aria-label="Kopēt saiti">' . efpic_admin_link_icon_copy() . '</button>';
    $html .= '<button type="button" class="admin-link-btn admin-link-share" data-share-url="' . efpic_admin_esc($url)
        . '" title="Kopīgot" aria-label="Kopīgot">' . efpic_admin_link_icon_share() . '</button>';
    $html .= '</span></span>';

    return $html;
}

function efpic_admin_render_link_row_disabled(string $url): string
{
    $display = efpic_admin_short_display_url($url);
    $html = '<span class="admin-link-row is-disabled" aria-disabled="true">';
    $html .= '<span class="admin-link-row__url admin-link-row__url--disabled" title="'
        . efpic_admin_esc($url) . '">' . efpic_admin_esc($display) . '</span>';
    $html .= '</span>';

    return $html;
}

/** @return array{gallery_password_set: bool, client_password_set: bool} */
function efpic_admin_password_fields_payload(array $meta): array
{
    return [
        'gallery_password_set' => efpic_gallery_has_password($meta),
        'client_password_set' => efpic_client_portal_has_password($meta),
    ];
}

/** @return array{gallery_token: string, public_link_html: string, share_sets_html: string, share_index: list<string>, share_counts: array<string, int>} */
function efpic_admin_gallery_links_payload(array $config, array $meta): array
{
    $gt = (string) ($meta['gallery_token'] ?? '');
    $shareIndex = efpic_share_sets_token_index($meta);

    return [
        'gallery_token' => $gt,
        'public_link_html' => efpic_admin_render_link_row(efpic_gallery_view_url($config, $gt)),
        'share_sets_html' => efpic_admin_render_share_sets_body($config, $meta),
        'share_index' => array_keys($shareIndex),
        'share_counts' => efpic_share_sets_count_index($meta),
    ];
}

function efpic_admin_render_media_lightbox(string $id = 'admin-lightbox'): string
{
    $html = '<div id="' . efpic_admin_esc($id) . '" class="admin-lightbox" hidden role="dialog" aria-modal="true" aria-label="Bildes priekšskatījums">';
    $html .= '<button type="button" class="admin-lightbox-close" aria-label="Aizvērt">&times;</button>';
    $html .= '<button type="button" class="admin-lightbox-nav admin-lightbox-prev" data-lightbox-prev aria-label="Iepriekšējā" hidden>‹</button>';
    $html .= '<button type="button" class="admin-lightbox-nav admin-lightbox-next" data-lightbox-next aria-label="Nākamā" hidden>›</button>';
    $html .= '<img src="" alt="">';
    $html .= '<div class="admin-lightbox-actions" id="admin-lightbox-share-actions" hidden>';
    $html .= '<button type="button" class="btn primary" id="admin-lightbox-share-remove">Izņemt no izlases</button>';
    $html .= '</div></div>';

    return $html;
}

function efpic_admin_render_share_set_thumbs(array $config, array $meta, array $guest): string
{
    $tokens = $guest['image_tokens'] ?? [];
    if (!is_array($tokens) || $tokens === []) {
        return '';
    }
    $guestToken = (string) ($guest['guest_token'] ?? '');
    $byToken = [];
    foreach ($meta['images'] ?? [] as $img) {
        if (is_array($img) && ($img['token'] ?? '') !== '') {
            $byToken[(string) $img['token']] = $img;
        }
    }
    $html = '<div class="admin-share-set-thumbs">';
    foreach ($tokens as $tok) {
        $tok = (string) $tok;
        if ($tok === '' || !isset($byToken[$tok])) {
            continue;
        }
        $img = $byToken[$tok];
        $thumb = efpic_admin_media_thumb_url($config, $img);
        $preview = efpic_client_media_url($config, $img, 'web', 1200);
        $html .= '<button type="button" class="admin-share-set-thumb"'
            . ' data-preview="' . efpic_admin_esc($preview) . '"'
            . ' data-token="' . efpic_admin_esc($tok) . '"'
            . ' data-guest-token="' . efpic_admin_esc($guestToken) . '"'
            . ' aria-label="Priekšskatījums">';
        $html .= '<img src="' . efpic_admin_esc($thumb) . '" alt="" width="96" height="96" loading="lazy">';
        $html .= '</button>';
    }
    $html .= '</div>';

    return $html;
}

function efpic_admin_render_share_sets_body(array $config, array $meta): string
{
    $gt = (string) ($meta['gallery_token'] ?? '');
    $guests = $meta['guests'] ?? [];
    if (!is_array($guests)) {
        $guests = [];
    }
    $hasVideos = efpic_admin_gallery_video_count($meta) > 0;
    $hasSlideshow = efpic_gallery_has_shareable_slideshow($meta);

    $html = '<fieldset class="admin-fieldset-full admin-share-compose-fs" id="admin-share-compose">';
    $html .= '<legend>Jauna kopīgojamā izlase</legend>';
    $html .= '<div class="admin-share-compose-row">';
    $html .= '<label>Kam / nosaukums<input type="text" id="admin-share-new-label" placeholder="piem. Dekoratore Anna" autocomplete="off"></label>';
    if ($hasVideos) {
        $html .= efpic_render_admin_toggle('Video izlasē', false, [
            'id' => 'admin-share-new-videos',
            'inline' => true,
            'class' => 'admin-share-videos-check',
        ]);
    }
    if ($hasSlideshow) {
        $html .= efpic_render_admin_toggle('Slideshow izlasē', true, [
            'id' => 'admin-share-new-slideshow',
            'inline' => true,
            'class' => 'admin-share-slideshow-check',
        ]);
    }
    $html .= '<button type="button" class="btn admin-btn-sm primary" id="admin-share-start-new">Sākt jaunu izlasi</button>';
    $html .= '</div></fieldset>';

    $setBlocks = '';
    foreach ($guests as $g) {
        if (!is_array($g)) {
            continue;
        }
        $gtok = (string) ($g['guest_token'] ?? '');
        if ($gtok === '') {
            continue;
        }
        $n = efpic_share_set_image_count($g);
        if ($n === 0) {
            continue;
        }
        $tokens = [];
        foreach ($g['image_tokens'] ?? [] as $tok) {
            $tok = (string) $tok;
            if ($tok !== '') {
                $tokens[] = $tok;
            }
        }
        $url = efpic_gallery_view_url($config, $gt, $gtok);
        $created = substr((string) ($g['created_at'] ?? ''), 0, 10);
        $includeVideos = !empty($g['include_videos']);
        $includeSlideshow = !array_key_exists('include_slideshow', $g) || !empty($g['include_slideshow']);
        $label = (string) ($g['label'] ?? 'Izlase');
        $fsId = 'admin-share-set-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $gtok);
        $setBlocks .= '<fieldset class="admin-fieldset-full admin-share-set-item" id="' . efpic_admin_esc($fsId) . '"'
            . ' data-guest-token="' . efpic_admin_esc($gtok) . '"'
            . ' data-share-label="' . efpic_admin_esc($label) . '"'
            . ' data-share-tokens="' . efpic_admin_esc(implode(',', $tokens)) . '"'
            . ' data-share-videos="' . ($includeVideos ? '1' : '0') . '"'
            . ' data-share-slideshow="' . ($includeSlideshow ? '1' : '0') . '">';
        $setBlocks .= '<legend>' . efpic_admin_esc($label) . '</legend>';
        $setBlocks .= '<p class="muted admin-share-set-meta">' . $n . ' bildes · ' . efpic_admin_esc(efpic_admin_share_created_by_label($g));
        if ($created !== '') {
            $setBlocks .= ' · ' . efpic_admin_esc($created);
        }
        $metaBits = [];
        if ($hasVideos) {
            $metaBits[] = 'Video: ' . ($includeVideos ? 'jā' : 'nē');
        }
        if ($hasSlideshow) {
            $metaBits[] = 'Slideshow: ' . ($includeSlideshow ? 'jā' : 'nē');
        }
        if ($metaBits !== []) {
            $setBlocks .= ' · ' . efpic_admin_esc(implode(' · ', $metaBits));
        }
        $setBlocks .= '</p>';
        $setBlocks .= '<div class="admin-share-set-toggles">';
        if ($hasVideos) {
            $setBlocks .= efpic_render_admin_toggle('Video izlasē', $includeVideos, [
                'inline' => true,
                'class' => 'admin-share-videos-toggle',
                'input_class' => 'admin-share-videos-cb',
                'input_attrs' => 'data-guest-token="' . efpic_admin_esc($gtok) . '"',
            ]);
        }
        if ($hasSlideshow) {
            $setBlocks .= efpic_render_admin_toggle('Slideshow izlasē', $includeSlideshow, [
                'inline' => true,
                'class' => 'admin-share-slideshow-toggle',
                'input_class' => 'admin-share-slideshow-cb',
                'input_attrs' => 'data-guest-token="' . efpic_admin_esc($gtok) . '"',
            ]);
        }
        $setBlocks .= '</div>';
        $setBlocks .= efpic_admin_render_share_set_thumbs($config, $meta, $g);
        $setBlocks .= '<div class="admin-share-set-foot">';
        $setBlocks .= efpic_admin_render_link_row($url);
        $setBlocks .= '<div class="admin-share-set-actions">';
        $setBlocks .= '<button type="button" class="btn admin-btn-sm primary admin-share-edit" data-guest-token="' . efpic_admin_esc($gtok)
            . '">Labot izlasi</button>';
        $setBlocks .= '<button type="button" class="btn admin-btn-sm admin-share-delete" data-guest-token="' . efpic_admin_esc($gtok)
            . '">Dzēst</button>';
        $setBlocks .= '</div></div></fieldset>';
    }

    if ($setBlocks === '') {
        $html .= '<p class="muted admin-share-empty">Nav nevienas kopīgojamās izlases.</p>';
    } else {
        $html .= $setBlocks;
    }

    return $html;
}

function efpic_admin_render_share_sets(array $config, array $meta): string
{
    $html = '<div class="admin-form admin-share-sets-panel" id="admin-share-sets-panel">';
    $html .= '<div class="admin-share-sets-toolbar">';
    $html .= '<p class="admin-share-sets-toolbar__title">Kopīgojamās izlases</p>';
    $html .= '<button type="button" class="portal-images-action-bar__info admin-share-info-btn" data-share-info-open'
        . ' aria-haspopup="dialog" aria-controls="adminShareInfoModal" aria-label="Kā veidot un labot izlases">';
    $html .= '<span aria-hidden="true">i</span></button>';
    $html .= '</div>';
    $html .= '<div id="admin-share-sets-body" class="admin-share-sets-body">'
        . efpic_admin_render_share_sets_body($config, $meta) . '</div>';
    $html .= '<div class="portal-images-info-modal" id="adminShareInfoModal" hidden>';
    $html .= '<div class="portal-images-info-dialog" role="dialog" aria-modal="true" aria-labelledby="adminShareInfoTitle">';
    $html .= '<button type="button" class="portal-images-info-close" data-share-info-close aria-label="Aizvērt">&times;</button>';
    $html .= '<h2 id="adminShareInfoTitle">Kā strādā kopīgojamās izlases</h2>';
    $html .= '<ul class="portal-images-info-list">';
    $html .= '<li><strong>Jauna izlase</strong>';
    $html .= '<p>Ievadi nosaukumu (kam paredzēta) un spied «Sākt jaunu izlasi». Atvērsies cilne Bildes — atzīmē bildes un spied «Saglabāt izlasi».</p></li>';
    $html .= '<li><strong>Labot izlasi</strong>';
    $html .= '<p>Spied «Labot izlasi» pie esošās izlases. Cilnē Bildes vari pievienot vai noņemt bildes, tad saglabā. Saglabātā saite paliek tā pati.</p></li>';
    $html .= '<li><strong>Video un Slideshow</strong>';
    $html .= '<p>Ja galerijā ir video vai slideshow, slēdži ļauj izvēlēties, vai tie rādās šajā izlasē.</p></li>';
    $html .= '<li><strong>Saites kopīgošana</strong>';
    $html .= '<p>Katram blokam ir sava saite — to vari nokopēt un nosūtīt. Viesis redzēs tikai izvēlētās bildes.</p></li>';
    $html .= '</ul></div></div>';
    $html .= '</div>';

    return $html;
}

function efpic_admin_render_favorite_thumb_grid(array $config, array $meta, string $who, bool $editable, bool $showNames = true): string
{
    $ctx = ['guest_token' => '', 'share_image_tokens' => null, 'share_include_videos' => false, 'share_include_slideshow' => true];
    if ($who === 'admin') {
        $draft = efpic_gallery_slideshow_storage($meta)['draft'];
        $order = is_array($draft['image_order_tokens'] ?? null) ? $draft['image_order_tokens'] : [];
        $images = efpic_slideshow_sort_images_for_render(
            efpic_slideshow_favorite_images($meta, $ctx, $config, 'admin'),
            $order,
        );
    } else {
        $images = efpic_slideshow_favorite_images($meta, $ctx, $config, 'client');
    }

    $html = '<ul class="admin-fav-grid">';
    $hasAny = false;
    foreach ($images as $img) {
        if (!is_array($img)) {
            continue;
        }
        $tok = (string) ($img['token'] ?? '');
        if ($tok === '') {
            continue;
        }
        $hasAny = true;
        $thumb = efpic_admin_media_thumb_url($config, $img);
        $preview = efpic_client_media_url($config, $img, 'web', 1200);
        $html .= '<li class="admin-fav-item">';
        if ($editable) {
            $html .= '<label class="admin-fav-card is-selected">';
            $html .= '<input type="checkbox" name="image_fav_admin[' . efpic_admin_esc($tok) . ']" value="1" checked>';
        } else {
            $html .= '<div class="admin-fav-card is-readonly">';
        }
        $html .= '<img src="' . efpic_admin_esc($thumb) . '" alt="">';
        if ($showNames) {
            $html .= '<span class="admin-sort-name">' . efpic_admin_esc((string) ($img['basename'] ?? $tok)) . '</span>';
        }
        $html .= $editable ? '</label>' : '</div>';
        $html .= '<button type="button" class="admin-fav-preview" data-preview="' . efpic_admin_esc($preview) . '" aria-label="Priekšskatījums">⤢</button>';
        $html .= '</li>';
    }
    if (!$hasAny) {
        $html .= '<li class="admin-fav-empty muted">' . ($who === 'admin' ? 'Vēl nav izvēlēts neviens favorīts.' : 'Klients vēl nav atzīmējis favorītus.') . '</li>';
    }
    $html .= '</ul>';

    return $html;
}

function efpic_slideshow_form_prefix(string $owner, string $itemId = ''): string
{
    if ($owner === 'client') {
        return 'slideshow_client';
    }
    if ($itemId === 'draft') {
        return 'slideshow_draft';
    }
    if ($itemId === 'favorites') {
        return 'slideshow_favorites';
    }
    if ($itemId !== '') {
        return 'slideshow_item_' . $itemId;
    }

    return 'slideshow_admin';
}

function efpic_admin_slideshow_item_dom_id(string $itemId, string $suffix): string
{
    if ($itemId === 'draft') {
        return 'slideshow-draft-' . $suffix;
    }

    return 'slideshow-item-' . $itemId . '-' . $suffix;
}

function efpic_admin_slideshow_block_start(string $title): string
{
    return '<section class="admin-slideshow-block"><h4 class="admin-slideshow-block__title">' . efpic_admin_esc($title) . '</h4>';
}

function efpic_admin_slideshow_block_end(): string
{
    return '</section>';
}

function efpic_admin_render_slideshow_section_settings(array $meta, array $slot, string $owner = 'admin', bool $includeMarker = false, string $itemId = ''): string
{
    $prefix = efpic_slideshow_form_prefix($owner, $itemId);
    $esc = $owner === 'client' ? 'efpic_client_esc' : 'efpic_admin_esc';
    $placement = (string) ($slot['section_placement'] ?? 'top');
    if ($placement === 'after_scene') {
        $placement = 'before_scene';
    }
    if (!in_array($placement, ['top', 'bottom', 'before_scene'], true)) {
        $placement = 'top';
    }
    $afterScene = (string) ($slot['section_after_scene'] ?? '');

    $html = '<div class="admin-slideshow-section-settings admin-form-split">';
    if ($includeMarker) {
        $html .= '<input type="hidden" name="' . $esc($prefix . '_placement_fields') . '" value="1">';
    }
    $html .= '<label>Sadaļas virsraksts<input type="text" name="' . $esc($prefix . '_section_title') . '" maxlength="80" value="'
        . $esc((string) ($slot['section_title'] ?? '')) . '" placeholder="'
        . ($owner === 'client' ? 'Klienta slideshow' : 'Slideshow') . '"></label>';
    $html .= '<label>Sadaļas vieta<select name="' . $esc($prefix . '_section_placement') . '">';
    $html .= '<option value="top"' . ($placement === 'top' ? ' selected' : '') . '>Galerijas augšā (pirms sadaļām)</option>';
    $html .= '<option value="before_scene"' . ($placement === 'before_scene' ? ' selected' : '') . '>Pirms konkrētas sadaļas</option>';
    $html .= '<option value="bottom"' . ($placement === 'bottom' ? ' selected' : '') . '>Galerijas apakšā</option>';
    $html .= '</select></label>';
    $html .= '<label>Pirms sadaļas<select name="' . $esc($prefix . '_section_after_scene') . '">';
    $html .= '<option value="">— izvēlies —</option>';
    foreach (efpic_gallery_scene_options($meta) as $scene) {
        $sid = (string) ($scene['id'] ?? '');
        if ($sid === '') {
            continue;
        }
        $html .= '<option value="' . $esc($sid) . '"' . ($afterScene === $sid ? ' selected' : '') . '>'
            . $esc((string) ($scene['title'] ?? $sid)) . '</option>';
    }
    $html .= '</select></label>';
    $html .= '</div>';

    return $html;
}

/** @return list<array{owner: string, id: string, key: string, order: int}> */
function efpic_admin_slideshow_ready_sort_entries(array $meta): array
{
    $storage = efpic_gallery_slideshow_storage($meta);
    $entries = [];
    $client = efpic_slideshow_slot_with_render($storage['client']);
    if (efpic_slideshow_item_is_published($client)) {
        $entries[] = [
            'owner' => 'client',
            'id' => 'client',
            'key' => 'client',
            'order' => efpic_slideshow_ready_effective_order($client, 'client'),
        ];
    }
    $adminIndex = 0;
    foreach ($storage['items'] as $item) {
        if (!efpic_slideshow_item_is_published($item)) {
            continue;
        }
        ++$adminIndex;
        $id = (string) ($item['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $entries[] = [
            'owner' => 'admin',
            'id' => $id,
            'key' => $id,
            'order' => efpic_slideshow_ready_effective_order($item, 'admin', $adminIndex),
        ];
    }
    usort($entries, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

    return $entries;
}

function efpic_admin_render_slideshow_enabled_toggle(array $slot, string $prefix): string
{
    $name = $prefix . '_enabled';
    $enabled = !empty($slot['enabled']);
    $html = '<input type="hidden" name="' . efpic_admin_esc($name) . '" value="0">';
    $html .= efpic_render_admin_toggle('Rādīt publiskajā galerijā', $enabled, [
        'name' => $name,
        'value' => '1',
        'class' => 'admin-slideshow-ready__toggle',
    ]);

    return $html;
}

function efpic_admin_slideshow_public_status_text(array $meta, array $slot): string
{
    if (!efpic_slideshow_slot_video_ready($slot)) {
        return 'Video vēl nav gatavs publiskai rādīšanai.';
    }
    if (empty($slot['enabled'])) {
        return 'Slēgts — ieslēdz «Rādīt publiskajā galerijā», lai parādītos apmeklētājiem.';
    }
    $placement = (string) ($slot['section_placement'] ?? 'top');
    if ($placement === 'after_scene') {
        $placement = 'before_scene';
    }
    if ($placement === 'before_scene' && ($slot['section_after_scene'] ?? '') === '') {
        $placement = 'top';
    }
    if ($placement === 'top') {
        return 'Redzams galerijā — augšā, pirms sadaļām.';
    }
    if ($placement === 'bottom') {
        return 'Redzams galerijā — apakšā, pēc visām bildēm.';
    }
    $sceneId = (string) ($slot['section_after_scene'] ?? '');
    foreach (efpic_gallery_scene_options($meta) as $scene) {
        if ((string) ($scene['id'] ?? '') === $sceneId) {
            return 'Redzams galerijā — pirms sadaļas «' . (string) ($scene['title'] ?? $sceneId) . '».';
        }
    }

    return 'Redzams galerijā — pirms izvēlētās sadaļas.';
}

function efpic_admin_render_slideshow_order_controls(string $owner, string $itemId, int $sortIndex, int $sortCount): string
{
    if ($sortIndex < 0 || $sortCount < 2) {
        return '';
    }
    $value = $owner === 'client' ? 'client' : $itemId;
    $esc = 'efpic_admin_esc';
    $canMoveUp = $sortIndex > 0;
    $canMoveDown = $sortIndex < $sortCount - 1;
    if (!$canMoveUp && !$canMoveDown) {
        return '';
    }

    $html = '<div class="admin-slideshow-order-controls">';
    $html .= '<span class="admin-slideshow-order-controls__label">Vieta galerijā</span>';
    if ($canMoveUp) {
        $html .= '<button type="submit" class="btn admin-slideshow-order-btn" name="slideshow_move_up" value="'
            . $esc($value) . '">Augšāk</button>';
    }
    if ($canMoveDown) {
        $html .= '<button type="submit" class="btn admin-slideshow-order-btn" name="slideshow_move_down" value="'
            . $esc($value) . '">Zemāk</button>';
    }
    $html .= '</div>';

    return $html;
}

function efpic_admin_render_slideshow_image_source_options(
    array $config,
    array $meta,
    array $slot,
    string $prefix,
    string $itemId = '',
): string {
    $source = (string) ($slot['image_source'] ?? 'favorites');
    if (!in_array($source, ['favorites', 'all', 'scenes'], true)) {
        $source = 'favorites';
    }
    $selectedScenes = is_array($slot['image_scene_ids'] ?? null) ? $slot['image_scene_ids'] : [];
    $sourceKey = $prefix . '_image_source';
    $sourceOptions = [
        'favorites' => 'Izveidot no favorītbildēm',
        'scenes' => 'Izveidot no galerijas sadaļām',
        'all' => 'No visām bildēm',
    ];
    $html = '<div class="admin-slideshow-source admin-fieldset-full" data-slideshow-source>';
    $html .= '<input type="hidden" name="' . efpic_admin_esc($sourceKey) . '" value="' . efpic_admin_esc($source) . '" data-slideshow-source-input>';
    $html .= '<div class="admin-slideshow-source-toggles">';
    foreach ($sourceOptions as $value => $label) {
        $html .= efpic_render_admin_toggle($label, $source === $value, [
            'class' => 'admin-slideshow-source-toggle',
            'input_attrs' => 'data-slideshow-source-value="' . efpic_admin_esc($value) . '"',
        ]);
    }
    $html .= '</div>';

    $html .= '<div class="admin-slideshow-source__panel admin-slideshow-source__panel--favorites'
        . ($source === 'favorites' ? ' is-visible' : '') . '" data-composer-favorites-panel>';
    if ($itemId === 'draft') {
        $html .= efpic_admin_render_composer_favorites_panel($config, $meta, $slot);
    } else {
        $html .= '<p class="muted">Favorītus atzīmē cilnē «Favorītbildes» vai Bildes cilnē. Šeit vari mainīt bilžu secību slideshow video.</p>';
        $html .= efpic_admin_render_slideshow_image_grid($config, $meta, $slot, 'admin', null, $itemId, true);
    }
    $html .= '</div>';

    $html .= '<div class="admin-slideshow-source__panel admin-slideshow-source__panel--scenes'
        . ($source === 'scenes' ? ' is-visible' : '') . '">';
    $html .= '<p class="muted">Ieslēdz vienu vai vairākas sadaļas. Bildes tiks ņemtas sadaļu secībā.</p>';
    $html .= '<div class="admin-slideshow-scene-toggles">';
    foreach (efpic_gallery_scene_options($meta) as $scene) {
        $sid = (string) ($scene['id'] ?? '');
        if ($sid === '') {
            continue;
        }
        $checked = in_array($sid, $selectedScenes, true);
        $html .= efpic_render_admin_toggle((string) ($scene['title'] ?? $sid), $checked, [
            'name' => $prefix . '_scene_ids[]',
            'value' => $sid,
            'class' => 'admin-slideshow-scene-toggle',
        ]);
    }
    $html .= '</div>';
    $html .= '</div>';

    $html .= '<div class="admin-slideshow-source__panel admin-slideshow-source__panel--all'
        . ($source === 'all' ? ' is-visible' : '') . '">';
    $html .= '<p class="muted">Slideshow tiks taisīts no visām redzamajām bildēm galerijas kārtībā.</p>';
    $html .= '</div>';
    $html .= '</div>';

    return $html;
}

function efpic_admin_render_slideshow_image_grid(
    array $config,
    array $meta,
    array $slot,
    string $owner = 'admin',
    ?string $blockTitle = null,
    string $itemId = '',
    bool $reorderOnly = false,
    bool $favoritesPicker = false,
): string
{
    $prefix = efpic_slideshow_form_prefix($owner, $itemId);
    $esc = $owner === 'client' ? 'efpic_client_esc' : 'efpic_admin_esc';
    $listId = $owner === 'client'
        ? 'portal-slideshow-order-list'
        : ($itemId !== '' ? efpic_admin_slideshow_item_dom_id($itemId, 'order-list') : 'admin-slideshow-order-list');
    $orderInputId = $owner === 'client'
        ? 'slideshow-client-image-order'
        : ($itemId !== '' ? efpic_admin_slideshow_item_dom_id($itemId, 'image-order') : 'slideshow-admin-image-order');
    $favField = $owner === 'client' ? 'image_fav_client' : 'image_fav_admin';
    $source = $owner === 'admin' ? (string) ($slot['image_source'] ?? 'favorites') : 'favorites';
    if ($favoritesPicker) {
        $source = 'favorites';
    }
    if (!in_array($source, ['favorites', 'all', 'scenes'], true)) {
        $source = 'favorites';
    }
    $sceneIds = is_array($slot['image_scene_ids'] ?? null) ? $slot['image_scene_ids'] : [];
    $images = efpic_slideshow_sort_images_for_render(
        efpic_slideshow_collect_images_for_render($config, $meta, $owner, $source, $sceneIds),
        is_array($slot['image_order_tokens'] ?? null) ? $slot['image_order_tokens'] : [],
    );
    if ($owner === 'admin' && $source === 'favorites' && !$favoritesPicker) {
        $images = array_values(array_filter($images, static function (array $img): bool {
            return !efpic_image_favorited_client($img);
        }));
    }
    if ($source === 'all' && !$reorderOnly && !$favoritesPicker) {
        return '<p class="muted admin-slideshow-order-empty">Tiks izmantotas visas redzamās bildes galerijas kārtībā.</p>';
    }
    if ($source === 'scenes' && $sceneIds === [] && !$reorderOnly) {
        return '<p class="muted admin-slideshow-order-empty">Izvēlies vismaz vienu galerijas sadaļu.</p>';
    }
    if ($images === []) {
        if ($favoritesPicker) {
            return '<p class="muted admin-slideshow-order-empty">Vēl nav izvēlēts neviens favorīts.</p>';
        }

        return '<p class="muted admin-slideshow-order-empty">Nav bilžu — atzīmē favorītus cilnē «Favorītbildes»'
            . ($owner === 'admin' ? ' vai izvēlies citu avotu' : '') . '.</p>';
    }

    $tokens = [];
    $html = '<div class="admin-slideshow-order">';
    if (!$favoritesPicker) {
        if ($blockTitle !== null) {
            $html .= '<p class="muted">' . ($owner === 'admin'
                ? 'Atzīmē savus favorītus un velc bildes, lai mainītu kārtību video ģenerēšanai.'
                : 'Atzīmē favorītus un velc bildes, lai mainītu kārtību video ģenerēšanai.') . '</p>';
        } else {
            $html .= '<h4 class="admin-slideshow-order__title">Slideshow bilžu secība</h4>';
            $html .= '<p class="muted">Atzīmē favorītus, velc bildes, lai mainītu kārtību video ģenerēšanai. Saglabā pirms «Ģenerēt video».</p>';
        }
    }
    $html .= '<ul class="admin-fav-grid admin-slideshow-order-list" id="' . $esc($listId) . '">';
    foreach ($images as $img) {
        $tok = (string) ($img['token'] ?? '');
        if ($tok === '') {
            continue;
        }
        $tokens[] = $tok;
        $thumb = efpic_admin_media_thumb_url($config, $img);
        $preview = efpic_client_media_url($config, $img, 'web', 1200);
        $label = (string) ($img['basename'] ?? $img['filename'] ?? $tok);
        $isFav = $owner === 'admin' ? efpic_image_favorited_admin($img) : efpic_image_favorited_client($img);
        $html .= '<li class="admin-fav-item admin-slideshow-order-item" data-token="' . $esc($tok) . '" draggable="true">';
        $html .= '<label class="admin-fav-card' . ($isFav ? ' is-selected' : '') . '">';
        if (!$reorderOnly) {
            $html .= '<input type="checkbox" name="' . $esc($favField . '[' . $tok . ']') . '" value="1"'
                . ($isFav ? ' checked' : '') . '>';
        }
        $html .= '<span class="admin-slideshow-order-grip" aria-hidden="true" title="Vilkt">⋮⋮</span>';
        $html .= '<img src="' . $esc($thumb) . '" alt="" loading="lazy" decoding="async">';
        $html .= '<span class="admin-sort-name">' . $esc($label) . '</span>';
        $html .= '</label>';
        $html .= '<button type="button" class="admin-fav-preview" data-preview="' . $esc($preview) . '" aria-label="Priekšskatījums">⤢</button>';
        $html .= '</li>';
    }
    $html .= '</ul>';
    $html .= '<input type="hidden" name="' . $esc($prefix . '_image_order') . '" id="' . $esc($orderInputId) . '" value="'
        . $esc(implode(',', $tokens)) . '">';
    $html .= '</div>';

    return $html;
}

/** @deprecated Izmanto efpic_admin_render_slideshow_image_grid */
function efpic_admin_render_slideshow_image_order_list(array $config, array $meta, array $slot, string $owner = 'admin'): string
{
    return efpic_admin_render_slideshow_image_grid($config, $meta, $slot, $owner);
}

function efpic_admin_render_slideshow_audio_list(array $config, string $galleryToken, array $slot, string $owner = 'admin', string $itemId = ''): string
{
    $prefix = efpic_slideshow_form_prefix($owner, $itemId);
    $listId = $owner === 'client'
        ? 'portal-slideshow-audio-list'
        : ($itemId !== '' ? efpic_admin_slideshow_item_dom_id($itemId, 'audio-list') : 'admin-slideshow-audio-list');
    $orderInputId = $owner === 'client'
        ? 'slideshow-client-audio-order'
        : ($itemId !== '' ? efpic_admin_slideshow_item_dom_id($itemId, 'audio-order') : 'slideshow-admin-audio-order');
    $files = efpic_slideshow_slot_audio_files($slot);
    $esc = $owner === 'client' ? 'efpic_client_esc' : 'efpic_admin_esc';
    $html = '<div class="admin-slideshow-audio">';
    $html .= '<p class="muted">Līdz 8 dziesmām — video ģenerēšanā tās tiks savienotas secīgi. Velc, lai mainītu kārtību.</p>';
    if ($files !== []) {
        $html .= '<ul class="admin-slideshow-audio-list" id="' . $esc($listId) . '">';
        foreach ($files as $file) {
            $url = efpic_gallery_asset_url($config, $galleryToken, $file);
            $html .= '<li class="admin-slideshow-audio-item" data-audio-file="' . $esc($file) . '" draggable="true">';
            $html .= '<span class="admin-slideshow-order-grip" aria-hidden="true" title="Vilkt">⋮⋮</span>';
            $html .= '<div class="admin-slideshow-audio-item__main">';
            $html .= '<span class="admin-slideshow-audio-name" title="' . $esc($file) . '">' . $esc($file) . '</span>';
            $html .= '<a class="admin-slideshow-audio-play" href="' . $esc($url) . '" target="_blank" rel="noopener">Klausīties</a>';
            $html .= '</div>';
            $html .= '<label class="admin-check admin-slideshow-audio-remove">';
            $html .= '<input type="checkbox" name="' . $esc($prefix . '_remove_audio_file') . '[' . $esc($file) . ']" value="1"> Dzēst';
            $html .= '</label></li>';
        }
        $html .= '</ul>';
        $html .= '<input type="hidden" name="' . $esc($prefix . '_audio_order') . '" id="' . $esc($orderInputId) . '" value="'
            . $esc(implode(',', $files)) . '">';
        $html .= '<label class="admin-check admin-slideshow-audio-remove-all"><input type="checkbox" name="' . $esc($prefix . '_remove_audio') . '" value="1"> Dzēst visus MP3</label>';
    } else {
        $html .= '<p class="muted admin-slideshow-order-empty">Nav augšupielādēts neviens MP3.</p>';
        $html .= '<input type="hidden" name="' . $esc($prefix . '_audio_order') . '" id="' . $esc($orderInputId) . '" value="">';
    }
    $html .= '<label class="admin-slideshow-audio-upload">Augšupielādēt MP3<input type="file" name="' . $esc($prefix . '_mp3') . '[]" accept="audio/mpeg,.mp3" multiple></label>';
    $html .= '</div>';

    return $html;
}

function efpic_admin_client_slideshow_configured(array $clientSlot, int $clientFavCount): bool
{
    if (empty($clientSlot['enabled'])) {
        return false;
    }

    return efpic_slideshow_slot_video_ready($clientSlot)
        || efpic_slideshow_slot_audio_files($clientSlot) !== []
        || $clientFavCount > 0;
}

function efpic_admin_render_ready_slideshow_item(
    array $config,
    array $meta,
    string $galleryToken,
    string $slug,
    array $item,
    int $index,
    int $sortIndex = -1,
    int $sortCount = 0,
): string {
    $item = efpic_slideshow_slot_with_render($item);
    $itemId = (string) ($item['id'] ?? '');
    $prefix = efpic_slideshow_form_prefix('admin', $itemId);
    $title = trim((string) ($item['title'] ?? ''));
    if ($title === '') {
        $title = 'Slideshow ' . ($index + 1);
    }
    $videoReady = efpic_slideshow_slot_video_ready($item);
    $renderStatus = (string) ($item['render_status'] ?? 'none');
    $statusId = efpic_admin_slideshow_item_dom_id($itemId, 'render-status');

    $html = '<article class="admin-slideshow-ready" data-slideshow-id="' . efpic_admin_esc($itemId) . '">';
    $html .= '<header class="admin-slideshow-ready__head">';
    $html .= '<h4 class="admin-slideshow-ready__title">' . efpic_admin_esc($title) . '</h4>';
    $html .= '</header>';

    if (!$videoReady) {
        $html .= '<p class="muted" id="' . efpic_admin_esc($statusId) . '">Video statuss: <strong data-render-status="' . efpic_admin_esc($renderStatus) . '">'
            . efpic_admin_esc(efpic_render_status_label($renderStatus)) . '</strong></p>';
        if ($renderStatus === 'failed' && ($item['render_error'] ?? '') !== '') {
            $html .= '<p class="admin-warn">' . efpic_admin_esc((string) $item['render_error']) . '</p>';
        }
        $html .= '<div class="admin-slideshow-ready__actions">';
        $html .= '<button type="submit" class="btn admin-btn-danger" name="slideshow_delete_item" value="'
            . efpic_admin_esc($itemId) . '" onclick="return confirm(\'Dzēst šo slideshow?\');">DZĒST</button>';
        $html .= '</div>';
        $html .= '</article>';

        return $html;
    }

    $html .= '<input type="hidden" name="' . efpic_admin_esc($prefix . '_ready') . '" value="1">';
    $html .= efpic_admin_render_slideshow_video_preview($config, $galleryToken, $item);
    $html .= efpic_admin_render_slideshow_enabled_toggle($item, $prefix);
    $html .= '<p class="muted admin-slideshow-public-status">' . efpic_admin_esc(efpic_admin_slideshow_public_status_text($meta, $item)) . '</p>';

    $videoFile = (string) ($item['video_file'] ?? '');
    if ($videoFile !== '') {
        $videoUrl = efpic_gallery_asset_url($config, $galleryToken, $videoFile);
        $html .= '<p class="admin-ok">MP4: <a href="' . efpic_admin_esc($videoUrl) . '" target="_blank" rel="noopener">'
            . efpic_admin_esc($videoFile) . '</a></p>';
        if ($slug !== '') {
            $videoPath = efpic_gallery_assets_dir($config, $slug) . DIRECTORY_SEPARATOR . $videoFile;
            $sizeLabel = is_file($videoPath) ? efpic_format_bytes((int) filesize($videoPath)) : 'nav atrasts';
            $html .= '<p class="muted">Serverī: <code>storage/galleries/' . efpic_admin_esc($slug) . '/assets/'
                . efpic_admin_esc($videoFile) . '</code> (' . efpic_admin_esc($sizeLabel) . ')</p>';
        }
    }

    $html .= efpic_admin_slideshow_block_start('Vieta publiskajā galerijā');
    $html .= '<p class="muted">Kur un ar kādu nosaukumu slideshow video parādās apmeklētājiem.</p>';
    $html .= efpic_admin_render_slideshow_section_settings($meta, $item, 'admin', false, $itemId);
    $html .= efpic_admin_render_slideshow_order_controls('admin', $itemId, $sortIndex, $sortCount);
    $html .= efpic_admin_slideshow_block_end();

    $html .= '<div class="admin-slideshow-ready__actions">';
    if ($videoFile !== '') {
        $html .= '<button type="submit" class="btn admin-btn-danger" name="' . efpic_admin_esc($prefix . '_remove_video') . '" value="1"'
            . ' onclick="return confirm(\'Dzēst šo slideshow?\');">DZĒST</button>';
    }
    $html .= '</div>';
    $html .= '</article>';

    return $html;
}

function efpic_admin_render_ready_client_slideshow_item(
    array $config,
    array $meta,
    string $galleryToken,
    array $clientSlot,
    int $sortIndex = -1,
    int $sortCount = 0,
): string {
    $clientSlot = efpic_slideshow_slot_with_render($clientSlot);
    if (!efpic_slideshow_item_is_published($clientSlot)) {
        return '';
    }
    $prefix = 'slideshow_client';
    $videoReady = efpic_slideshow_slot_video_ready($clientSlot);
    $renderStatus = (string) ($clientSlot['render_status'] ?? 'none');
    $title = trim((string) ($clientSlot['section_title'] ?? ''));
    if ($title === '') {
        $title = 'Klienta slideshow';
    }

    $html = '<article class="admin-slideshow-ready admin-slideshow-ready--client" data-slideshow-id="client">';
    $html .= '<header class="admin-slideshow-ready__head">';
    $html .= '<h4 class="admin-slideshow-ready__title">' . efpic_admin_esc($title) . ' <span class="admin-slideshow-ready__badge">Klients</span></h4>';
    $html .= '</header>';

    if (!$videoReady) {
        $html .= '<p class="muted" id="slideshow-item-client-render-status">Video statuss: <strong data-render-status="' . efpic_admin_esc($renderStatus) . '">'
            . efpic_admin_esc(efpic_render_status_label($renderStatus)) . '</strong></p>';
        if ($renderStatus === 'failed' && ($clientSlot['render_error'] ?? '') !== '') {
            $html .= '<p class="admin-warn">' . efpic_admin_esc((string) $clientSlot['render_error']) . '</p>';
        }
        $html .= '</article>';

        return $html;
    }

    $html .= '<input type="hidden" name="' . efpic_admin_esc($prefix . '_ready') . '" value="1">';
    $html .= efpic_admin_render_slideshow_video_preview($config, $galleryToken, $clientSlot);
    $html .= efpic_admin_render_slideshow_enabled_toggle($clientSlot, $prefix);
    $html .= '<p class="muted admin-slideshow-public-status">' . efpic_admin_esc(efpic_admin_slideshow_public_status_text($meta, $clientSlot)) . '</p>';
    $html .= efpic_admin_slideshow_block_start('Vieta publiskajā galerijā');
    $html .= '<p class="muted">Klients konfigurē slideshow savā panelī. Šeit vari ieslēgt/izslēgt un mainīt vietu publiskajā galerijā.</p>';
    $html .= efpic_admin_render_slideshow_section_settings($meta, $clientSlot, 'client', false);
    $html .= efpic_admin_render_slideshow_order_controls('client', 'client', $sortIndex, $sortCount);
    $html .= efpic_admin_slideshow_block_end();
    $html .= '</article>';

    return $html;
}

function efpic_admin_render_slideshow_composer(
    array $config,
    array $meta,
    string $galleryToken,
    array $draft,
): string {
    $draft = efpic_slideshow_slot_with_render($draft);
    $prefix = efpic_slideshow_form_prefix('admin', 'draft');

    $html = '<section class="admin-media-section admin-slideshow-composer">';
    $html .= '<h3 class="admin-media-section__title">1. Jauna slideshow izveide</h3>';
    $html .= '<p class="muted admin-slideshow-composer__intro">Sagatavo jaunu slideshow video. Pēc ģenerēšanas tas parādīsies «Gatavie slideshow» sarakstā.</p>';

    $html .= efpic_admin_render_slideshow_image_source_options($config, $meta, $draft, $prefix, 'draft');

    $html .= efpic_admin_slideshow_block_start('Audio (MP3)');
    $html .= efpic_admin_render_slideshow_audio_list($config, $galleryToken, $draft, 'admin', 'draft');
    $html .= efpic_admin_slideshow_block_end();

    $html .= efpic_admin_slideshow_block_start('Video parametri');
    $html .= '<div class="admin-slideshow-params">';
    $html .= '<label>Intro virsraksts<input type="text" name="' . efpic_admin_esc($prefix . '_intro_title') . '" maxlength="120" value="'
        . efpic_admin_esc($draft['intro_title']) . '" placeholder="piem. Jānis + Ieva"></label>';
    $html .= '<p class="muted admin-fieldset-full">Intro video: lielie burti, treknraksts. «+» starp vārdiem — jauna rinda.</p>';
    $html .= '<label>Intervāls (sek.)<input type="number" name="' . efpic_admin_esc($prefix . '_interval') . '" min="2" max="60" value="'
        . (int) $draft['interval_sec'] . '"></label>';
    $html .= '<label>Fona krāsa<select name="' . efpic_admin_esc($prefix . '_bg_mode') . '">';
    $html .= '<option value="white"' . ($draft['bg_mode'] === 'white' ? ' selected' : '') . '>Balts</option>';
    $html .= '<option value="gallery"' . ($draft['bg_mode'] === 'gallery' ? ' selected' : '') . '>Galerijas fons</option>';
    $html .= '</select></label>';
    $html .= '</div>';
    $html .= efpic_admin_slideshow_block_end();

    $html .= '<div class="admin-media-action-row admin-slideshow-composer__actions">';
    $html .= '<button type="submit" class="btn primary" name="save" value="1">Saglabāt melnrakstu</button>';
    $html .= '<button type="submit" class="btn" name="slideshow_draft_generate_video" value="1"'
        . ' onclick="return confirm(\'Ģenerēt jaunu slideshow video? Tas tiks pievienots gatavo slideshow sarakstam.\');">Ģenerēt video</button>';
    $html .= '</div>';
    $html .= '</section>';

    return $html;
}

function efpic_admin_render_favorites_tab_grid(array $config, array $meta): string
{
    $draft = efpic_gallery_slideshow_storage($meta)['draft'];
    $favOrder = is_array($draft['image_order_tokens'] ?? null) ? $draft['image_order_tokens'] : [];
    $favSlot = ['image_source' => 'favorites', 'image_order_tokens' => $favOrder];

    return efpic_admin_render_slideshow_image_grid(
        $config,
        $meta,
        $favSlot,
        'admin',
        null,
        'favorites',
        false,
        true,
    );
}

function efpic_admin_render_composer_favorites_panel(array $config, array $meta, array $draft): string
{
    $favSlot = $draft;
    $favSlot['image_source'] = 'favorites';
    $html = '<p class="muted">Favorītus atzīmē cilnē «Favorītbildes» vai Bildes cilnē. Šeit vari mainīt bilžu secību slideshow video.</p>';

    return $html . efpic_admin_render_slideshow_image_grid($config, $meta, $favSlot, 'admin', null, 'draft', true, true);
}

/** @return array{favorites_tab_grid_html: string, composer_favorites_panel_html: string} */
function efpic_admin_favorites_slideshow_panels_payload(array $config, array $meta): array
{
    $draft = efpic_gallery_slideshow_storage($meta)['draft'];

    return [
        'favorites_tab_grid_html' => efpic_admin_render_favorites_tab_grid($config, $meta),
        'composer_favorites_panel_html' => efpic_admin_render_composer_favorites_panel($config, $meta, $draft),
    ];
}

/** Diagnostika: kas tieši ir saglabāts meta.json slideshow.items[]. */
function efpic_admin_slideshow_meta_diagnostic(array $meta): array
{
    $raw = $meta['slideshow'] ?? [];
    if (!is_array($raw)) {
        return ['has_legacy_admin' => false, 'items' => [], 'payload_received' => false];
    }
    $items = [];
    foreach ($raw['items'] ?? [] as $itemRaw) {
        if (!is_array($itemRaw)) {
            continue;
        }
        $items[] = [
            'id' => (string) ($itemRaw['id'] ?? ''),
            'enabled' => array_key_exists('enabled', $itemRaw) ? !empty($itemRaw['enabled']) : null,
            'has_enabled_key' => array_key_exists('enabled', $itemRaw),
            'video_file' => (string) ($itemRaw['video_file'] ?? ''),
            'render_status' => (string) ($itemRaw['render_status'] ?? ''),
        ];
    }

    return [
        'has_legacy_admin' => isset($raw['admin']),
        'items' => $items,
        'payload_received' => trim((string) ($_POST['ready_slideshow_payload'] ?? '')) !== '',
    ];
}

/** @return list<array{id: string, enabled: bool, public_status: string}> */
function efpic_admin_ready_slideshow_autosave_state(array $meta): array
{
    $out = [];
    $storage = efpic_gallery_slideshow_storage($meta);
    foreach ($storage['items'] as $item) {
        $item = efpic_slideshow_slot_with_render($item);
        if (!efpic_slideshow_slot_video_ready($item)) {
            continue;
        }
        $id = (string) ($item['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $out[] = [
            'id' => $id,
            'enabled' => !empty($item['enabled']),
            'public_status' => efpic_admin_slideshow_public_status_text($meta, $item),
        ];
    }
    $client = efpic_slideshow_slot_with_render($storage['client']);
    if (efpic_slideshow_slot_video_ready($client)) {
        $out[] = [
            'id' => 'client',
            'enabled' => !empty($client['enabled']),
            'public_status' => efpic_admin_slideshow_public_status_text($meta, $client),
        ];
    }

    return $out;
}

function efpic_admin_render_favorites_fieldset(array $config, array $meta): string
{
    $clientFavCount = efpic_count_favorites($meta, 'client');

    $html = '<fieldset class="admin-fieldset-full"><legend>Favorītbildes</legend>';

    $html .= '<section class="admin-favorites-block">';
    $html .= '<h3 class="admin-favorites-block__title">Manas favorītbildes</h3>';
    $html .= '<input type="hidden" name="favorites_dirty" id="favorites_dirty" value="0">';
    $html .= '<div id="admin-favorites-slideshow-grid">';
    $html .= efpic_admin_render_favorites_tab_grid($config, $meta);
    $html .= '</div>';
    $html .= '</section>';

    $html .= '<section class="admin-favorites-block">';
    $html .= '<h3 class="admin-favorites-block__title">Klienta favorītbildes</h3>';
    $html .= '<p class="muted">' . $clientFavCount . ' bildes — klients atzīmē savā panelī. Tikai skatīšanai.</p>';
    $html .= efpic_admin_render_favorite_thumb_grid($config, $meta, 'client', false, true);
    $html .= '</section>';

    $html .= '</fieldset>';

    return $html;
}

function efpic_admin_render_media_tab(array $config, array $meta, string $galleryToken, string $slug = ''): string
{
    $storage = efpic_gallery_slideshow_storage($meta);
    $adminItems = $storage['items'];
    $draft = $storage['draft'];
    $clientSlot = efpic_slideshow_slot_with_render($storage['client']);
    $sortEntries = efpic_admin_slideshow_ready_sort_entries($meta);
    $sortCount = count($sortEntries);
    $sortIndexByKey = [];
    foreach ($sortEntries as $i => $entry) {
        $sortIndexByKey[$entry['key']] = $i;
    }
    $clientSortIndex = $sortIndexByKey['client'] ?? -1;
    $clientReadyHtml = efpic_admin_render_ready_client_slideshow_item(
        $config,
        $meta,
        $galleryToken,
        $clientSlot,
        $clientSortIndex,
        $sortCount,
    );
    $hasReady = $adminItems !== [] || $clientReadyHtml !== '';

    $html = '';

    $html .= efpic_admin_render_slideshow_composer($config, $meta, $galleryToken, $draft);

    $html .= '<section class="admin-media-section admin-slideshow-ready-panel">';
    $html .= '<h3 class="admin-media-section__title">2. Gatavie slideshow</h3>';
    $html .= '<p class="muted">Admin un klienta slideshow video. Ieslēdz/izslēdz un ar pogām <strong>Augšāk</strong> / <strong>Zemāk</strong> nosaki secību publiskajā galerijā.</p>';
    if (!$hasReady) {
        $html .= '<p class="muted admin-slideshow-ready-panel__empty">Vēl nav neviena slideshow video.</p>';
    } else {
        $html .= '<div class="admin-slideshow-ready-list">';
        $html .= $clientReadyHtml;
        foreach ($adminItems as $i => $item) {
            $itemId = (string) ($item['id'] ?? '');
            $itemSortIndex = $sortIndexByKey[$itemId] ?? -1;
            $html .= efpic_admin_render_ready_slideshow_item(
                $config,
                $meta,
                $galleryToken,
                $slug,
                $item,
                $i,
                $itemSortIndex,
                $sortCount,
            );
        }
        $html .= '</div>';
        $html .= '<p class="muted admin-slideshow-ready-panel__autosave">Izmaiņas saglabājas automātiski.</p>';
    }
    $html .= '</section>';

    $html .= efpic_admin_render_video_add_section($config, $meta);
    $html .= efpic_admin_render_video_list_section($config, $meta, $galleryToken);

    return $html;
}

/** @deprecated Izmanto efpic_admin_render_media_tab */
function efpic_admin_render_favorites_and_slideshow(array $config, array $meta, string $galleryToken, string $slug = ''): string
{
    return efpic_admin_render_media_tab($config, $meta, $galleryToken, $slug);
}

function efpic_admin_render_client_media_panel(array $config, array $meta, string $galleryToken): string
{
    $slots = efpic_gallery_slideshows_struct($meta);
    $clientSlot = efpic_slideshow_slot_with_render($slots['client']);
    $clientFavCount = efpic_count_favorites($meta, 'client');
    $clientActive = efpic_slideshow_slot_public_ready($clientSlot, $clientFavCount);

    return efpic_admin_render_client_panel_section($config, $meta, $galleryToken, $clientSlot, $clientFavCount, $clientActive);
}

function efpic_admin_render_video_preview(array $config, string $galleryToken, array $video): string
{
    $kind = (string) ($video['kind'] ?? 'file');
    if ($kind === 'embed') {
        $provider = (string) ($video['provider'] ?? '');
        $embedId = (string) ($video['embed_id'] ?? '');
        if ($embedId === '') {
            return '';
        }
        $src = $provider === 'vimeo'
            ? 'https://player.vimeo.com/video/' . rawurlencode($embedId)
            : 'https://www.youtube-nocookie.com/embed/' . rawurlencode($embedId);

        return '<div class="admin-video-preview admin-video-preview--embed"><iframe src="' . efpic_admin_esc($src)
            . '" allowfullscreen loading="lazy" referrerpolicy="strict-origin-when-cross-origin" title="Video priekšskatījums"></iframe></div>';
    }

    $file = (string) ($video['file'] ?? '');
    if ($file === '') {
        return '';
    }
    $url = efpic_gallery_asset_url($config, $galleryToken, $file);

    return '<div class="admin-video-preview admin-video-preview--file"><video controls playsinline preload="metadata" src="'
        . efpic_admin_esc($url) . '"></video></div>';
}

function efpic_admin_render_slideshow_video_preview(array $config, string $galleryToken, array $slot): string
{
    if (!efpic_slideshow_slot_video_ready($slot)) {
        return '';
    }
    $videoFile = trim((string) ($slot['video_file'] ?? ''));
    if ($videoFile === '') {
        return '';
    }

    $preview = efpic_admin_render_video_preview($config, $galleryToken, ['kind' => 'file', 'file' => $videoFile]);
    if ($preview === '') {
        return '';
    }

    return '<div class="admin-slideshow-video-preview">' . $preview . '</div>';
}

function efpic_admin_render_client_panel_section(
    array $config,
    array $meta,
    string $galleryToken,
    array $clientSlot,
    int $clientFavCount,
    bool $clientActive,
): string {
    $clientAudioFiles = efpic_slideshow_slot_audio_files($clientSlot);

    $html = '<fieldset class="admin-fieldset-full admin-client-panel">';
    $html .= '<legend>Klients</legend>';
    $html .= '<p class="muted admin-client-panel__intro">Ko klients izvēlējies un konfigurējis klienta panelī. Tikai skatīšanai.</p>';

    $html .= '<div class="admin-client-panel__block">';
    $html .= '<h4 class="admin-client-panel__title">Favorīti</h4>';
    $html .= '<p class="muted">' . $clientFavCount . ' bildes — netiek rādītas tavā slideshow bilžu secībā.</p>';
    $html .= efpic_admin_render_favorite_thumb_grid($config, $meta, 'client', false, false);
    $html .= '</div>';

    $html .= '<div class="admin-client-panel__block">';
    $html .= '<h4 class="admin-client-panel__title">Slideshow</h4>';
    $html .= '<p class="muted">Konfigurē klienta panelī. Rāda publiski, ja ir ieslēgta un ir MP3.</p>';
    $html .= efpic_admin_render_slideshow_video_preview($config, $galleryToken, $clientSlot);
    $html .= '<ul class="admin-status-list">';
    $html .= '<li>Ieslēgta: <strong>' . ($clientSlot['enabled'] ? 'Jā' : 'Nē') . '</strong></li>';
    $html .= '<li>MP3: <strong>' . ($clientAudioFiles !== [] ? 'Jā (' . count($clientAudioFiles) . ')' : 'Nē') . '</strong></li>';
    $html .= '<li>Publiski aktīva: <strong>' . ($clientActive ? 'Jā (galvenā)' : 'Nē') . '</strong></li>';
    $html .= '<li>MP4 gatavs: <strong>' . (efpic_slideshow_slot_video_ready($clientSlot) ? 'Jā' : 'Nē') . '</strong></li>';
    $html .= '</ul>';
    if ($clientAudioFiles !== []) {
        foreach ($clientAudioFiles as $clientAudio) {
            $html .= '<p><a href="' . efpic_admin_esc(efpic_gallery_asset_url($config, $galleryToken, $clientAudio))
                . '" target="_blank" rel="noopener">Klausīties: ' . efpic_admin_esc($clientAudio) . '</a></p>';
        }
    }
    $html .= '</div></fieldset>';

    return $html;
}

function efpic_admin_render_existing_videos_list(array $config, array $meta, string $galleryToken, bool $readOnly = false): string
{
    $scenes = efpic_gallery_scene_options($meta);
    $sceneTitles = [];
    foreach ($scenes as $scene) {
        $sceneTitles[$scene['id']] = $scene['title'];
    }
    $html = '';
    foreach ($meta['videos'] ?? [] as $video) {
        if (!is_array($video)) {
            continue;
        }
        $vid = (string) ($video['id'] ?? '');
        if ($vid === '') {
            continue;
        }
        $title = trim((string) ($video['title'] ?? ''));
        $kind = (string) ($video['kind'] ?? 'file');
        $sceneId = (string) ($video['scene_id'] ?? 'main');
        $providerLabel = $kind === 'embed' ? strtoupper((string) ($video['provider'] ?? 'video')) : 'MP4';
        $sceneTitle = (string) ($sceneTitles[$sceneId] ?? $sceneId);
        $html .= '<div class="admin-video-card' . ($readOnly ? ' admin-video-card--readonly' : '') . '" data-video-id="' . efpic_admin_esc($vid) . '">';
        $html .= efpic_admin_render_video_preview($config, $galleryToken, $video);
        $html .= '<div class="admin-video-card__meta">';
        if ($readOnly) {
            if ($title !== '') {
                $html .= '<p class="admin-video-readonly-title"><strong>' . efpic_admin_esc($title) . '</strong></p>';
            }
            $html .= '<p class="muted admin-video-readonly-scene">' . efpic_admin_esc($sceneTitle) . '</p>';
            $html .= '<span class="muted admin-video-kind">' . efpic_admin_esc($providerLabel) . '</span>';
        } else {
            $html .= '<label>Virsraksts<input type="text" name="video_title[' . efpic_admin_esc($vid) . ']" value="'
                . efpic_admin_esc($title) . '" placeholder="Video virsraksts"></label>';
            $html .= '<label>Sadaļa<select name="video_scene[' . efpic_admin_esc($vid) . ']" class="admin-video-scene-select">';
            foreach ($scenes as $scene) {
                $sel = $scene['id'] === $sceneId ? ' selected' : '';
                $html .= '<option value="' . efpic_admin_esc($scene['id']) . '"' . $sel . '>' . efpic_admin_esc($scene['title']) . '</option>';
            }
            $html .= '</select></label>';
            $html .= '<span class="muted admin-video-kind">' . efpic_admin_esc($providerLabel) . '</span>';
            $html .= '<button type="button" class="btn primary admin-video-delete" data-video-id="' . efpic_admin_esc($vid) . '">Dzēst</button>';
            $html .= '<input type="hidden" name="delete_video[' . efpic_admin_esc($vid) . ']" value="0" class="admin-video-delete-flag">';
        }
        $html .= '</div></div>';
    }

    if ($html === '') {
        return '<p class="muted admin-videos-empty">Vēl nav pievienotu video.</p>';
    }

    return $html;
}

function efpic_admin_render_video_add_section(array $config, array $meta): string
{
    $scenes = efpic_gallery_scene_options($meta);
    $html = '<section class="admin-media-section admin-video-add-section" id="admin-video-add-panel">';
    $html .= '<h3 class="admin-media-section__title">3. Pievienot jaunu video</h3>';
    $html .= '<p class="muted">Video tiek rādīti publiskajā galerijā <strong>pirms</strong> izvēlētās sadaļas bildēm.</p>';
    $html .= '<h4 class="admin-video-add-title">Video fails (MP4)</h4>';
    $html .= '<div class="admin-form-split">';
    $html .= '<label>Video fails<input type="file" name="gallery_video" accept="video/mp4,video/quicktime,video/webm,.mp4,.mov,.webm"></label>';
    $html .= '<label>Virsraksts<input name="video_upload_title" placeholder="piem. Laulību ceremonija"></label>';
    $html .= '<label>Sadaļa<select name="video_upload_scene" class="admin-video-scene-select">';
    foreach ($scenes as $scene) {
        $html .= '<option value="' . efpic_admin_esc($scene['id']) . '">' . efpic_admin_esc($scene['title']) . '</option>';
    }
    $html .= '</select></label></div>';
    $html .= '<h4 class="admin-video-add-title">YouTube / Vimeo</h4>';
    $html .= '<div class="admin-form-split admin-video-embed-add">';
    $html .= '<label>Saite<input name="video_embed_url" placeholder="https://youtube.com/watch?v=..."></label>';
    $html .= '<label>Virsraksts<input name="video_embed_title" placeholder="Ievietots video"></label>';
    $html .= '<label>Sadaļa<select name="video_embed_scene" class="admin-video-scene-select">';
    foreach ($scenes as $scene) {
        $html .= '<option value="' . efpic_admin_esc($scene['id']) . '">' . efpic_admin_esc($scene['title']) . '</option>';
    }
    $html .= '</select></label></div>';
    $html .= '<div class="admin-video-submit-row">';
    $html .= '<button type="button" class="btn primary admin-btn-inline" id="admin-add-embed-video">Pievienot video</button>';
    $html .= '</div></section>';

    return $html;
}

function efpic_admin_render_video_list_section(array $config, array $meta, string $galleryToken): string
{
    $html = '<section class="admin-media-section admin-video-list-section" id="admin-videos-panel">';
    $html .= '<h3 class="admin-media-section__title">4. Video</h3>';
    $html .= '<p class="muted">Visi pievienotie video — gan tavi, gan klienta (ja klients pievienojis klienta panelī).</p>';
    $html .= '<div id="admin-videos-list" class="admin-videos-list">';
    $html .= efpic_admin_render_existing_videos_list($config, $meta, $galleryToken);
    $html .= '</div></section>';

    return $html;
}

/** @deprecated Izmanto efpic_admin_render_video_add_section + efpic_admin_render_video_list_section */
function efpic_admin_render_videos_fieldset(array $config, array $meta, string $galleryToken): string
{
    return efpic_admin_render_video_add_section($config, $meta)
        . efpic_admin_render_video_list_section($config, $meta, $galleryToken);
}

function efpic_admin_handle_logout(): void
{
    if (isset($_GET['logout'])) {
        efpic_admin_session_start();
        unset($_SESSION['efpic_admin']);
        header('Location: index.php');
        exit;
    }
}

function efpic_admin_require_delete_confirm(): void
{
    if (trim((string) ($_POST['confirm_delete'] ?? '')) !== 'DELETE') {
        throw new InvalidArgumentException('Lai apstiprinātu, ievadiet DELETE.');
    }
}

/** @return list<string> */
function efpic_admin_post_gallery_slugs(): array
{
    $slugs = $_POST['gallery_slugs'] ?? [];
    if (!is_array($slugs)) {
        return [];
    }
    $out = [];
    foreach ($slugs as $slug) {
        $slug = trim((string) $slug);
        if ($slug !== '' && preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $slug) === 1) {
            $out[] = $slug;
        }
    }

    return array_values(array_unique($out));
}

function efpic_admin_handle_gallery_list_actions(array $config): ?string
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['gallery_action'])) {
        return null;
    }
    $action = (string) $_POST['gallery_action'];
    $slugs = efpic_admin_post_gallery_slugs();
    if ($action === 'select_all_active' || $action === 'select_all_deleted') {
        return null;
    }
    if ($slugs === []) {
        throw new InvalidArgumentException('Nav izvēlēta neviena galerija.');
    }

    if (in_array($action, ['soft_delete', 'purge'], true)) {
        efpic_admin_require_delete_confirm();
    }

    $count = 0;
    foreach ($slugs as $slug) {
        $meta = efpic_load_gallery_meta($config, $slug);
        if ($meta === null || !efpic_is_delivery_gallery($meta)) {
            continue;
        }
        match ($action) {
            'soft_delete' => (function () use ($config, $meta, $slug, &$count) {
                if (efpic_gallery_is_active($meta)) {
                    efpic_soft_delete_gallery($config, $slug);
                    $count++;
                }
            })(),
            'restore' => (function () use ($config, $slug, &$count) {
                $m = efpic_load_gallery_meta($config, $slug);
                if ($m !== null && efpic_gallery_status($m) === 'deleted') {
                    efpic_restore_gallery($config, $slug);
                    $count++;
                }
            })(),
            'purge' => (function () use ($config, $slug, &$count) {
                efpic_purge_gallery($config, $slug);
                $count++;
            })(),
            default => throw new InvalidArgumentException('Nezināma darbība'),
        };
    }

    return match ($action) {
        'soft_delete' => $count . ' galerija(s) pārvietota uz dzēstajām.',
        'restore' => $count . ' galerija(s) atjaunota.',
        'purge' => $count . ' galerija(s) neatgriezeniski izdzēsta.',
        default => null,
    };
}

/**
 * @return list<array{slug: string, meta: array}>
 */
function efpic_admin_collect_delivery_galleries(array $config, string $statusFilter): array
{
    $items = [];
    foreach (efpic_list_gallery_slugs($config) as $slug) {
        $meta = efpic_load_gallery_meta($config, $slug);
        if ($meta === null || !efpic_is_delivery_gallery($meta)) {
            continue;
        }
        $status = efpic_gallery_status($meta);
        if ($statusFilter === 'active' && $status !== 'active') {
            continue;
        }
        if ($statusFilter === 'deleted' && $status !== 'deleted') {
            continue;
        }
        $items[] = ['slug' => $slug, 'meta' => $meta];
    }

    return $items;
}

function efpic_admin_list_delivery_galleries(array $config): void
{
    $view = ($_GET['view'] ?? 'active') === 'deleted' ? 'deleted' : 'active';
    $allowedSorts = ['date', 'name', 'panel', 'images', 'video', 'sync', 'expires'];
    $sort = (string) ($_GET['sort'] ?? 'date');
    if (!in_array($sort, $allowedSorts, true)) {
        $sort = 'date';
    }
    $order = strtolower((string) ($_GET['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

    $flash = isset($_GET['ok']) ? (string) $_GET['ok'] : null;
    $flashIsError = false;
    if (isset($_GET['error']) && trim((string) $_GET['error']) !== '') {
        $flash = (string) $_GET['error'];
        $flashIsError = true;
    }

    $items = efpic_admin_collect_delivery_galleries($config, $view);
    usort($items, static function ($a, $b) use ($sort, $order) {
        $cmp = efpic_admin_gallery_list_compare_items($a, $b, $sort);

        return $order === 'asc' ? $cmp : -$cmp;
    });

    $baseQs = static function (array $extra) use ($view, $sort, $order) {
        return 'index.php?' . http_build_query(array_merge([
            'view' => $view,
            'sort' => $sort,
            'order' => $order,
        ], $extra));
    };

    $body = '';
    if ($flash !== null && $flash !== '') {
        $cls = $flashIsError ? 'err' : 'admin-flash';
        $body .= '<p class="' . $cls . '">' . efpic_admin_esc($flash) . '</p>';
    }

    $body .= '<div class="admin-list-toolbar">';
    $listTitle = $view === 'deleted' ? 'Dzēstās galerijas' : 'Aktīvās galerijas';
    $body .= '<h2 class="admin-list-title">' . efpic_admin_esc($listTitle) . '</h2>';
    $body .= '</div>';

    $body .= '<form method="post" class="admin-gallery-bulk-form" id="admin-gallery-bulk-form">';
    $body .= '<input type="hidden" name="list_view" value="' . efpic_admin_esc($view) . '">';
    $body .= '<input type="hidden" name="list_sort" value="' . efpic_admin_esc($sort) . '">';
    $body .= '<input type="hidden" name="list_order" value="' . efpic_admin_esc($order) . '">';
    $body .= '<input type="hidden" name="confirm_delete" id="confirm_delete" value="">';
    $body .= '<div class="admin-sticky-bar admin-sticky-bar--list">';
    if ($view === 'active') {
        $body .= '<button type="submit" class="btn" name="gallery_action" value="soft_delete" data-confirm-delete="1">Dzēst izvēlētās</button>';
    } else {
        $body .= '<button type="submit" class="btn primary" name="gallery_action" value="restore">Atjaunot izvēlētās</button>';
        $body .= '<button type="submit" class="btn" name="gallery_action" value="purge" data-confirm-delete="1">Izdzēst neatgriezeniski</button>';
    }
    $body .= '<label class="admin-check"><input type="checkbox" id="admin-gallery-select-all"> Atlasīt visas</label>';
    $body .= '</div>';

    $rows = '';
    foreach ($items as $item) {
        $slug = $item['slug'];
        $meta = $item['meta'];
        $gt = (string) ($meta['gallery_token'] ?? '');
        $stats = $meta['failiem']['sync_stats'] ?? null;
        $paired = is_array($stats) ? (int) ($stats['paired'] ?? 0) : 0;
        $videoCount = efpic_admin_gallery_video_count($meta);
        $syncAt = efpic_admin_format_sync_datetime_lv((string) ($meta['failiem']['last_sync_at'] ?? ''));
        $date = substr((string) ($meta['event_date'] ?? ''), 0, 10);
        $expiresShort = efpic_gallery_expires_at_value($meta) ?? '';
        if ($expiresShort === '') {
            $expiresCell = '<span class="muted">bez termiņa</span>';
        } elseif (efpic_gallery_expired($meta)) {
            $expiresCell = '<span class="err">līdz ' . efpic_admin_esc($expiresShort) . '</span>';
        } else {
            $expiresCell = '<span class="muted">līdz ' . efpic_admin_esc($expiresShort) . '</span>';
        }
        $dateCell = efpic_admin_esc($date !== '' ? $date : '—');
        $panelOn = efpic_client_portal_enabled($meta);
        if ($panelOn) {
            $panelCell = '<span class="admin-gallery-list__panel-yes">Jā</span>';
        } else {
            $panelCell = '<span class="muted">Nē</span>';
        }
        $imageCell = count($meta['images'] ?? []) . ' / ' . $paired;
        if ($videoCount > 0) {
            $videoCell = '<span class="admin-gallery-list__video-yes">' . $videoCount . '</span>';
        } else {
            $videoCell = '<span class="muted">—</span>';
        }
        $viewCell = $view === 'active' && $gt !== ''
            ? '<a href="' . efpic_admin_esc(efpic_gallery_view_url($config, $gt)) . '" target="_blank" rel="noopener">Skatīt</a>'
            : '<span class="muted">Nav publiska</span>';

        $rows .= '<tr class="admin-gallery-list__row">';
        $rows .= '<td class="admin-gallery-list__pick" data-label=""><input type="checkbox" name="gallery_slugs[]" value="'
            . efpic_admin_esc($slug) . '" class="admin-gallery-pick"></td>';
        $rows .= '<td class="admin-gallery-list__date" data-label="Datums">' . $dateCell . '</td>';
        $rows .= '<td class="admin-gallery-list__name" data-label="Nosaukums"><a href="delivery_edit.php?slug='
            . rawurlencode($slug) . '">' . efpic_admin_esc($meta['name'] ?? $slug) . '</a></td>';
        $rows .= '<td class="admin-gallery-list__panel" data-label="K Panelis">' . $panelCell . '</td>';
        $rows .= '<td class="admin-gallery-list__images" data-label="Bildes">' . efpic_admin_esc($imageCell) . '</td>';
        $rows .= '<td class="admin-gallery-list__videos" data-label="Video">' . $videoCell . '</td>';
        $rows .= '<td class="admin-gallery-list__sync muted" data-label="Sync">' . efpic_admin_esc($syncAt) . '</td>';
        $rows .= '<td class="admin-gallery-list__expires" data-label="Termiņš">' . $expiresCell . '</td>';
        $rows .= '<td class="admin-gallery-list__view" data-label="">' . $viewCell . '</td>';
        $rows .= '</tr>';
    }

    if ($rows === '') {
        $empty = $view === 'deleted'
            ? 'Nav dzēstu galeriju.'
            : 'Vēl nav galeriju. <a href="delivery_new.php">Izveidot jaunu</a>';
        $rows = '<tr><td colspan="9" class="muted">' . $empty . '</td></tr>';
    }

    $body .= '<div class="admin-gallery-list-scroll">';
    $body .= '<div class="admin-table-wrap admin-gallery-list">';
    $body .= '<table class="admin-table admin-gallery-list-table"><thead><tr>';
    $body .= '<th class="admin-gallery-list__pick"></th>';
    $body .= efpic_admin_gallery_list_sort_th('date', 'Datums', $sort, $order, $baseQs);
    $body .= efpic_admin_gallery_list_sort_th('name', 'Nosaukums', $sort, $order, $baseQs);
    $body .= efpic_admin_gallery_list_sort_th('panel', 'K Panelis', $sort, $order, $baseQs);
    $body .= efpic_admin_gallery_list_sort_th('images', 'Bildes', $sort, $order, $baseQs);
    $body .= efpic_admin_gallery_list_sort_th('video', 'Video', $sort, $order, $baseQs);
    $body .= efpic_admin_gallery_list_sort_th('sync', 'Sync', $sort, $order, $baseQs);
    $body .= efpic_admin_gallery_list_sort_th('expires', 'Termiņš', $sort, $order, $baseQs);
    $body .= '<th class="admin-gallery-list__view-head"></th>';
    $body .= '</tr></thead><tbody>' . $rows . '</tbody></table></div></div></form>';

    efpic_admin_layout(
        'Galerijas',
        $body,
        $view === 'deleted' ? 'deleted' : 'list',
        $view === 'deleted' ? 'Dzēstās galerijas' : 'Galerijas',
        $view === 'deleted'
            ? 'Galerijas šeit nav publiski pieejamas. Var atjaunot vai izdzēst neatgriezeniski (DELETE).'
            : 'Bildes glabājas Failiem.lv. Dzēšot, ievadi DELETE — galerija pāriet uz dzēstajām.',
        $config
    );
}

function efpic_admin_save_delivery_from_post(array $config, ?string $slug): string
{
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Nosaukums obligāts');
    }
    $eventDate = trim((string) ($_POST['event_date'] ?? ''));
    if ($eventDate === '') {
        throw new InvalidArgumentException('Datums obligāts');
    }

    $isNew = $slug === null || $slug === '';
    if ($isNew) {
        $created = efpic_create_delivery_gallery($config, [
            'name' => $name,
            'slug' => trim((string) ($_POST['slug'] ?? '')),
            'event_date' => $eventDate,
            'password' => efpic_post_flag_is_on('gallery_password_enabled')
                ? (string) ($_POST['gallery_password'] ?? '')
                : '',
            'client_email' => trim((string) ($_POST['client_email'] ?? '')),
            'client_phone' => trim((string) ($_POST['client_phone'] ?? '')),
            'client_password' => efpic_post_flag_is_on('client_password_enabled')
                ? (string) ($_POST['client_password'] ?? '')
                : '',
            'client_portal_enabled' => efpic_post_flag_is_on('client_portal_enabled'),
            'folder_parent_url' => trim((string) ($_POST['folder_parent_url'] ?? '')),
            'folder_full_url' => trim((string) ($_POST['folder_full_url'] ?? '')),
            'folder_web_url' => trim((string) ($_POST['folder_web_url'] ?? '')),
            'folder_video_url' => trim((string) ($_POST['folder_video_url'] ?? '')),
            'theme' => efpic_normalize_gallery_theme((string) ($_POST['theme'] ?? 'efpic-modern')),
        ]);
        $slug = $created['slug'];
        $meta = $created['meta'];
        if (trim((string) ($_POST['expires_at'] ?? '')) !== '') {
            efpic_gallery_apply_expires_from_post($meta);
        }
        $meta['scenes'] = efpic_parse_scenes_from_post();
        $accent = trim((string) ($_POST['hero_accent_color'] ?? ''));
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $accent) === 1) {
            $meta['hero_accent_color'] = strtolower($accent);
        }
        $pageBg = trim((string) ($_POST['page_bg_color'] ?? ''));
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $pageBg) === 1) {
            $meta['page_bg_color'] = strtolower($pageBg);
        }
        efpic_apply_cover_theme_from_post($meta);
        efpic_apply_cover_media_from_post($meta);
        efpic_apply_mood_theme_from_post($meta);
        efpic_save_gallery_meta($config, $slug, $meta);
        efpic_gallery_log_activity(
            $config,
            $slug,
            $meta,
            'gallery_created',
            'Galerija izveidota',
            'admin',
        );
    } else {
        $meta = efpic_load_gallery_meta($config, $slug);
        if ($meta === null) {
            throw new RuntimeException('Nav atrasts');
        }

        if (trim((string) ($_POST['share_action'] ?? '')) !== '') {
            efpic_apply_share_actions_from_post($meta, 'admin');
        }

        if (!empty($_POST['delete_share_token'])) {
            efpic_delete_share_set($meta, (string) $_POST['delete_share_token']);
        }

        if (!empty($_POST['create_share_set']) && (string) ($_POST['create_share_set'] ?? '') === '1') {
            $label = trim((string) ($_POST['share_set_label'] ?? ''));
            $raw = trim((string) ($_POST['share_set_tokens'] ?? ''));
            $tokens = array_values(array_filter(array_map('trim', explode(',', $raw))));
            $shareEntry = efpic_create_share_set(
                $meta,
                $label,
                $tokens,
                'admin',
                !empty($_POST['share_include_videos']),
                array_key_exists('share_include_slideshow', $_POST)
                    ? !empty($_POST['share_include_slideshow'])
                    : true,
            );
            efpic_log_share_set_created($config, $slug, $meta, $shareEntry, 'admin');
        }

        $meta['name'] = $name;
        $meta['event_date'] = $eventDate;
        $meta['theme'] = 'efpic-base';

        $accent = trim((string) ($_POST['hero_accent_color'] ?? ''));
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $accent) === 1) {
            $meta['hero_accent_color'] = strtolower($accent);
        }

        $pageBg = trim((string) ($_POST['page_bg_color'] ?? ''));
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $pageBg) === 1) {
            $meta['page_bg_color'] = strtolower($pageBg);
        }

        efpic_apply_cover_theme_from_post($meta);
        efpic_apply_cover_media_from_post($meta);
        efpic_apply_mood_theme_from_post($meta);

        $coverTok = trim((string) ($_POST['cover_image_token'] ?? ''));
        if ($coverTok !== '') {
            foreach ($meta['images'] ?? [] as $img) {
                if (is_array($img) && ($img['token'] ?? '') === $coverTok) {
                    $meta['cover_image_token'] = $coverTok;
                    break;
                }
            }
        }
        $meta['cover_from_favorites'] = !empty($_POST['cover_from_favorites']);

        efpic_apply_client_contact_from_post($meta);
        efpic_apply_gallery_client_messages_from_post($meta);
        efpic_apply_gallery_passwords_from_post($meta);
        efpic_apply_client_portal_sections_from_post($meta);
        efpic_apply_client_portal_enabled_from_post($meta);

        $meta['failiem']['folder_parent_url'] = trim((string) ($_POST['folder_parent_url'] ?? ''));
        $meta['failiem']['folder_parent_hash'] = efpic_failiem_parse_folder_hash($meta['failiem']['folder_parent_url']);
        $meta['failiem']['folder_full_url'] = trim((string) ($_POST['folder_full_url'] ?? ''));
        $meta['failiem']['folder_web_url'] = trim((string) ($_POST['folder_web_url'] ?? ''));
        $meta['failiem']['folder_video_url'] = trim((string) ($_POST['folder_video_url'] ?? ''));
        $meta['failiem']['folder_full_hash'] = efpic_failiem_parse_folder_hash($meta['failiem']['folder_full_url']);
        $meta['failiem']['folder_web_hash'] = efpic_failiem_parse_folder_hash($meta['failiem']['folder_web_url']);
        $meta['failiem']['folder_video_hash'] = efpic_failiem_parse_folder_hash($meta['failiem']['folder_video_url']);

        $meta['scenes'] = efpic_parse_scenes_from_post();
        efpic_reassign_orphan_scene_images($meta);
        efpic_apply_image_scenes_from_post($meta);
        efpic_apply_admin_favorites_from_post($meta);
        if (!isset($meta['settings']) || !is_array($meta['settings'])) {
            $meta['settings'] = efpic_gallery_defaults('delivery')['settings'];
        }
        $meta['settings']['client_comments_enabled'] = isset($_POST['client_comments_enabled']);
        $meta['settings']['enable_public_collection'] = efpic_post_flag_is_on('enable_public_collection');
        efpic_apply_face_search_from_post($meta, $config);
        if (efpic_gallery_apply_expires_from_post($meta)) {
            $expiresLabel = efpic_gallery_expires_display($meta);
            efpic_gallery_log_activity(
                $config,
                $slug,
                $meta,
                'expiry_changed',
                $expiresLabel !== '' ? 'Termiņš: līdz ' . $expiresLabel : 'Termiņš noņemts',
                'admin',
            );
        }
        efpic_gallery_migrate_slideshow_meta_in_place($meta);
        efpic_apply_slideshow_from_post($config, $slug, $meta, 'admin');
        if (!empty($_POST['slideshow_draft_generate_video'])) {
            efpic_slideshow_create_from_draft($config, $slug, $meta);
        }
        efpic_apply_videos_from_post($config, $slug, $meta);
        efpic_apply_cover_media_from_post($meta);
        efpic_normalize_gallery_image_sorts($meta);
        if (!empty($_POST['rebaseline_scene_sort'])) {
            efpic_rebaseline_auto_scene_sorts($meta);
        }
        if (!empty($_POST['design_template_save'])) {
            $tplName = trim((string) ($_POST['design_template_name'] ?? ''));
            if ($tplName !== '') {
                efpic_design_template_save($config, $tplName, efpic_design_template_extract_from_meta($meta));
            }
        }
        if (!empty($_POST['design_template_delete'])) {
            $tplId = trim((string) ($_POST['design_template_id'] ?? ''));
            if ($tplId !== '') {
                efpic_design_template_delete($config, $tplId);
            }
        }
        efpic_save_gallery_meta($config, $slug, $meta);
        // Indeksēšanu sāk tikai ar «Indeksēt / turpināt» — ne automātiski pie Saglabāt (mazāk hosting slodzes).
    }

    if (!empty($_POST['sync_now'])) {
        $syncResult = efpic_sync_delivery_gallery($config, $slug);
        $meta = efpic_load_gallery_meta($config, $slug);
        if ($meta !== null) {
            $paired = (int) (($meta['failiem']['sync_stats']['paired'] ?? 0));
            efpic_gallery_log_activity(
                $config,
                $slug,
                $meta,
                'sync',
                'Sinhronizēts no Failiem (' . $paired . ' pāri)',
                'admin',
            );
        }
        efpic_admin_session_start();
        $_SESSION['efpic_admin_sync_dims'] = [
            'backfilled' => (int) ($syncResult['dimensions_backfilled'] ?? 0),
            'reprobed' => (int) ($syncResult['dimensions_reprobed'] ?? 0),
            'stats' => is_array($syncResult['dimensions_stats'] ?? null)
                ? $syncResult['dimensions_stats']
                : efpic_gallery_image_dimensions_stats(efpic_load_gallery_meta($config, $slug) ?? []),
        ];
    }

    $shouldApplyImageOrder = !empty($_POST['image_order']) && is_string($_POST['image_order'])
        && (!empty($_POST['image_order_dirty']) || empty($_POST['autosave']));
    if ($shouldApplyImageOrder) {
        $tokens = array_filter(array_map('trim', explode(',', $_POST['image_order'])));
        if ($tokens !== []) {
            efpic_update_delivery_image_order($config, $slug, $tokens);
            $meta = efpic_load_gallery_meta($config, $slug);
            if ($meta !== null) {
                efpic_apply_image_scenes_from_post($meta);
                efpic_apply_admin_favorites_from_post($meta);
                efpic_save_gallery_meta($config, $slug, $meta);
            }
        }
    }

    return $slug;
}

/** @return array{updated: int, stats: array{total: int, with_dims: int, missing: int}} */
function efpic_admin_backfill_gallery_dimensions(array $config, string $slug): array
{
    @set_time_limit(300);
    $meta = efpic_load_gallery_meta($config, $slug);
    if ($meta === null) {
        return ['updated' => 0, 'stats' => ['total' => 0, 'with_dims' => 0, 'missing' => 0]];
    }

    $all = !empty($_POST['backfill_all']);
    $force = !empty($_POST['backfill_force']);
    if ($force) {
        $meta = efpic_load_gallery_meta($config, $slug);
        if ($meta === null) {
            return ['updated' => 0, 'stats' => ['total' => 0, 'with_dims' => 0, 'missing' => 0, 'stale' => 0]];
        }
        foreach ($meta['images'] ?? [] as &$img) {
            if (is_array($img)) {
                efpic_image_clear_dimensions($img);
            }
        }
        unset($img);
        efpic_save_gallery_meta($config, $slug, $meta);
        $stats = efpic_gallery_image_dimensions_stats($meta);

        return ['updated' => 0, 'stats' => $stats, 'cleared' => true];
    }
    $meta = efpic_load_gallery_meta($config, $slug);
    if ($meta !== null) {
        efpic_gallery_reprobe_changed_image_dimensions($config, $slug, $meta, [], true);
    }
    if ($all) {
        $result = efpic_gallery_backfill_all_image_dimensions($config, $slug, true, EFPIC_DIMS_BACKFILL_BATCH);

        return ['updated' => $result['updated'], 'stats' => $result['stats']];
    }

    $updated = efpic_gallery_backfill_image_dimensions($config, $slug, $meta, EFPIC_DIMS_BACKFILL_BATCH, true);
    $meta = efpic_load_gallery_meta($config, $slug);
    $stats = efpic_gallery_image_dimensions_stats(is_array($meta) ? $meta : []);

    return ['updated' => $updated, 'stats' => $stats];
}

function efpic_admin_render_dimensions_debug_line(array $meta): string
{
    $stats = efpic_gallery_image_dimensions_stats($meta);
    if ($stats['total'] <= 0) {
        return '';
    }

    $html = '<div class="admin-dims-panel" id="admin-dims-debug">';
    $html .= '<div class="admin-dims-panel__actions">';
    $html .= '<button type="button" class="btn admin-btn-sm" id="admin-refresh-dimensions">'
        . 'Pārrēķināt izmērus</button>';
    if ($stats['missing'] > 0) {
        $html .= '<button type="button" class="btn admin-btn-sm" id="admin-backfill-dimensions">'
            . 'Ievākt atlikušos izmērus</button>';
    } elseif (($stats['stale'] ?? 0) > 0) {
        $html .= '<button type="button" class="btn admin-btn-sm" id="admin-backfill-dimensions">'
            . 'Pārrēķināt novecojušos</button>';
    }
    $html .= '<span class="admin-dims-progress-wrap" id="admin-dims-progress-wrap" hidden>'
        . '<span class="admin-dims-progress-bar" id="admin-dims-progress-bar"></span></span>';
    $html .= '<span class="admin-dims-status muted" id="admin-dims-status" hidden></span>';
    $html .= '</div>';
    $html .= '<p class="muted admin-dims-panel__summary">';
    $html .= 'Izmēri saglabāti: <strong id="admin-dims-count">' . $stats['with_dims'] . ' / ' . $stats['total'] . '</strong>';
    if ($stats['missing'] <= 0 && ($stats['stale'] ?? 0) <= 0) {
        $html .= ' — viss kārtībā';
    } else {
        if ($stats['missing'] > 0) {
            $html .= ' · trūkst <strong id="admin-dims-missing">' . $stats['missing'] . '</strong>';
        }
        if (($stats['stale'] ?? 0) > 0) {
            $html .= ' · novecojuši <strong id="admin-dims-stale">' . (int) $stats['stale'] . '</strong>';
        }
    }
    $html .= '</p></div>';

    return $html;
}

function efpic_admin_render_portal_links_block(array $config, array $formMeta, bool $isEdit): string
{
    $portalEnabled = $isEdit ? efpic_client_portal_enabled($formMeta) : true;
    $portalToken = trim((string) ($formMeta['client_access']['portal_token'] ?? ''));
    $portalUrl = $portalToken !== '' ? efpic_portal_url($config, $portalToken) : '';
    $blockClass = 'admin-links-portal-block' . ($portalEnabled ? '' : ' is-portal-disabled');

    $html = '<div class="' . $blockClass . '" id="admin-portal-link-block">';
    $html .= '<input type="hidden" name="client_portal_enabled" value="0">';
    $html .= efpic_render_admin_toggle('Klienta panelis', $portalEnabled, [
        'name' => 'client_portal_enabled',
        'value' => '1',
        'class' => 'admin-toggle-field--links',
    ]);
    $html .= '<p class="admin-links-row admin-links-row--portal" id="admin-portal-link-row">';
    if ($isEdit && $portalUrl !== '') {
        $html .= efpic_admin_render_link_row($portalUrl);
    } elseif ($isEdit) {
        $html .= '<span class="muted">Saite nav pieejama.</span>';
    } else {
        $html .= '<span class="muted">Saite būs pieejama pēc galerijas izveides.</span>';
    }
    $html .= '</p></div>';

    return $html;
}

function efpic_admin_media_thumb_url(array $config, array $img): string
{
    $hash = '';
    if (is_array($img['failiem_web'] ?? null)) {
        $hash = (string) ($img['failiem_web']['file_hash'] ?? '');
    }
    if ($hash === '' && is_array($img['failiem_full'] ?? null)) {
        $hash = (string) ($img['failiem_full']['file_hash'] ?? '');
    }
    if ($hash !== '') {
        return efpic_failiem_thumb_url($config, $hash, 360);
    }
    $token = (string) ($img['token'] ?? '');

    return efpic_base_url($config) . '/v/media/' . rawurlencode($token) . '?size=web&w=240';
}

function efpic_admin_render_edit_tabs_nav(): string
{
    $tabs = [
        ['id' => 'admin-tab-failiem', 'label' => 'Failiem'],
        ['id' => 'admin-tab-basic', 'label' => 'Pamati'],
        ['id' => 'admin-tab-scenes', 'label' => 'Sadaļas'],
        ['id' => 'admin-tab-theme', 'label' => 'Dizains'],
        ['id' => 'admin-tab-messages', 'label' => 'Ziņojumi klientam'],
        ['id' => 'admin-tab-images', 'label' => 'Bildes'],
        ['id' => 'admin-tab-favorites', 'label' => 'Favorītbildes'],
        ['id' => 'admin-tab-share', 'label' => 'Kopīgošana'],
        ['id' => 'admin-tab-analytics', 'label' => 'Analītika'],
        ['id' => 'admin-tab-media', 'label' => 'Slideshow & video'],
    ];
    $out = '<nav class="admin-edit-tabs" role="tablist" aria-label="Galerijas sadaļas">';
    foreach ($tabs as $i => $tab) {
        $active = $i === 0 ? ' is-active' : '';
        $selected = $i === 0 ? 'true' : 'false';
        $out .= '<button type="button" class="admin-edit-tab' . $active . '" role="tab" id="'
            . efpic_admin_esc($tab['id']) . '-tab" aria-selected="' . $selected . '" aria-controls="'
            . efpic_admin_esc($tab['id']) . '" data-admin-tab="' . efpic_admin_esc($tab['id']) . '">'
            . efpic_admin_esc($tab['label']) . '</button>';
    }

    return $out . '</nav>';
}

function efpic_admin_tab_panel_open(string $id, bool $active = false): string
{
    $hidden = $active ? '' : ' hidden';

    return '<div class="admin-tab-panel" id="' . efpic_admin_esc($id) . '" role="tabpanel" tabindex="0"'
        . ' aria-labelledby="' . efpic_admin_esc($id) . '-tab"' . $hidden . ' data-admin-tab-panel>';
}

function efpic_admin_tab_panel_close(): string
{
    return '</div>';
}

function efpic_admin_delivery_form(array $config, ?array $meta, ?string $slug, ?string $flash = null, bool $flashIsError = false): void
{
    $isEdit = $meta !== null && $slug !== null;
    $formMeta = is_array($meta) ? $meta : [];
    if ($isEdit) {
        efpic_ensure_gallery_indexed($config, $slug, $meta);
        efpic_normalize_gallery_image_sorts($meta);
    }
    $failiem = $formMeta['failiem'] ?? [];
    if (!is_array($failiem)) {
        $failiem = [];
    }
    $sceneTitle = isset($formMeta['scenes'][0]['title']) ? (string) $formMeta['scenes'][0]['title'] : 'Galerija';

    $body = '';
    if ($flash !== null) {
        $flashClass = $flashIsError ? 'err' : 'admin-flash';
        $body .= '<p class="' . $flashClass . '">' . efpic_admin_esc($flash) . '</p>';
    }

    $body .= '<form method="post" class="admin-form' . ($isEdit ? ' admin-form--tabbed' : '') . '" id="admin-delivery-form" enctype="multipart/form-data"';
    if ($isEdit && $slug !== null) {
        $body .= ' data-admin-edit-slug="' . efpic_admin_esc($slug) . '"';
        if (!empty($_GET['synced'])) {
            $body .= ' data-dims-after-sync="1"';
        }
    }
    $body .= '>';
    $body .= '<div class="admin-sticky-bar">';
    $body .= '<button type="submit" class="btn primary" name="save" value="1">Saglabāt</button>';
    if ($isEdit) {
        $body .= '<input type="hidden" name="ready_slideshow_payload" id="ready-slideshow-payload" value="">';
        $body .= '<button type="submit" class="btn" name="sync_now" value="1">Sinhronizēt no Failiem</button>';
    }
    $body .= '</div>';

    if ($isEdit) {
        $body .= efpic_admin_render_edit_tabs_nav();
        $body .= efpic_admin_tab_panel_open('admin-tab-failiem', true);
        $body .= efpic_admin_render_failiem_fieldset($failiem);
        $body .= efpic_admin_tab_panel_close();
        $body .= efpic_admin_tab_panel_open('admin-tab-basic', false);
    } elseif (!$isEdit) {
        $body .= '<div class="admin-delivery-sections">';
        $body .= efpic_admin_render_failiem_fieldset($failiem);
    }

    if (!$isEdit) {
        $body .= '<fieldset class="admin-fieldset-full admin-fieldset-compact admin-links-panel" id="admin-fs-saites"><legend>Saites</legend>';
        $body .= efpic_admin_render_portal_links_block($config, $formMeta, false);
        $body .= '<p class="muted admin-field-hint">Publiskā galerijas saite būs redzama pēc izveides.</p>';
        $body .= '</fieldset>';
    }

    if ($isEdit) {
        if (!efpic_gallery_is_active($meta)) {
            $body .= '<p class="admin-warn">Šī galerija ir <strong>dzēsta</strong> — publiski nav pieejama. Atjauno no saraksta «Dzēstās galerijas».</p>';
        }
        $gt = (string) ($meta['gallery_token'] ?? '');
        $body .= '<div class="admin-tab-panel-grid admin-tab-panel-grid--pamati-top">';
        $body .= '<fieldset class="admin-fieldset-full admin-fieldset-compact admin-links-panel" id="admin-fs-saites"><legend>Saites</legend>';
        $body .= '<p class="admin-links-row" id="admin-public-link-row" data-gallery-token="' . efpic_admin_esc($gt) . '"><strong>Publiska saite:</strong> '
            . efpic_admin_render_link_row(efpic_gallery_view_url($config, $gt)) . '</p>';
        $body .= efpic_admin_render_portal_links_block($config, $formMeta, true);
        $body .= '<p class="admin-regenerate-link-row"><button type="button" class="btn admin-btn-sm" id="admin-regenerate-public-link" data-confirm="'
            . efpic_admin_esc('Izveidot jaunu publisko saiti? Vecā saite un ar to saistītās kopīgošanas saites pārtraks darboties.')
            . '">Ģenerēt jaunu publisko saiti</button></p>';
        if (efpic_gallery_has_password($meta)) {
            $body .= '<p class="admin-warn">Galerijai ir <strong>parole</strong> — publiskajā saitē klientam tā jāievada, lai redzētu bildes.</p>';
        }
        $body .= '<div class="admin-links-panel-footer">';
        $stats = $failiem['sync_stats'] ?? null;
        if (is_array($stats)) {
            $body .= '<p class="muted admin-links-sync">Sync: ' . (int) ($stats['paired'] ?? 0) . ' pāri';
            if ((int) ($stats['video_count'] ?? 0) > 0) {
                $body .= ' · ' . (int) ($stats['video_count'] ?? 0) . ' video';
            }
            if ((int) ($stats['orphans_full'] ?? 0) > 0 || (int) ($stats['orphans_web'] ?? 0) > 0) {
                $body .= ' · brīdinājumi: pilns ' . (int) ($stats['orphans_full'] ?? 0) . ', web ' . (int) ($stats['orphans_web'] ?? 0);
            }
            $body .= ' · ' . efpic_admin_esc((string) ($failiem['last_sync_at'] ?? '')) . '</p>';
        }
        $body .= efpic_admin_render_dimensions_debug_line($meta);
        $body .= '</div></fieldset>';
        $body .= efpic_admin_render_face_search_panel($config, $meta, $slug);
        $body .= '</div>';
    }

    $body .= '<fieldset class="admin-fieldset-full" id="admin-fs-pamatinformacija"><legend>Pamatinformācija</legend>';
    $body .= '<div class="admin-form-layout admin-form-layout--pamati">';
    $eventDate = substr((string) ($formMeta['event_date'] ?? ''), 0, 10);
    if ($eventDate === '' && !$isEdit) {
        $eventDate = date('Y-m-d');
    }
    $expiresVal = efpic_gallery_expires_at_value($formMeta) ?? '';
    if ($expiresVal === '' && !$isEdit) {
        $expiresVal = efpic_gallery_default_expires_at();
    }
    $body .= '<div class="admin-form-row admin-form-row--3">';
    $body .= '<label>Nosaukums<input name="name" required value="' . efpic_admin_esc((string) ($formMeta['name'] ?? '')) . '"></label>';
    $body .= '<label class="admin-field-date">Datums (obligāts)<input name="event_date" type="date" required value="' . efpic_admin_esc($eventDate) . '"></label>';
    $body .= '<label class="admin-field-date">Pieejama līdz<input type="date" name="expires_at" value="' . efpic_admin_esc($expiresVal) . '"></label>';
    $body .= '</div>';
    $body .= '<div class="admin-form-row admin-form-row--2">';
    $body .= '<label>Klienta tālrunis (WhatsApp)<input type="tel" name="client_phone" value="' . efpic_admin_esc((string) ($formMeta['client_access']['phone'] ?? '')) . '" placeholder="29123456"></label>';
    $body .= '<label>Klienta e-pasts<input type="email" name="client_email" value="' . efpic_admin_esc((string) ($formMeta['client_access']['email'] ?? '')) . '"></label>';
    $body .= '</div>';
    $body .= '<div class="admin-form-row admin-form-row--2">';
    $body .= efpic_admin_render_password_field(
        'Galerijas parole',
        'gallery_password',
        '',
        'Aizsargā publisko galeriju (/v/g/…). Ieslēdz slēdzi un ievadi paroli.',
        efpic_gallery_has_password($formMeta),
    );
    $body .= efpic_admin_render_password_field(
        'Klienta paneļa parole',
        'client_password',
        '',
        'Aizsargā klienta paneli (/c/p/…). Ieslēdz slēdzi un ievadi paroli.',
        efpic_client_portal_has_password($formMeta),
    );
    $body .= '</div>';
    $body .= '<p class="admin-field-hint admin-field-full">Jaunām galerijām noklusējums ir 12 mēneši. Pēc termiņa galerija vairs nav pieejama.</p>';
    $body .= '</div></fieldset>';

    if ($isEdit && is_array($meta)) {
        $gallerySettings = efpic_gallery_settings($meta);
        $collectionOn = !empty($gallerySettings['enable_public_collection']);
        $commentsOn = !empty($gallerySettings['client_comments_enabled']);
        $portalSections = efpic_client_portal_sections($meta);
        $body .= '<div class="admin-tab-panel-grid">';
        $body .= '<fieldset class="admin-fieldset-full admin-fieldset-compact" id="admin-fs-public-gallery"><legend>Publiskā galerija</legend>';
        $body .= '<input type="hidden" name="enable_public_collection" value="0">';
        $body .= efpic_render_admin_toggle('Atļaut apmeklētājiem izvēlēties bildes izlasei', $collectionOn, [
            'name' => 'enable_public_collection',
            'value' => '1',
        ]);
        $body .= '<p class="admin-field-hint">Pēc noklusējuma izslēgts. Kad ieslēgts, apmeklētāji ar vārdu un e-pastu var veidot vairākas izlases, '
            . 'saņemt saiti turpināšanai un pieprasīt ZIP lejupielādi uz e-pastu. '
            . 'Paslēptās bildes publiskajā skatā nav redzamas un nevar tikt pievienotas izlasei.</p>';
        if ($collectionOn) {
            $body .= '<p class="admin-ok">Izlase ir aktīva — publiskajā galerijā pie bildēm parādās aplītis (augšējā kreisajā stūrī).</p>';
        }
        $body .= '</fieldset>';
        $sectionLabels = [
            'images' => 'Bildes',
            'scenes' => 'Sadaļas',
            'theme' => 'Dizains',
            'share' => 'Kopīgošana',
            'media' => 'Slideshow & video',
        ];
        $body .= '<fieldset class="admin-fieldset-full admin-fieldset-compact" id="admin-fs-client-portal"><legend>Klienta panela sadaļas</legend>';
        if (!efpic_client_portal_enabled($meta)) {
            $body .= '<p class="admin-warn">Klienta panelis ir izslēgts. Ieslēdz to sadaļā «Saites», lai konfigurētu sadaļas.</p>';
        } else {
            $body .= '<p class="admin-field-hint">Ieslēdz vai izslēdz sadaļas, ko klients redz savā panelī. «Iestatījumi» vienmēr ir pieejami.</p>';
            foreach ($sectionLabels as $sectionKey => $sectionLabel) {
                $body .= '<input type="hidden" name="client_portal_section_' . efpic_admin_esc($sectionKey) . '" value="0">';
                $body .= efpic_render_admin_toggle('Rādīt: ' . $sectionLabel, !empty($portalSections[$sectionKey]), [
                    'name' => 'client_portal_section_' . $sectionKey,
                    'value' => '1',
                ]);
            }
            $body .= efpic_render_admin_toggle('Atļaut klienta komentārus pie bildēm', $commentsOn, [
                'name' => 'client_comments_enabled',
            ]);
            $body .= '<p class="admin-field-hint">Pēc noklusējuma izslēgts. Kad ieslēgts, klients var atstāt komentāru pie katras bildes panelī.</p>';
        }
        $body .= '</fieldset>';
        $body .= '</div>';
        $body .= '<fieldset class="admin-fieldset-full" id="admin-fs-activity-log"><legend>Aktivitāšu žurnāls</legend>';
        $body .= efpic_admin_render_activity_log($meta);
        $body .= '</fieldset>';
    }

    if ($isEdit) {
        $body .= efpic_admin_tab_panel_close();
        $body .= efpic_admin_tab_panel_open('admin-tab-scenes');
        if (is_array($meta)) {
            $body .= efpic_admin_render_scenes_fieldset($meta);
        }
        $body .= efpic_admin_tab_panel_close();
        $body .= efpic_admin_tab_panel_open('admin-tab-theme');
        $body .= efpic_admin_render_theme_fieldset($config, $formMeta);
        $body .= efpic_admin_tab_panel_close();
        $body .= efpic_admin_tab_panel_open('admin-tab-messages');
        if (is_array($meta) && $slug !== null) {
            $body .= efpic_admin_render_gallery_client_messages($config, $meta, $slug);
        }
        $body .= efpic_admin_tab_panel_close();
        $body .= efpic_admin_tab_panel_open('admin-tab-images');
    } else {
        $body .= efpic_admin_render_new_gallery_scenes_fieldset($sceneTitle);
        $body .= efpic_admin_render_theme_fieldset($config, $formMeta);
        $body .= '</div>';
    }

    if ($isEdit && ($meta['images'] ?? []) !== []) {
        $coverTok = trim((string) ($meta['cover_image_token'] ?? ''));
        $sortedImages = efpic_sort_images_for_display($meta);
        if ($coverTok === '' && $sortedImages !== []) {
            $first = $sortedImages[0];
            $coverTok = is_array($first) ? (string) ($first['token'] ?? '') : '';
        }
        $body .= '<fieldset class="admin-fieldset-full admin-images-panel"><legend>Kārtība un vāka bilde (' . count($meta['images']) . ' bildes)</legend>';
        $body .= '<p class="muted">Velciet kartītes secībai. Klikšķis — atlase; <kbd>Shift</kbd> — diapazons. Sadaļu maini tieši pie bildes (var ierakstīt jaunu nosaukumu).</p>';
        $body .= efpic_admin_render_image_scene_toolbar($meta);
        $body .= '<div class="admin-share-edit-bar" id="admin-share-edit-bar" hidden>';
        $body .= '<span class="admin-share-edit-bar__label" id="admin-share-edit-bar-label"></span>';
        $body .= '<button type="button" class="btn admin-btn-sm primary" id="admin-share-edit-save">Saglabāt izlasi</button>';
        $body .= '<button type="button" class="btn admin-btn-sm" id="admin-share-edit-cancel">Atcelt</button>';
        $body .= '</div>';
        $sceneOptions = efpic_gallery_scene_options($meta);
        $shareIndex = efpic_share_sets_token_index($meta);
        $shareCountIndex = efpic_share_sets_count_index($meta);
        $body .= '<ul id="sortable" class="admin-media-grid">';
        foreach ($sortedImages as $img) {
            if (!is_array($img)) {
                continue;
            }
            $tok = (string) ($img['token'] ?? '');
            $checked = $tok !== '' && $tok === $coverTok ? ' checked' : '';
            $imgScene = (string) ($img['scene_id'] ?? 'main');
            $imgSceneTitle = 'Galerija';
            foreach ($sceneOptions as $sceneOpt) {
                if ($sceneOpt['id'] === $imgScene) {
                    $imgSceneTitle = (string) $sceneOpt['title'];
                    break;
                }
            }
            $thumb = efpic_admin_media_thumb_url($config, $img);
            $preview = efpic_client_media_url($config, $img, 'web', 1200);
            $adminFav = efpic_image_favorited_admin($img);
            $clientFav = efpic_image_favorited_client($img);
            $likes = efpic_image_likes_count($img);
            $inShare = isset($shareIndex[$tok]);
            $shareCount = (int) ($shareCountIndex[$tok] ?? 0);
            $body .= '<li class="admin-media-card" data-token="' . efpic_admin_esc($tok) . '" data-scene-id="' . efpic_admin_esc($imgScene) . '"'
                . ' data-admin-fav="' . ($adminFav ? '1' : '0') . '" data-client-fav="' . ($clientFav ? '1' : '0') . '"'
                . ' data-in-share="' . ($inShare ? '1' : '0') . '" data-share-count="' . $shareCount . '" data-likes="' . $likes . '">';
            $body .= '<label class="admin-bulk-pick"><input type="checkbox" class="admin-image-pick" value="' . efpic_admin_esc($tok) . '" aria-label="Atlasīt bildi"></label>';
            $body .= '<button type="button" class="admin-media-thumb" data-preview="' . efpic_admin_esc($preview) . '" aria-label="Priekšskatījums">';
            $body .= '<img src="' . efpic_admin_esc($thumb) . '" alt="" width="320" height="320" loading="lazy" decoding="async"></button>';
            $body .= '<div class="admin-media-card__actions">';
            $body .= '<div class="admin-media-card__row admin-media-card__row--toggles">';
            $body .= '<label class="admin-media-toggle admin-cover-pick"><input type="radio" name="cover_image_token" value="'
                . efpic_admin_esc($tok) . '"' . $checked . '><span class="admin-media-toggle__label">Vāks</span></label>';
            $body .= '<label class="admin-media-toggle admin-fav-pick"><input type="checkbox" name="image_fav_admin['
                . efpic_admin_esc($tok) . ']" value="1"' . ($adminFav ? ' checked' : '') . '><span class="admin-media-toggle__label">Favorīts</span></label>';
            $body .= '</div>';
            $body .= '<div class="admin-media-card__row admin-media-card__row--meta">';
            if ($shareCount > 0) {
                $body .= '<span class="admin-share-badge" title="Iekļauta ' . $shareCount . ' kopīgojamā izlasē">⎘ Kopīgots · '
                    . $shareCount . '</span>';
            }
            if ($clientFav) {
                $body .= '<span class="admin-client-fav-badge" title="Klienta favorīts" aria-label="Klienta favorīts">★</span>';
            }
            if ($likes > 0) {
                $body .= '<span class="admin-like-badge" title="Publiskās sirsniņas">♥ ' . $likes . '</span>';
            }
            $body .= '</div></div>';
            $body .= '<div class="admin-scene-pick"><span class="admin-scene-pick-label">Sadaļa</span>';
            $body .= '<input type="hidden" class="admin-scene-id" name="image_scene[' . efpic_admin_esc($tok) . ']" value="'
                . efpic_admin_esc($imgScene) . '">';
            $body .= '<span class="admin-scene-input-wrap">';
            $body .= '<input type="text" class="admin-scene-input" value="' . efpic_admin_esc($imgSceneTitle)
                . '" list="admin-scene-datalist" placeholder="Sadaļa…" autocomplete="off" aria-label="Galerijas sadaļa">';
            $body .= '<button type="button" class="admin-scene-open-btn" aria-label="Izvēlēties sadaļu" title="Esošās sadaļas">▾</button>';
            $body .= '</span></div>';
            $body .= '<span class="admin-sort-name">' . efpic_admin_esc((string) ($img['basename'] ?? $tok)) . '</span></li>';
        }
        $body .= '</ul><input type="hidden" name="image_order" id="image_order" value="">';
        $body .= '<input type="hidden" name="image_order_dirty" id="image_order_dirty" value="0">';
        $body .= '</fieldset>';
        $body .= '<div id="admin-scene-float-bar" class="admin-scene-float-bar" hidden>';
        $body .= '<span class="admin-scene-float-count" id="admin-scene-float-count" aria-live="polite"></span>';
        $body .= '<label class="admin-scene-float-label">Sadaļa<input type="text" id="admin-float-scene-input" list="admin-scene-datalist" placeholder="Visām atlasītajām…" autocomplete="off"></label>';
        $body .= '<button type="button" class="btn primary admin-btn-inline" id="admin-float-apply-scene">Pielietot</button>';
        $body .= '<button type="button" class="btn admin-btn-inline" id="admin-float-clear-picks">Noņemt atlasi</button>';
        $body .= '</div>';
    } elseif ($isEdit) {
        $body .= '<fieldset class="admin-fieldset-full"><legend>Bildes</legend>';
        $body .= '<p class="muted">Vēl nav sinhronizētu bilžu. Izmanto «Sinhronizēt no Failiem» un pārbaudi mapes cilnē Failiem.</p></fieldset>';
    }

    if ($isEdit) {
        $body .= efpic_admin_tab_panel_close();
        $body .= efpic_admin_tab_panel_open('admin-tab-favorites');
        $body .= efpic_admin_render_favorites_fieldset($config, $meta);
        $body .= efpic_admin_tab_panel_close();
        $body .= efpic_admin_tab_panel_open('admin-tab-share');
    }

    if ($isEdit && is_array($meta)) {
        $body .= efpic_admin_render_share_sets($config, $meta);
        $body .= efpic_admin_tab_panel_close();
        $body .= efpic_admin_tab_panel_open('admin-tab-analytics');
        $body .= efpic_admin_render_gallery_analytics_tab($config, $meta, $slug ?? '');
        $body .= efpic_admin_tab_panel_close();
        $body .= efpic_admin_tab_panel_open('admin-tab-media');
        $body .= efpic_admin_render_media_tab($config, $meta, (string) ($meta['gallery_token'] ?? ''), $slug);
        $body .= efpic_admin_tab_panel_close();
    }

    if ($isEdit && is_array($meta) && $slug !== null) {
        $body .= '<input type="hidden" name="send_client_email" id="clientEmailComposeGroupHidden" value="">';
        $body .= '<input type="hidden" name="client_email_compose_subject" id="clientEmailComposeSubjectHidden" value="">';
        $body .= '<input type="hidden" name="client_email_compose_body_html" id="clientEmailComposeBodyHidden" value="">';
    }

    $body .= '</form>';
    if ($isEdit) {
        $body .= efpic_admin_render_media_lightbox('admin-lightbox');
    }

    $footExtra = '';
    if ($isEdit && $slug !== null) {
        $previewUrl = 'delivery_edit.php?slug=' . rawurlencode($slug) . '&poll=client_email_preview';
        $footExtra = '<script>window.EFPIC_CLIENT_EMAIL_PREVIEW_URL=' . json_encode($previewUrl, JSON_UNESCAPED_SLASHES) . ';</script>';
        $footExtra .= '<script src="' . efpic_admin_esc(efpic_asset_url('/admin/assets/rich-text-editor.js')) . '" defer></script>';
        $footExtra .= '<script src="' . efpic_admin_esc(efpic_asset_url('/admin/assets/client-email-compose.js')) . '" defer></script>';
    }

    efpic_admin_layout(
        $isEdit ? 'Rediģēt' : 'Jauna',
        $body,
        $isEdit ? 'list' : 'new',
        $isEdit ? 'Rediģēt galeriju' : 'Jauna galerija',
        $isEdit ? 'Cilnes: Failiem, pamati, sadaļas, dizains, bildes un citas.' : 'Secība: Failiem mapes → saites → pamatinformācija → sadaļas → dizains.',
        $config,
        '',
        $footExtra,
    );
}

function efpic_admin_render_render_queue_panel(array $config): string
{
    $payload = efpic_render_admin_monitor_payload($config);
    $worker = $payload['worker'];
    $stats = $payload['stats'];
    $workerClass = 'admin-render-worker--' . efpic_admin_esc((string) ($worker['status'] ?? 'offline'));

    $html = '<fieldset class="admin-fieldset-full" id="admin-render-queue-panel">';
    $html .= '<legend>Slideshow render rinda</legend>';
    $html .= '<p class="muted">Synology worker statuss un aktīvie render job. Atjaunina automātiski.</p>';
    $html .= '<div class="admin-render-summary">';
    $html .= '<p class="admin-render-worker ' . $workerClass . '">Worker: <strong data-render-worker-status="'
        . efpic_admin_esc((string) ($worker['status'] ?? 'offline')) . '">'
        . efpic_admin_esc((string) ($worker['status_label'] ?? '')) . '</strong>';
    $html .= ' <span class="muted">(pēdējais signāls pirms '
        . efpic_admin_esc((string) ($worker['last_seen_ago'] ?? 'nav datu')) . ')</span></p>';
    $html .= '<ul class="admin-render-stats" data-render-stats>';
    $html .= '<li>Rindā: <strong data-stat="queued">' . (int) ($stats['queued'] ?? 0) . '</strong></li>';
    $html .= '<li>Apstrādē: <strong data-stat="processing">' . (int) ($stats['processing'] ?? 0) . '</strong></li>';
    $html .= '<li>Kļūdas: <strong data-stat="failed">' . (int) ($stats['failed'] ?? 0) . '</strong></li>';
    $html .= '</ul></div>';
    $html .= '<div class="admin-table-wrap"><table class="admin-table admin-render-queue-table">';
    $html .= '<thead><tr><th>Galerija</th><th>Slideshow</th><th>Statuss</th><th>Mēģ.</th><th>Atjaunots</th><th>Darbība</th></tr></thead>';
    $html .= '<tbody id="admin-render-queue-body">';
    $html .= efpic_admin_render_render_queue_rows($payload['jobs']);
    $html .= '</tbody></table></div>';
    $html .= '<p class="muted admin-render-queue-empty" id="admin-render-queue-empty"'
        . ($payload['jobs'] !== [] ? ' hidden' : '') . '>Nav aktīvu render job.</p>';
    $html .= '</fieldset>';

    return $html;
}

/** @param list<array<string, mixed>> $jobs */
function efpic_admin_render_render_queue_rows(array $jobs): string
{
    if ($jobs === []) {
        return '';
    }
    $html = '';
    foreach ($jobs as $job) {
        $html .= '<tr data-job-id="' . efpic_admin_esc((string) ($job['id'] ?? '')) . '">';
        $html .= '<td>';
        if (($job['slug'] ?? '') !== '') {
            $html .= '<a href="delivery_edit.php?slug=' . efpic_admin_esc((string) $job['slug'])
                . '">' . efpic_admin_esc((string) ($job['gallery_name'] ?? '')) . '</a>';
        } else {
            $html .= efpic_admin_esc((string) ($job['gallery_name'] ?? ''));
        }
        $html .= '</td>';
        $html .= '<td>' . efpic_admin_esc((string) ($job['owner_label'] ?? '')) . '</td>';
        $html .= '<td><span class="admin-render-status admin-render-status--'
            . efpic_admin_esc((string) ($job['status'] ?? '')) . '">'
            . efpic_admin_esc((string) ($job['status_label'] ?? '')) . '</span>';
        if (($job['error'] ?? '') !== '') {
            $html .= '<br><span class="muted admin-render-error">' . efpic_admin_esc((string) $job['error']) . '</span>';
        }
        $html .= '</td>';
        $html .= '<td>' . (int) ($job['attempt'] ?? 1) . '/' . (int) ($job['max_attempts'] ?? 3) . '</td>';
        $html .= '<td>' . efpic_admin_esc((string) ($job['updated_ago'] ?? '')) . '</td>';
        $html .= '<td class="admin-render-actions">';
        if (!empty($job['can_retry'])) {
            $html .= '<button type="submit" class="btn admin-btn-sm" name="render_queue_action" value="retry:'
                . efpic_admin_esc((string) ($job['id'] ?? '')) . '">Retry</button> ';
        }
        if (!empty($job['can_cancel'])) {
            $html .= '<button type="submit" class="btn admin-btn-sm admin-btn-danger" name="render_queue_action" value="cancel:'
                . efpic_admin_esc((string) ($job['id'] ?? '')) . '" onclick="return confirm(\'Atcelt render job?\');">Atcelt</button>';
        }
        $html .= '</td></tr>';
    }

    return $html;
}

function efpic_admin_parse_gallery_email_settings_from_post(array $existing): array
{
    $prev = is_array($existing['gallery_email'] ?? null) ? $existing['gallery_email'] : [];
    $smtpPass = trim((string) ($_POST['gallery_email_smtp_pass'] ?? ''));
    if ($smtpPass === '') {
        $smtpPass = (string) ($prev['smtp_pass'] ?? '');
    }

    return [
        'enabled' => efpic_post_flag_is_on('gallery_email_enabled'),
        'from' => trim((string) ($_POST['gallery_email_from'] ?? '')),
        'from_name' => trim((string) ($_POST['gallery_email_from_name'] ?? 'EdgarsFoto')),
        'use_php_mail' => efpic_post_flag_is_on('gallery_email_use_php_mail'),
        'smtp_host' => trim((string) ($_POST['gallery_email_smtp_host'] ?? '')),
        'smtp_port' => max(1, min(65535, (int) ($_POST['gallery_email_smtp_port'] ?? 587))),
        'smtp_secure' => in_array((string) ($_POST['gallery_email_smtp_secure'] ?? 'tls'), ['tls', 'ssl', ''], true)
            ? (string) ($_POST['gallery_email_smtp_secure'] ?? 'tls')
            : 'tls',
        'smtp_user' => trim((string) ($_POST['gallery_email_smtp_user'] ?? '')),
        'smtp_pass' => $smtpPass,
        'auto_expiry_reminder_emails' => efpic_post_flag_is_on('gallery_email_auto_expiry_reminders'),
    ];
}

function efpic_admin_render_gallery_email_settings_fieldset(array $settings): string
{
    $email = is_array($settings['gallery_email'] ?? null) ? $settings['gallery_email'] : [];

    $html = '<fieldset class="admin-fieldset-full"><legend>E-pasts klientam</legend>';
    $html .= '<p class="muted">SMTP vai servera <code>mail()</code>. Sagatavju mainīgo saraksts — sadaļā <strong>Ziņu sagataves</strong> zemāk.</p>';
    $html .= '<input type="hidden" name="gallery_email_enabled" value="0">';
    $html .= efpic_render_admin_toggle('Ieslēgt e-pasta sūtīšanu klientiem', !empty($email['enabled']), [
        'name' => 'gallery_email_enabled',
        'value' => '1',
    ]);
    $html .= '<input type="hidden" name="gallery_email_auto_expiry_reminders" value="0">';
    $html .= efpic_render_admin_toggle('Automātiski sūtīt termiņa atgādinājumus (30 un 7 dienas)', !empty($email['auto_expiry_reminder_emails']), [
        'name' => 'gallery_email_auto_expiry_reminders',
        'value' => '1',
    ]);
    $html .= '<p class="muted">Ja ieslēgts, sistēma nosūta atgādinājuma e-pastus, kad galerijai līdz beigām ir ≤30 vai ≤7 dienas. '
        . 'Tiek izmantotas sagataves no <strong>Ziņu sagataves</strong> un izvēles galerijas cilnē <strong>Ziņojumi klientam</strong>. '
        . '«Jauna galerija» e-pasts vienmēr jāsūta manuāli. Pārbaudi ar cron: <code>/api/gallery-notifications/run</code> (API token).</p>';
    $html .= '<div class="admin-form-layout admin-form-layout--basic">';
    $html .= '<label>Nosūtītāja e-pasts<input type="email" name="gallery_email_from" value="'
        . efpic_admin_esc((string) ($email['from'] ?? '')) . '" placeholder="noreply@edgarsfoto.lv"></label>';
    $html .= '<label>Nosūtītāja vārds<input name="gallery_email_from_name" value="'
        . efpic_admin_esc((string) ($email['from_name'] ?? 'EdgarsFoto')) . '"></label>';
    $html .= '</div>';
    $html .= '<input type="hidden" name="gallery_email_use_php_mail" value="0">';
    $html .= efpic_render_admin_toggle('Izmantot PHP mail() (ja hostingā ieslēgts)', !empty($email['use_php_mail']), [
        'name' => 'gallery_email_use_php_mail',
        'value' => '1',
    ]);
    $html .= '<p class="muted">Ja izslēgts — obligāti aizpildi SMTP laukus zemāk.</p>';
    $html .= '<div class="admin-form-layout admin-form-layout--basic">';
    $html .= '<label>SMTP serveris<input name="gallery_email_smtp_host" value="'
        . efpic_admin_esc((string) ($email['smtp_host'] ?? '')) . '" placeholder="mail.edgarsfoto.lv"></label>';
    $html .= '<label>SMTP ports<input type="number" name="gallery_email_smtp_port" min="1" max="65535" value="'
        . efpic_admin_esc((string) ($email['smtp_port'] ?? 587)) . '"></label>';
    $html .= '<label>SMTP drošība<select name="gallery_email_smtp_secure">';
    foreach (['tls' => 'TLS (587)', 'ssl' => 'SSL (465)', '' => 'Nav'] as $val => $label) {
        $sel = ((string) ($email['smtp_secure'] ?? 'tls')) === $val ? ' selected' : '';
        $html .= '<option value="' . efpic_admin_esc($val) . '"' . $sel . '>' . efpic_admin_esc($label) . '</option>';
    }
    $html .= '</select></label>';
    $html .= '<label>SMTP lietotājs<input name="gallery_email_smtp_user" value="'
        . efpic_admin_esc((string) ($email['smtp_user'] ?? '')) . '"></label>';
    $html .= '<label>SMTP parole<input type="password" name="gallery_email_smtp_pass" value="" autocomplete="new-password" placeholder="'
        . ((string) ($email['smtp_pass'] ?? '') !== '' ? '••••••••' : '') . '"></label>';
    $html .= '<p class="muted">Atstāj paroli tukšu, lai saglabātu esošo.</p>';
    $html .= '</div></fieldset>';

    return $html;
}

function efpic_admin_parse_gallery_whatsapp_settings_from_post(): array
{
    return [
        'default_country_code' => trim((string) ($_POST['gallery_whatsapp_country'] ?? '371')) ?: '371',
    ];
}

function efpic_admin_render_gallery_whatsapp_settings_fieldset(array $settings): string
{
    $wa = is_array($settings['gallery_whatsapp'] ?? null) ? $settings['gallery_whatsapp'] : [];

    $html = '<fieldset class="admin-fieldset-full"><legend>WhatsApp klientam</legend>';
    $html .= '<p class="muted">Manuāla sūtīšana caur <code>wa.me</code> (adminā pie galerijas).</p>';
    $html .= '<label>Valsts kods tālruņiem bez prefiksa<input name="gallery_whatsapp_country" value="'
        . efpic_admin_esc((string) ($wa['default_country_code'] ?? '371')) . '" placeholder="371"></label>';
    $html .= '<p class="muted">Piem. klients ievadījis <code>29123456</code> → sistēma pievieno <code>371</code>.</p>';
    $html .= '</fieldset>';

    return $html;
}

function efpic_admin_save_settings_from_post(array $config): void
{
    efpic_admin_apply_design_templates_from_post($config);

    $byline = trim((string) ($_POST['gallery_byline'] ?? ''));
    if ($byline === '') {
        throw new InvalidArgumentException('Galerijas paraksts obligāts');
    }

    $gapMobile = efpic_sanitize_gallery_feed_gap($_POST['gallery_feed_gap'] ?? null);
    $gapTablet = efpic_sanitize_gallery_feed_gap($_POST['gallery_feed_gap_tablet'] ?? null, 20);
    $gapDesktop = efpic_sanitize_gallery_feed_gap($_POST['gallery_feed_gap_desktop'] ?? null, 24);

    $existing = efpic_load_app_settings($config);
    $email = efpic_admin_parse_gallery_email_settings_from_post($existing);
    if ($email['enabled'] && $email['from'] === '') {
        throw new InvalidArgumentException('Nosūtītāja e-pasts obligāts, ja e-pasts ir ieslēgts');
    }
    if ($email['enabled'] && empty($email['use_php_mail']) && $email['smtp_host'] === '') {
        throw new InvalidArgumentException('SMTP serveris obligāts, ja PHP mail() ir izslēgts');
    }

    $siteLogo = trim((string) ($existing['site_logo'] ?? ''));
    if (!empty($_FILES['site_logo']['tmp_name']) && is_uploaded_file((string) $_FILES['site_logo']['tmp_name'])) {
        $siteLogo = efpic_store_site_asset($config, $_FILES['site_logo'], ['png', 'jpg', 'jpeg', 'webp', 'ico', 'gif'], 'logo');
    }
    $sigImage = trim((string) ($existing['gallery_email_signature_image'] ?? ''));
    $signatureHtml = efpic_sanitize_email_signature_html(
        efpic_signature_host_remote_images(
            $config,
            efpic_signature_host_embedded_images($config, (string) ($_POST['gallery_email_signature'] ?? '')),
        ),
    );
    if ($signatureHtml === '' && $sigImage !== '' && is_file(efpic_site_asset_path($config, $sigImage))) {
        $signatureHtml = '<p><img src="'
            . htmlspecialchars(efpic_site_signature_image_url($config), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" alt=""></p>';
    }

    efpic_save_app_settings($config, [
        'gallery_byline' => $byline,
        'gallery_feed_gap' => $gapMobile,
        'gallery_feed_gap_tablet' => $gapTablet,
        'gallery_feed_gap_desktop' => $gapDesktop,
        'gallery_email' => $email,
        'gallery_whatsapp' => efpic_admin_parse_gallery_whatsapp_settings_from_post(),
        'message_templates' => efpic_admin_parse_message_templates_from_post(),
        'site_logo' => $siteLogo,
        'gallery_email_signature' => $signatureHtml,
        'gallery_email_signature_image' => $sigImage,
    ]);
}

function efpic_admin_render_rich_text_toolbar(string $toolbarId, string $ariaLabel = 'Teksta formatēšana'): string
{
    $html = '<div id="' . efpic_admin_esc($toolbarId) . '" class="admin-rich-text-toolbar admin-signature-toolbar" data-rich-toolbar role="toolbar" aria-label="'
        . efpic_admin_esc($ariaLabel) . '">';
    $html .= '<select data-cmd="fontName" class="admin-signature-select" title="Fonts" aria-label="Fonts">';
    $html .= '<option value="sans-serif">Sans Serif</option>';
    $html .= '<option value="Arial">Arial</option>';
    $html .= '<option value="Helvetica">Helvetica</option>';
    $html .= '<option value="Georgia">Georgia</option>';
    $html .= '<option value="Times New Roman">Times New Roman</option>';
    $html .= '<option value="Verdana">Verdana</option>';
    $html .= '</select>';
    $html .= '<select data-cmd="fontSize" class="admin-signature-select admin-signature-select-size" title="Izmērs" aria-label="Izmērs">';
    $html .= '<option value="2">Mazs</option>';
    $html .= '<option value="3" selected>Parasts</option>';
    $html .= '<option value="4">Liels</option>';
    $html .= '<option value="5">Ļoti liels</option>';
    $html .= '</select>';
    $html .= '<span class="admin-signature-toolbar-sep" aria-hidden="true"></span>';
    $html .= '<button type="button" class="admin-signature-btn" data-cmd="bold" title="Treknraksts"><b>B</b></button>';
    $html .= '<button type="button" class="admin-signature-btn" data-cmd="italic" title="Kursīvs"><i>I</i></button>';
    $html .= '<button type="button" class="admin-signature-btn" data-cmd="underline" title="Pasvītrojums"><u>U</u></button>';
    $html .= '<button type="button" class="admin-signature-btn" data-cmd="foreColor" title="Krāsa">A</button>';
    $html .= '<span class="admin-signature-toolbar-sep" aria-hidden="true"></span>';
    $html .= '<button type="button" class="admin-signature-btn" data-cmd="link" title="Saite">Saite</button>';
    $html .= '<button type="button" class="admin-signature-btn" data-cmd="image" title="Bilde">Bilde</button>';
    $html .= '<span class="admin-signature-toolbar-sep" aria-hidden="true"></span>';
    $html .= '<button type="button" class="admin-signature-btn" data-cmd="justifyLeft" title="Pa kreisi" aria-label="Pa kreisi">◧</button>';
    $html .= '<button type="button" class="admin-signature-btn" data-cmd="justifyCenter" title="Centrā" aria-label="Centrā">◫</button>';
    $html .= '<button type="button" class="admin-signature-btn" data-cmd="justifyRight" title="Pa labi" aria-label="Pa labi">◨</button>';
    $html .= '<span class="admin-signature-toolbar-sep" aria-hidden="true"></span>';
    $html .= '<button type="button" class="admin-signature-btn" data-cmd="insertOrderedList" title="Numurēts saraksts">1.</button>';
    $html .= '<button type="button" class="admin-signature-btn" data-cmd="insertUnorderedList" title="Aizzīmju saraksts">•</button>';
    $html .= '</div>';

    return $html;
}

function efpic_admin_render_rich_text_editor_block(
    string $editorId,
    string $toolbarId,
    string $hiddenInputId,
    string $ariaLabel,
    string $initialHtml = '',
    string $initialJsonId = '',
    string $uploadUrl = '',
    string $wrapExtraClass = '',
    string $hiddenInputName = '',
): string {
    $wrapClass = 'admin-rich-text-editor-wrap admin-signature-editor-wrap' . ($wrapExtraClass !== '' ? ' ' . $wrapExtraClass : '');
    $html = '<div class="' . efpic_admin_esc(trim($wrapClass)) . '" data-rich-editor-wrap';
    if ($uploadUrl !== '') {
        $html .= ' data-upload-url="' . efpic_admin_esc($uploadUrl) . '"';
    }
    $html .= '>';
    $html .= efpic_admin_render_rich_text_toolbar($toolbarId, $ariaLabel);
    $html .= '<div id="' . efpic_admin_esc($editorId) . '" class="admin-rich-text-editor admin-signature-editor" data-rich-editor contenteditable="true" role="textbox" aria-label="'
        . efpic_admin_esc($ariaLabel) . '"></div>';
    $html .= '<input type="hidden" id="' . efpic_admin_esc($hiddenInputId) . '" data-rich-input';
    if ($hiddenInputName !== '') {
        $html .= ' name="' . efpic_admin_esc($hiddenInputName) . '"';
    }
    $html .= ' value="">';
    if ($initialJsonId !== '') {
        $html .= '<script type="application/json" data-rich-initial id="' . efpic_admin_esc($initialJsonId) . '">'
            . json_encode($initialHtml, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)
            . '</script>';
    }
    $html .= '</div>';

    return $html;
}

function efpic_admin_render_email_signature_editor(array $config, array $settings): string
{
    $signature = (string) ($settings['gallery_email_signature'] ?? '');
    if ($signature === '' && efpic_site_signature_image_url($config) !== '') {
        $signature = '<p><img src="' . efpic_admin_esc(efpic_site_signature_image_url($config)) . '" alt=""></p>';
    }

    $html = '<fieldset class="admin-fieldset-full"><legend>E-pasta paraksts</legend>';
    $html .= '<p class="muted">Tiek pievienots automātiskajiem e-pastiem. Vari nokopēt parakstu no Gmail — izkārtojums tiek saglabāts.</p>';
    $html .= efpic_admin_render_rich_text_editor_block(
        'signatureEditorContent',
        'signatureEditorToolbar',
        'galleryEmailSignatureInput',
        'E-pasta paraksts',
        $signature,
        'signatureEditorInitial',
        'settings.php?upload=signature_image',
        '',
        'gallery_email_signature',
    );
    $html .= '</fieldset>';

    return $html;
}

function efpic_admin_apply_design_templates_from_post(array $config): void
{
    $postedNames = $_POST['design_template_name'] ?? null;
    if (!is_array($postedNames)) {
        return;
    }

    $templates = efpic_load_design_templates($config);
    if ($templates === []) {
        return;
    }

    $next = [];
    foreach ($templates as $tpl) {
        if (!is_array($tpl)) {
            continue;
        }
        $id = (string) ($tpl['id'] ?? '');
        if ($id === '') {
            continue;
        }
        if (array_key_exists($id, $postedNames)) {
            $name = trim((string) $postedNames[$id]);
            if ($name === '') {
                throw new InvalidArgumentException('Dizaina šablona nosaukums nevar būt tukšs');
            }
            if ($name !== (string) ($tpl['name'] ?? '')) {
                $tpl['name'] = $name;
                $tpl['updated_at'] = gmdate('c');
            }
        }
        $next[] = $tpl;
    }

    efpic_save_design_templates($config, $next);
}

function efpic_admin_save_design_template_one_from_post(array $config, string $id): void
{
    $id = trim($id);
    if ($id === '') {
        throw new InvalidArgumentException('Nav norādīts šablons');
    }
    $name = trim((string) ($_POST['design_template_name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Dizaina šablona nosaukums nevar būt tukšs');
    }
    if (!efpic_design_template_rename($config, $id, $name)) {
        throw new InvalidArgumentException('Dizaina šablons nav atrasts');
    }
}

function efpic_admin_render_design_templates_settings_fieldset(array $config): string
{
    $templates = efpic_load_design_templates($config);
    $fontsJson = json_encode(efpic_gallery_intro_fonts_family_map(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $fontGroupsJson = json_encode(efpic_gallery_intro_fonts_group_map(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $fontUrlsJson = json_encode(efpic_gallery_intro_fonts_google_urls(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $clientCssUrl = efpic_asset_url('/client/assets/client.css');

    $html = '<fieldset class="admin-fieldset-full" id="admin-fs-design-templates"><legend>Dizaina šabloni</legend>';
    $html .= '<p class="muted">Globāli saglabātie dizaina šabloni. Tos vari lietot jebkurā galerijā cilnē <strong>Dizains</strong>.</p>';

    if ($templates === []) {
        $html .= '<p class="muted">Vēl nav saglabātu šablonu. Izveido tos galerijas rediģēšanas formā — cilne <strong>Dizains → Dizaina šabloni → Saglabāt</strong>.</p>';
        $html .= '</fieldset>';

        return $html;
    }

    $map = [];
    $firstId = '';
    foreach ($templates as $tpl) {
        if (!is_array($tpl)) {
            continue;
        }
        $id = (string) ($tpl['id'] ?? '');
        $name = (string) ($tpl['name'] ?? '');
        if ($id === '' || $name === '') {
            continue;
        }
        if ($firstId === '') {
            $firstId = $id;
        }
        $settings = is_array($tpl['settings'] ?? null) ? $tpl['settings'] : [];
        $map[$id] = [
            'name' => $name,
            'preview' => efpic_design_template_preview_payload($config, $settings),
            'created' => substr((string) ($tpl['created_at'] ?? ''), 0, 10),
            'updated' => substr((string) ($tpl['updated_at'] ?? ''), 0, 10),
        ];
    }

    if ($map === []) {
        $html .= '<p class="muted">Vēl nav saglabātu šablonu. Izveido tos galerijas rediģēšanas formā — cilne <strong>Dizains → Dizaina šabloni → Saglabāt</strong>.</p>';
        $html .= '</fieldset>';

        return $html;
    }

    $selectedId = trim((string) ($_GET['template'] ?? ''));
    if ($selectedId === '' || !isset($map[$selectedId])) {
        $selectedId = $firstId;
    }
    $selected = $map[$selectedId];
    $templatesJson = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $created = (string) ($selected['created'] ?? '');
    $updated = (string) ($selected['updated'] ?? '');
    $metaLine = 'Izveidots: ' . ($created !== '' ? $created : '—');
    if ($updated !== '' && $updated !== $created) {
        $metaLine .= ' · Atjaunots: ' . $updated;
    }

    $html .= '<div id="admin-design-templates-settings-root"'
        . ' data-fonts="' . efpic_admin_esc($fontsJson !== false ? $fontsJson : '{}') . '"'
        . ' data-font-groups="' . efpic_admin_esc($fontGroupsJson !== false ? $fontGroupsJson : '{}') . '"'
        . ' data-font-urls="' . efpic_admin_esc($fontUrlsJson !== false ? $fontUrlsJson : '[]') . '"'
        . ' data-client-css="' . efpic_admin_esc($clientCssUrl) . '"'
        . ' data-templates="' . efpic_admin_esc($templatesJson !== false ? $templatesJson : '{}') . '">';
    $html .= '<div class="admin-design-templates-settings__toolbar">';
    $html .= '<label class="admin-design-templates__field">Apskatīt šablonu<select id="design_template_settings_select">';
    foreach ($map as $id => $entry) {
        $html .= '<option value="' . efpic_admin_esc($id) . '"' . ($id === $selectedId ? ' selected' : '') . '>'
            . efpic_admin_esc((string) ($entry['name'] ?? '')) . '</option>';
    }
    $html .= '</select></label>';
    $html .= '</div>';
    $html .= '<article class="admin-design-template-card" id="admin-design-template-settings-card">';
    $html .= '<input type="hidden" name="design_template_id" id="design_template_settings_id" value="' . efpic_admin_esc($selectedId) . '">';
    $html .= '<div class="admin-design-template-card__main">';
    $html .= '<label class="admin-design-template-card__name">Nosaukums<input type="text" name="design_template_name" id="design_template_settings_name" value="'
        . efpic_admin_esc((string) ($selected['name'] ?? '')) . '" required maxlength="120"></label>';
    $html .= '<p class="muted admin-design-template-card__meta" id="design_template_settings_meta">' . efpic_admin_esc($metaLine) . '</p>';
    $html .= '<div class="admin-design-template-card__actions">';
    $html .= '<button type="submit" class="btn primary admin-btn-inline" name="design_template_save_one" value="1" formnovalidate>Saglabāt</button>';
    $html .= '<button type="submit" class="btn admin-btn-danger admin-btn-inline" name="design_template_delete_one" value="1" formnovalidate data-confirm-delete="1">Dzēst</button>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '<div class="admin-design-template-card__preview-wrap">';
    $html .= '<p class="admin-design-template-card__preview-label">Reāllaika priekšskatījums</p>';
    $html .= efpic_admin_render_cover_preview_devices_grid('admin-cover-live-grid admin-design-template-card__live-grid');
    $html .= '</div></article>';
    $html .= '</div></fieldset>';

    return $html;
}

function efpic_admin_settings_page(array $config): void
{
    $settings = efpic_load_app_settings($config);
    $saved = isset($_GET['saved']);
    $renderQueue = isset($_GET['render_queue']);
    $error = trim((string) ($_GET['error'] ?? ''));

    $body = '';
    if ($saved) {
        $body .= '<p class="admin-ok">Saglabāts.</p>';
    }
    if ($renderQueue) {
        $body .= '<p class="admin-ok">Render rindas darbība izpildīta.</p>';
    }
    if ($error !== '') {
        $body .= '<p class="err">' . efpic_admin_esc($error) . '</p>';
    }

    $body .= '<form method="post" class="admin-form" id="admin-settings-form" enctype="multipart/form-data">';
    $body .= '<input type="hidden" name="confirm_delete" id="settings_confirm_delete" value="">';
    $body .= '<div class="admin-sticky-bar"><button type="submit" class="btn primary" name="save" value="1">Saglabāt</button></div>';
    $body .= '<div class="admin-form-layout">';
    $body .= '<fieldset><legend>Galerijas izskats</legend>';
    $body .= '<label>Galerijas paraksts (virs vāka)<input name="gallery_byline" required value="'
        . efpic_admin_esc((string) ($settings['gallery_byline'] ?? '')) . '" placeholder="Gallery by EdgarsFoto"></label>';
    $body .= '<p class="muted">Parādās visu galeriju sākuma ekrānā, piem. «Gallery by EdgarsFoto». Pamatkrāsu katra galerija nosaka pati (adminā vai klienta panelī).</p>';
    $logoUrl = efpic_site_logo_url($config);
    $body .= '<label>Lapas ikona (logo pārlūka cilnē)<input type="file" name="site_logo" accept="image/png,image/jpeg,image/webp,image/gif,image/x-icon,.ico"></label>';
    if ($logoUrl !== '') {
        $body .= '<p class="muted">Pašreizējā ikona: <img src="' . efpic_admin_esc($logoUrl) . '" alt="" style="height:32px;vertical-align:middle;"></p>';
    } else {
        $body .= '<p class="muted">PNG, JPG, WEBP vai ICO. Parādās pārlūka cilnē visās lapās.</p>';
    }
    $body .= '</fieldset>';
    $gapMobile = (int) ($settings['gallery_feed_gap'] ?? 16);
    $gapTablet = (int) ($settings['gallery_feed_gap_tablet'] ?? 20);
    $gapDesktop = (int) ($settings['gallery_feed_gap_desktop'] ?? 24);
    $body .= '<fieldset><legend>Galerijas iestatījumi</legend>';
    $body .= '<label>Atstarpes — mobilais (px)<input type="number" name="gallery_feed_gap" min="0" max="120" step="1" required value="'
        . efpic_admin_esc((string) $gapMobile) . '"></label>';
    $body .= '<label>Atstarpes — planšete (640px+, px)<input type="number" name="gallery_feed_gap_tablet" min="0" max="120" step="1" required value="'
        . efpic_admin_esc((string) $gapTablet) . '"></label>';
    $body .= '<label>Atstarpes — desktop (1024px+, px)<input type="number" name="gallery_feed_gap_desktop" min="0" max="120" step="1" required value="'
        . efpic_admin_esc((string) $gapDesktop) . '"></label>';
    $body .= '<p class="muted">Attiecas uz visām tēmām: atstarpe starp bildēm un malu atkāpes režģī.</p>';
    $body .= '</fieldset>';
    $body .= efpic_admin_render_design_templates_settings_fieldset($config);
    $body .= efpic_admin_render_gallery_email_settings_fieldset($settings);
    $body .= efpic_admin_render_email_signature_editor($config, $settings);
    $body .= efpic_admin_render_gallery_whatsapp_settings_fieldset($settings);
    $body .= efpic_admin_render_message_templates_fieldset($config);
    if (efpic_gallery_email_ready($config)) {
        $body .= '<p class="admin-ok">E-pasts ir konfigurēts un gatavs sūtīšanai.</p>';
    } elseif (!empty($settings['gallery_email']['enabled'])) {
        $body .= '<p class="err">E-pasts ieslēgts, bet trūkst nosūtītāja vai SMTP / mail() iestatījumu.</p>';
    }
    $body .= efpic_admin_render_render_queue_panel($config);
    $body .= '</div></form>';

    $sigJs = '<script src="' . efpic_admin_esc(efpic_asset_url('/admin/assets/rich-text-editor.js')) . '" defer></script>';

    efpic_admin_layout(
        'Iestatījumi',
        $body,
        'settings',
        'Iestatījumi',
        'Globālie iestatījumi visām publiskajām galerijām (paraksts, režģa atstarpes, render rinda).',
        $config,
        '',
        $sigJs
    );
}

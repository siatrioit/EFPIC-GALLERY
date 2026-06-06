<?php

declare(strict_types=1);

require_once __DIR__ . '/handlers.php';
require_once __DIR__ . '/gallery_assets.php';
require_once __DIR__ . '/slideshow_render.php';

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
    echo '<link rel="stylesheet" href="/client/assets/client.css">';
    echo '<link rel="stylesheet" href="/admin/assets/admin.css"></head><body class="page-auth admin-login">';
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

function efpic_admin_layout(string $title, string $body, string $active = '', ?string $pageHeading = null, ?string $pageLead = null, array $config = []): void
{
    header('Content-Type: text/html; charset=utf-8');
    $heading = $pageHeading ?? $title;
    echo '<!DOCTYPE html><html lang="lv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . efpic_admin_esc($title) . ' — EdgarsFoto</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="/client/assets/client.css">';
    echo '<link rel="stylesheet" href="/admin/assets/admin.css"></head><body class="admin-body">';
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
            $m = efpic_load_gallery_meta($config, $slug);
            if ($m !== null && efpic_is_delivery_gallery($m) && efpic_gallery_status($m) === 'deleted') {
                $deletedCount++;
            }
        }
    }
    echo '<a href="index.php"' . ($active === 'list' ? ' class="active"' : '') . '>Galerijas</a>';
    echo '<a href="delivery_new.php"' . ($active === 'new' ? ' class="active"' : '') . '>Jauna Galerija</a>';
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
    echo '<script src="/admin/assets/admin.js" defer></script></body></html>';
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

    $html = '<fieldset class="admin-fieldset-full admin-scenes-panel admin-scenes-panel--above-images"><legend>Galerijas sadaļas</legend>';
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

function efpic_admin_render_share_set_thumbs(array $config, array $meta, array $guest): string
{
    $tokens = $guest['image_tokens'] ?? [];
    if (!is_array($tokens) || $tokens === []) {
        return '';
    }
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
        $thumb = efpic_admin_media_thumb_url($config, $byToken[$tok]);
        $html .= '<img src="' . efpic_admin_esc($thumb) . '" alt="" width="48" height="48" loading="lazy">';
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

    $html = '<div class="admin-share-compose">';
    $html .= '<h3 class="admin-share-block-title">Jauna kopīgojamā izlase</h3>';
    $html .= '<p class="muted">Ievadi nosaukumu un spied «Sākt» — pārlec uz <strong>Bildes</strong>, kur atzīmē bildes un saglabā izlasi.</p>';
    $html .= '<div class="admin-share-compose-row">';
    $html .= '<label>Kam / nosaukums<input type="text" id="admin-share-new-label" placeholder="piem. Dekoratore Anna" autocomplete="off"></label>';
    $html .= '<label class="admin-check admin-share-videos-check"><input type="checkbox" id="admin-share-new-videos" value="1"> Video izlasē</label>';
    $html .= '<button type="button" class="btn admin-btn-sm primary" id="admin-share-start-new">Sākt jaunu izlasi</button>';
    $html .= '</div></div>';

    $html .= '<div class="admin-share-list-wrap"><h3 class="admin-share-block-title">Izveidotās izlases</h3>';
    if ($guests === []) {
        $html .= '<p class="muted">Nav nevienas kopīgojamās izlases.</p>';
    } else {
        $html .= '<ul class="admin-share-set-list">';
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
            $label = (string) ($g['label'] ?? 'Izlase');
            $html .= '<li class="admin-share-set-item" data-guest-token="' . efpic_admin_esc($gtok) . '"'
                . ' data-share-label="' . efpic_admin_esc($label) . '"'
                . ' data-share-tokens="' . efpic_admin_esc(implode(',', $tokens)) . '"'
                . ' data-share-videos="' . ($includeVideos ? '1' : '0') . '">';
            $html .= '<div class="admin-share-set-head">';
            $html .= '<div><strong class="admin-share-set-name">' . efpic_admin_esc($label) . '</strong>';
            $html .= '<span class="muted admin-share-set-meta">' . $n . ' bildes · ' . efpic_admin_esc(efpic_admin_share_created_by_label($g));
            if ($created !== '') {
                $html .= ' · ' . efpic_admin_esc($created);
            }
            $html .= ' · Video: ' . ($includeVideos ? 'jā' : 'nē') . '</span></div>';
            $html .= '<label class="admin-check admin-share-videos-toggle"><input type="checkbox" class="admin-share-videos-cb" data-guest-token="'
                . efpic_admin_esc($gtok) . '"' . ($includeVideos ? ' checked' : '') . '> Video izlasē</label>';
            $html .= '</div>';
            $html .= efpic_admin_render_share_set_thumbs($config, $meta, $g);
            $html .= '<div class="admin-share-set-foot">';
            $html .= efpic_admin_render_link_row($url);
            $html .= '<div class="admin-share-set-actions">';
            $html .= '<button type="button" class="btn admin-btn-sm primary admin-share-edit" data-guest-token="' . efpic_admin_esc($gtok)
                . '">Labot izlasi</button>';
            $html .= '<button type="button" class="btn admin-btn-sm admin-share-delete" data-guest-token="' . efpic_admin_esc($gtok)
                . '">Dzēst</button>';
            $html .= '</div></div></li>';
        }
        $html .= '</ul>';
    }
    $html .= '</div>';

    return $html;
}

function efpic_admin_render_share_sets(array $config, array $meta): string
{
    $html = '<fieldset class="admin-fieldset-full admin-share-sets-panel" id="admin-share-sets-panel"><legend>Kopīgojamās izlases</legend>';
    $html .= '<p class="muted">Atsevišķas saites ar izvēlētām bildēm. Spied <strong>Labot izlasi</strong>, lai mainītu bildes cilnē Bildes — vari pievienot vai noņemt.</p>';
    $html .= '<div id="admin-share-sets-body">' . efpic_admin_render_share_sets_body($config, $meta) . '</div>';
    $html .= '</fieldset>';

    return $html;
}

function efpic_admin_render_favorite_thumb_grid(array $config, array $meta, string $who, bool $editable): string
{
    $html = '<ul class="admin-fav-grid">';
    $hasAny = false;
    foreach (efpic_sort_images_for_display($meta) as $img) {
        if (!is_array($img)) {
            continue;
        }
        $tok = (string) ($img['token'] ?? '');
        if ($tok === '') {
            continue;
        }
        $isFav = $who === 'admin' ? efpic_image_favorited_admin($img) : efpic_image_favorited_client($img);
        if (!$isFav) {
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
        $html .= '<span class="admin-sort-name">' . efpic_admin_esc((string) ($img['basename'] ?? $tok)) . '</span>';
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

function efpic_admin_render_favorites_and_slideshow(array $config, array $meta, string $galleryToken, string $slug = ''): string
{
    $slots = efpic_gallery_slideshows_struct($meta);
    $adminSlot = efpic_slideshow_slot_with_render($slots['admin']);
    $clientSlot = efpic_slideshow_slot_with_render($slots['client']);
    $adminFavCount = efpic_count_favorites($meta, 'admin');
    $clientFavCount = efpic_count_favorites($meta, 'client');
    $clientActive = efpic_slideshow_slot_public_ready($clientSlot, $clientFavCount);

    $html = '<fieldset class="admin-fieldset-full"><legend>Favorīti un slideshow</legend>';

    $html .= '<div class="admin-fav-columns">';
    $html .= '<div class="admin-fav-col"><h3 class="admin-fav-heading">Mana favorītu izvēle</h3>';
    $html .= '<p class="muted">Atzīmē ★ pie bildēm zemāk vai noņem šeit. Izmanto tavu slideshow.</p>';
    $html .= efpic_admin_render_favorite_thumb_grid($config, $meta, 'admin', true);
    $html .= '</div>';

    $html .= '<div class="admin-fav-col"><h3 class="admin-fav-heading">Klienta favorīti</h3>';
    $html .= '<p class="muted">Ko klients izvēlējies klienta panelī (tikai skatīšanai).</p>';
    $html .= efpic_admin_render_favorite_thumb_grid($config, $meta, 'client', false);
    $html .= '</div></div>';

    $html .= '<div class="admin-slideshow-columns">';
    $html .= '<div class="admin-fav-col"><h3 class="admin-fav-heading">Mans slideshow</h3>';
    if ($clientActive) {
        $html .= '<p class="admin-warn">Klienta slideshow ir aktīva publiskajā galerijā — tava slideshow netiek rādīta.</p>';
    }
    $html .= '<label class="admin-check"><input type="checkbox" name="slideshow_admin_enabled" value="1"' . ($adminSlot['enabled'] ? ' checked' : '') . '> Ieslēgt manu slideshow</label>';
    $html .= '<p class="muted">Kad MP4 ir gatavs, video parādās publiskajā galerijā kā «Slideshow» sadaļa (visos motīvos). Bez MP4 — interaktīvs slideshow (bildes + MP3) pic-time motīvā.</p>';
    $html .= '<label>Intervāls (sek.)<input type="number" name="slideshow_admin_interval" min="2" max="60" value="' . (int) $adminSlot['interval_sec'] . '"></label>';
    $html .= '<p class="muted">Manas favorītbildes: <strong>' . $adminFavCount . '</strong></p>';
    if ($adminSlot['audio_file'] !== '') {
        $url = efpic_gallery_asset_url($config, $galleryToken, $adminSlot['audio_file']);
        $html .= '<p class="admin-ok">MP3: <a href="' . efpic_admin_esc($url) . '" target="_blank" rel="noopener">' . efpic_admin_esc($adminSlot['audio_file']) . '</a></p>';
        $html .= '<label class="admin-check"><input type="checkbox" name="slideshow_admin_remove_audio" value="1"> Dzēst MP3</label>';
    }
    $html .= '<label>Augšupielādēt MP3<input type="file" name="slideshow_admin_mp3" accept="audio/mpeg,.mp3"></label>';
    $html .= '<label>Intro virsraksts<input type="text" name="slideshow_admin_intro_title" maxlength="120" value="'
        . efpic_admin_esc($adminSlot['intro_title']) . '" placeholder="piem. Jānis + Ieva"></label>';
    $html .= '<p class="muted">Intro video: lielie burti, treknraksts. «+» starp vārdiem — jauna rinda (piem. Rihards + Annika).</p>';
    $html .= '<label>Fona krāsa<select name="slideshow_admin_bg_mode">';
    $html .= '<option value="white"' . ($adminSlot['bg_mode'] === 'white' ? ' selected' : '') . '>Balts</option>';
    $html .= '<option value="gallery"' . ($adminSlot['bg_mode'] === 'gallery' ? ' selected' : '') . '>Galerijas fons</option>';
    $html .= '</select></label>';
    $html .= '<label class="admin-check"><input type="checkbox" name="slideshow_admin_image_source_all" value="1"'
        . ($adminSlot['image_source'] === 'all' ? ' checked' : '') . '> Izmantot visas redzamās bildes (ne tikai favorītus)</label>';
    $renderStatus = (string) ($adminSlot['render_status'] ?? 'none');
    $html .= '<p class="muted" id="slideshow-admin-render-status">Video statuss: <strong data-render-status="' . efpic_admin_esc($renderStatus) . '">'
        . efpic_admin_esc(efpic_render_status_label($renderStatus)) . '</strong></p>';
    if ($renderStatus === 'failed' && ($adminSlot['render_error'] ?? '') !== '') {
        $html .= '<p class="admin-warn">' . efpic_admin_esc((string) $adminSlot['render_error']) . '</p>';
    }
    if (($adminSlot['video_file'] ?? '') !== '') {
        $videoFile = (string) $adminSlot['video_file'];
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
    $html .= '<div class="admin-media-action-row">';
    $html .= '<button type="submit" class="btn primary" name="save" value="1">Saglabāt slideshow</button>';
    $html .= '<button type="submit" class="btn" name="slideshow_admin_generate_video" value="1"'
        . ' onclick="return confirm(\'Ģenerēt jaunu slideshow video? Esošais MP4 tiks aizstāts, kad render pabeigts.\');">Ģenerēt video</button>';
    $html .= '</div>';
    $html .= '</div>';

    $html .= '<div class="admin-fav-col admin-fav-col--readonly"><h3 class="admin-fav-heading">Klienta slideshow</h3>';
    $html .= '<p class="muted">Konfigurē klienta panelī. Rāda publiski, ja ir ieslēgta, MP3 un favorīti.</p>';
    $html .= '<ul class="admin-status-list">';
    $html .= '<li>Ieslēgta: <strong>' . ($clientSlot['enabled'] ? 'Jā' : 'Nē') . '</strong></li>';
    $html .= '<li>Favorīti: <strong>' . $clientFavCount . '</strong></li>';
    $html .= '<li>MP3: <strong>' . ($clientSlot['audio_file'] !== '' ? 'Jā' : 'Nē') . '</strong></li>';
    $html .= '<li>Publiski aktīva: <strong>' . ($clientActive ? 'Jā (galvenā)' : 'Nē') . '</strong></li>';
    $html .= '<li>MP4 gatavs: <strong>' . (efpic_slideshow_slot_video_ready($clientSlot) ? 'Jā' : 'Nē') . '</strong></li>';
    $html .= '</ul>';
    if ($clientSlot['audio_file'] !== '') {
        $html .= '<p><a href="' . efpic_admin_esc(efpic_gallery_asset_url($config, $galleryToken, $clientSlot['audio_file'])) . '" target="_blank" rel="noopener">Klausīties klienta MP3</a></p>';
    }
    $html .= '</div></div></fieldset>';

    return $html;
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

function efpic_admin_render_videos_fieldset(array $config, array $meta, string $galleryToken): string
{
    $scenes = efpic_gallery_scene_options($meta);
    $html = '<fieldset class="admin-fieldset-full" id="admin-videos-panel"><legend>Video</legend>';
    $html .= '<p class="muted">Video tiek rādīti publiskajā galerijā <strong>pirms</strong> izvēlētās sadaļas bildēm. Izmaiņas saglabājas automātiski.</p>';
    $html .= '<div id="admin-videos-list" class="admin-videos-list">';
    $html .= efpic_admin_render_existing_videos_list($config, $meta, $galleryToken);
    $html .= '</div>';
    $html .= '<div class="admin-video-add">';
    $html .= '<h3 class="admin-video-add-title">Pievienot video failu</h3>';
    $html .= '<div class="admin-form-split">';
    $html .= '<label>Video fails (MP4)<input type="file" name="gallery_video" accept="video/mp4,video/quicktime,video/webm,.mp4,.mov,.webm"></label>';
    $html .= '<label>Virsraksts<input name="video_upload_title" placeholder="piem. Laulību ceremonija"></label>';
    $html .= '<label>Sadaļa<select name="video_upload_scene" class="admin-video-scene-select">';
    foreach ($scenes as $scene) {
        $html .= '<option value="' . efpic_admin_esc($scene['id']) . '">' . efpic_admin_esc($scene['title']) . '</option>';
    }
    $html .= '</select></label></div>';
    $html .= '<h3 class="admin-video-add-title">Pievienot YouTube / Vimeo</h3>';
    $html .= '<div class="admin-form-split admin-video-embed-add">';
    $html .= '<label>YouTube / Vimeo saite<input name="video_embed_url" placeholder="https://youtube.com/watch?v=..."></label>';
    $html .= '<label>Virsraksts<input name="video_embed_title" placeholder="Ievietots video"></label>';
    $html .= '<label>Sadaļa<select name="video_embed_scene" class="admin-video-scene-select">';
    foreach ($scenes as $scene) {
        $html .= '<option value="' . efpic_admin_esc($scene['id']) . '">' . efpic_admin_esc($scene['title']) . '</option>';
    }
    $html .= '</select></label></div>';
    $html .= '<div class="admin-video-submit-row">';
    $html .= '<button type="button" class="btn primary admin-btn-inline" id="admin-add-embed-video">Pievienot video</button>';
    $html .= '</div></div></fieldset>';

    return $html;
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
    $sort = (string) ($_GET['sort'] ?? 'date');
    if (!in_array($sort, ['name', 'date'], true)) {
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
        $ma = $a['meta'];
        $mb = $b['meta'];
        if ($sort === 'name') {
            $cmp = strnatcasecmp((string) ($ma['name'] ?? ''), (string) ($mb['name'] ?? ''));
        } else {
            $da = (string) ($ma['event_date'] ?? '');
            $db = (string) ($mb['event_date'] ?? '');
            $cmp = $da <=> $db;
            if ($cmp === 0) {
                $cmp = strnatcasecmp((string) ($ma['name'] ?? ''), (string) ($mb['name'] ?? ''));
            }
        }

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
    $body .= '<span class="admin-sort-links">Kārtot: ';
    foreach (['name' => 'Nosaukums', 'date' => 'Datums'] as $key => $label) {
        $nextOrder = ($sort === $key && $order === 'asc') ? 'desc' : 'asc';
        $body .= '<a href="' . efpic_admin_esc($baseQs(['sort' => $key, 'order' => $nextOrder])) . '">' . $label;
        if ($sort === $key) {
            $body .= $order === 'asc' ? ' ↑' : ' ↓';
        }
        $body .= '</a> ';
    }
    $body .= '</span></div>';

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
        $syncAt = (string) ($meta['failiem']['last_sync_at'] ?? '—');
        $views = (int) ($meta['analytics']['views'] ?? 0);
        $date = substr((string) ($meta['event_date'] ?? ''), 0, 10);
        $rows .= '<tr>';
        $rows .= '<td><input type="checkbox" name="gallery_slugs[]" value="' . efpic_admin_esc($slug) . '" class="admin-gallery-pick"></td>';
        $rows .= '<td><a href="delivery_edit.php?slug=' . rawurlencode($slug) . '">' . efpic_admin_esc($meta['name'] ?? $slug) . '</a></td>';
        $rows .= '<td>' . efpic_admin_esc($date !== '' ? $date : '—') . '</td>';
        $rows .= '<td>' . count($meta['images'] ?? []) . ' / ' . $paired . '</td>';
        $rows .= '<td class="muted">' . efpic_admin_esc($syncAt) . ' · skat. ' . $views . '</td>';
        $rows .= '<td>';
        if ($view === 'active' && $gt !== '') {
            $rows .= '<a href="' . efpic_admin_esc(efpic_gallery_view_url($config, $gt)) . '" target="_blank" rel="noopener">Skatīt</a>';
        } else {
            $rows .= '<span class="muted">Nav publiska</span>';
        }
        $rows .= '</td></tr>';
    }

    if ($rows === '') {
        $empty = $view === 'deleted'
            ? 'Nav dzēstu galeriju.'
            : 'Vēl nav galeriju. <a href="delivery_new.php">Izveidot jaunu</a>';
        $rows = '<tr><td colspan="6" class="muted">' . $empty . '</td></tr>';
    }

    $body .= '<div class="admin-table-wrap"><table class="admin-table"><thead><tr>';
    $body .= '<th></th><th>Nosaukums</th><th>Datums</th><th>Bildes</th><th>Sync</th><th></th>';
    $body .= '</tr></thead><tbody>' . $rows . '</tbody></table></div></form>';

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
            'password' => (string) ($_POST['gallery_password'] ?? ''),
            'client_email' => trim((string) ($_POST['client_email'] ?? '')),
            'client_password' => (string) ($_POST['client_password'] ?? ''),
            'folder_parent_url' => trim((string) ($_POST['folder_parent_url'] ?? '')),
            'folder_full_url' => trim((string) ($_POST['folder_full_url'] ?? '')),
            'folder_web_url' => trim((string) ($_POST['folder_web_url'] ?? '')),
            'theme' => (string) ($_POST['theme'] ?? 'pic-time'),
        ]);
        $slug = $created['slug'];
        $meta = $created['meta'];
        $meta['scenes'] = efpic_parse_scenes_from_post();
        $accent = trim((string) ($_POST['hero_accent_color'] ?? ''));
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $accent) === 1) {
            $meta['hero_accent_color'] = strtolower($accent);
        }
        $pageBg = trim((string) ($_POST['page_bg_color'] ?? ''));
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $pageBg) === 1) {
            $meta['page_bg_color'] = strtolower($pageBg);
        }
        efpic_save_gallery_meta($config, $slug, $meta);
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
            efpic_create_share_set($meta, $label, $tokens, 'admin', !empty($_POST['share_include_videos']));
        }

        $meta['name'] = $name;
        $meta['event_date'] = $eventDate;
        $meta['theme'] = (string) ($_POST['theme'] ?? $meta['theme']);
        if (!efpic_is_valid_gallery_theme($meta['theme'])) {
            $meta['theme'] = 'pic-time';
        }

        $accent = trim((string) ($_POST['hero_accent_color'] ?? ''));
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $accent) === 1) {
            $meta['hero_accent_color'] = strtolower($accent);
        }

        $pageBg = trim((string) ($_POST['page_bg_color'] ?? ''));
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $pageBg) === 1) {
            $meta['page_bg_color'] = strtolower($pageBg);
        }

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

        $gp = (string) ($_POST['gallery_password'] ?? '');
        if ($gp !== '') {
            $meta['password_hash'] = efpic_hash_password($gp);
        }

        $meta['failiem']['folder_parent_url'] = trim((string) ($_POST['folder_parent_url'] ?? ''));
        $meta['failiem']['folder_parent_hash'] = efpic_failiem_parse_folder_hash($meta['failiem']['folder_parent_url']);
        $meta['failiem']['folder_full_url'] = trim((string) ($_POST['folder_full_url'] ?? ''));
        $meta['failiem']['folder_web_url'] = trim((string) ($_POST['folder_web_url'] ?? ''));
        $meta['failiem']['folder_full_hash'] = efpic_failiem_parse_folder_hash($meta['failiem']['folder_full_url']);
        $meta['failiem']['folder_web_hash'] = efpic_failiem_parse_folder_hash($meta['failiem']['folder_web_url']);

        $meta['scenes'] = efpic_parse_scenes_from_post();
        efpic_reassign_orphan_scene_images($meta);
        efpic_apply_image_scenes_from_post($meta);
        efpic_apply_admin_favorites_from_post($meta);
        if (!isset($meta['settings']) || !is_array($meta['settings'])) {
            $meta['settings'] = efpic_gallery_defaults('delivery')['settings'];
        }
        $meta['settings']['client_comments_enabled'] = isset($_POST['client_comments_enabled']);
        efpic_apply_slideshow_from_post($config, $slug, $meta, 'admin');
        if (!empty($_POST['slideshow_admin_generate_video'])) {
            efpic_slideshow_enqueue_render($config, $slug, $meta, 'admin');
        }
        efpic_apply_videos_from_post($config, $slug, $meta);
        efpic_normalize_gallery_image_sorts($meta);
        if (!empty($_POST['rebaseline_scene_sort'])) {
            efpic_rebaseline_auto_scene_sorts($meta);
        }
        efpic_save_gallery_meta($config, $slug, $meta);
    }

    if (!empty($_POST['sync_now'])) {
        efpic_sync_delivery_gallery($config, $slug);
    }

    if (!empty($_POST['image_order_dirty']) && !empty($_POST['image_order']) && is_string($_POST['image_order'])) {
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
        ['id' => 'admin-tab-basic', 'label' => 'Pamati'],
        ['id' => 'admin-tab-failiem', 'label' => 'Failiem'],
        ['id' => 'admin-tab-scenes', 'label' => 'Sadaļas'],
        ['id' => 'admin-tab-images', 'label' => 'Bildes'],
        ['id' => 'admin-tab-share', 'label' => 'Kopīgošana'],
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
    }
    $body .= '>';
    $body .= '<div class="admin-sticky-bar">';
    $body .= '<button type="submit" class="btn primary" name="save" value="1">Saglabāt</button>';
    if ($isEdit) {
        $body .= '<button type="submit" class="btn" name="sync_now" value="1">Sinhronizēt no Failiem</button>';
    }
    $body .= '</div>';

    if ($isEdit) {
        $body .= efpic_admin_render_edit_tabs_nav();
    }

    if (!$isEdit) {
        $body .= '<fieldset class="admin-fieldset-full"><legend>Galerijas sadaļas</legend>';
        $body .= '<label>Pirmās sadaļas virsraksts<input name="scene_title" value="' . efpic_admin_esc($sceneTitle) . '"></label>';
        $body .= '<p class="muted">Pēc izveides varēs pievienot vairākas sadaļas.</p></fieldset>';
    }

    if ($isEdit) {
        $body .= efpic_admin_tab_panel_open('admin-tab-basic', true);
        if (!efpic_gallery_is_active($meta)) {
            $body .= '<p class="admin-warn">Šī galerija ir <strong>dzēsta</strong> — publiski nav pieejama. Atjauno no saraksta «Dzēstās galerijas».</p>';
        }
        $gt = (string) ($meta['gallery_token'] ?? '');
        $portal = (string) ($meta['client_access']['portal_token'] ?? '');
        $body .= '<div class="admin-links">';
        $body .= '<p class="admin-links-row" id="admin-public-link-row" data-gallery-token="' . efpic_admin_esc($gt) . '"><strong>Publiska saite:</strong> '
            . efpic_admin_render_link_row(efpic_gallery_view_url($config, $gt)) . '</p>';
        $body .= '<p class="admin-regenerate-link-row"><button type="button" class="btn admin-btn-sm" id="admin-regenerate-public-link" data-confirm="'
            . efpic_admin_esc('Izveidot jaunu publisko saiti? Vecā saite un ar to saistītās kopīgošanas saites pārtraks darboties.')
            . '">Ģenerēt jaunu publisko saiti</button></p>';
        $body .= '<p class="admin-links-row"><strong>Klienta panelis:</strong> '
            . efpic_admin_render_link_row(efpic_portal_url($config, $portal)) . '</p>';
        if (efpic_gallery_has_password($meta)) {
            $body .= '<p class="admin-warn">Galerijai ir <strong>parole</strong> — publiskajā saitē klientam tā jāievada, lai redzētu bildes.</p>';
        }
        $stats = $failiem['sync_stats'] ?? null;
        if (is_array($stats)) {
            $body .= '<p class="muted">Sync: ' . (int) ($stats['paired'] ?? 0) . ' pāri';
            if ((int) ($stats['orphans_full'] ?? 0) > 0 || (int) ($stats['orphans_web'] ?? 0) > 0) {
                $body .= ' · brīdinājumi: pilns ' . (int) ($stats['orphans_full'] ?? 0) . ', web ' . (int) ($stats['orphans_web'] ?? 0);
            }
            $body .= ' · ' . efpic_admin_esc((string) ($failiem['last_sync_at'] ?? '')) . '</p>';
        }
        $body .= '</div>';
    }

    if (!$isEdit) {
        $body .= '<div class="admin-form-layout">';
    }

    $body .= '<fieldset class="admin-fieldset-full"><legend>Pamatinformācija</legend>';
    $body .= '<div class="admin-form-layout admin-form-layout--basic">';
    $body .= '<label>Nosaukums<input name="name" required value="' . efpic_admin_esc((string) ($formMeta['name'] ?? '')) . '"></label>';
    if (!$isEdit) {
        $body .= '<label>Slug (URL)<input name="slug" placeholder="pasakuma-foto"></label>';
    }
    $eventDate = substr((string) ($formMeta['event_date'] ?? ''), 0, 10);
    if ($eventDate === '' && !$isEdit) {
        $eventDate = date('Y-m-d');
    }
    $body .= '<label>Datums (obligāts)<input name="event_date" type="date" required value="' . efpic_admin_esc($eventDate) . '"></label>';
    $body .= '<label>Galerijas parole (jauna)<input type="password" name="gallery_password" autocomplete="new-password"></label>';
    $body .= '<label>Klienta e-pasts<input type="email" name="client_email" value="' . efpic_admin_esc((string) ($formMeta['client_access']['email'] ?? '')) . '"></label>';
    $body .= '<label>Klienta parole (jauna)<input type="password" name="client_password" autocomplete="new-password"></label>';
    $theme = (string) ($formMeta['theme'] ?? 'pic-time');
    if (!efpic_is_valid_gallery_theme($theme)) {
        $theme = 'pic-time';
    }
    $heroAccent = efpic_client_hero_accent_color($formMeta);
    $pageBg = efpic_client_page_bg_color($config, $formMeta);
    $body .= '<label>Tēma<select name="theme">';
    foreach (efpic_gallery_theme_options() as $k => $lbl) {
        $sel = $k === $theme ? ' selected' : '';
        $body .= '<option value="' . efpic_admin_esc($k) . '"' . $sel . '>' . efpic_admin_esc($lbl) . '</option>';
    }
    $body .= '</select></label>';
    $body .= efpic_admin_color_field('hero_accent_color', 'Vāka krāsa (sākuma ekrāns)', $heroAccent);
    $body .= efpic_admin_color_field('page_bg_color', 'Galerijas pamatkrāsa (režģis un bilžu skats)', $pageBg);
    $body .= '<p class="muted admin-fieldset-full">Krāsas darbojas visās EdgarsFoto tēmās. Ja nepieciešams, klients var tās mainīt klienta panelī.</p>';
    if ($isEdit && is_array($meta)) {
        $gallerySettings = efpic_gallery_settings($meta);
        $commentsOn = !empty($gallerySettings['client_comments_enabled']);
        $body .= '<fieldset class="admin-fieldset-full"><legend>Klienta panelis</legend>';
        $body .= '<label class="admin-check"><input type="checkbox" name="client_comments_enabled" value="1"'
            . ($commentsOn ? ' checked' : '') . '> Atļaut klienta komentārus pie bildēm</label>';
        $body .= '<p class="muted">Pēc noklusējuma izslēgts. Kad ieslēgts, klients var atstāt komentāru pie katras bildes panelī.</p>';
        $body .= '</fieldset>';
    }
    $body .= '</div></fieldset>';

    if ($isEdit) {
        $body .= efpic_admin_tab_panel_close();
        $body .= efpic_admin_tab_panel_open('admin-tab-failiem');
    }

    $body .= '<fieldset class="admin-fieldset-full"><legend>Failiem.lv mapes</legend>';
    $body .= '<div class="admin-form-layout admin-form-layout--failiem">';
    $body .= '<p class="muted admin-fieldset-full">Pilns izmērs (PRINT) un web (mazāks). Piem. https://failiem.lv/u/…</p>';
    $body .= '<label class="admin-fieldset-full">Galvenā mape (AI meklēšanai, opcija)<input name="folder_parent_url" value="'
        . efpic_admin_esc((string) ($failiem['folder_parent_url'] ?? '')) . '" placeholder="https://failiem.lv/u/3989fkmbt7"></label>';
    $body .= '<label>Mapes pilns<input name="folder_full_url" value="' . efpic_admin_esc((string) ($failiem['folder_full_url'] ?? '')) . '"></label>';
    $body .= '<label>Mapes web<input name="folder_web_url" value="' . efpic_admin_esc((string) ($failiem['folder_web_url'] ?? '')) . '"></label>';
    $body .= '</div></fieldset>';

    if ($isEdit) {
        $body .= efpic_admin_tab_panel_close();
        $body .= efpic_admin_tab_panel_open('admin-tab-scenes');
    } elseif (!$isEdit) {
        $body .= '</div>';
    }

    if ($isEdit && is_array($meta)) {
        $body .= efpic_admin_render_scenes_fieldset($meta);
        $body .= efpic_admin_tab_panel_close();
        $body .= efpic_admin_tab_panel_open('admin-tab-images');
    }

    if ($isEdit && ($meta['images'] ?? []) !== []) {
        $coverTok = trim((string) ($meta['cover_image_token'] ?? ''));
        $sortedImages = efpic_sort_images_for_display($meta);
        if ($coverTok === '' && $sortedImages !== []) {
            $first = $sortedImages[0];
            $coverTok = is_array($first) ? (string) ($first['token'] ?? '') : '';
        }
        $body .= '<fieldset class="admin-fieldset-full admin-images-panel"><legend>Kārtība un vāka bilde (' . count($meta['images']) . ' bildes)</legend>';
        $coverFromFav = efpic_gallery_cover_from_favorites($meta);
        $body .= '<label class="admin-check admin-fieldset-full"><input type="checkbox" name="cover_from_favorites" value="1"'
            . ($coverFromFav ? ' checked' : '') . '> Vāka vietā rādīt nejaušu favorītu bildi</label>';
        $body .= '<p class="muted admin-fieldset-full">Izslēgts — tiek rādīta ar «Vāks» atzīmētā bilde. Ieslēgts — ja ir favorīti, vāks tiek ņemts no tiem (admin favorīti, pēc tam klienta); ja nav favorītu, tiek rādīta izvēlētā vāka bilde.</p>';
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
        $body .= '<input type="hidden" name="favorites_dirty" id="favorites_dirty" value="0">';
        $body .= '</fieldset>';
        $body .= '<div id="admin-lightbox" class="admin-lightbox" hidden role="dialog" aria-modal="true" aria-label="Bildes priekšskatījums">';
        $body .= '<button type="button" class="admin-lightbox-close" aria-label="Aizvērt">&times;</button>';
        $body .= '<img src="" alt=""></div>';
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
        $body .= efpic_admin_tab_panel_open('admin-tab-share');
    }

    if ($isEdit && is_array($meta)) {
        $body .= efpic_admin_render_share_sets($config, $meta);
        $body .= efpic_admin_tab_panel_close();
        $body .= efpic_admin_tab_panel_open('admin-tab-media');
        $body .= efpic_admin_render_favorites_and_slideshow($config, $meta, (string) ($meta['gallery_token'] ?? ''), $slug);
        $body .= efpic_admin_render_videos_fieldset($config, $meta, (string) ($meta['gallery_token'] ?? ''));
        $body .= efpic_admin_tab_panel_close();
    }

    $body .= '</form>';

    efpic_admin_layout(
        $isEdit ? 'Rediģēt' : 'Jauna',
        $body,
        $isEdit ? 'list' : 'new',
        $isEdit ? 'Rediģēt galeriju' : 'Jauna galerija',
        $isEdit ? 'Sadaļas cilnēs: pamati, Failiem, bildes, kopīgošana un mediji.' : 'Izveido jaunu galeriju un piesaisti Failiem mapes.',
        $config
    );
}

function efpic_admin_save_settings_from_post(array $config): void
{
    $byline = trim((string) ($_POST['gallery_byline'] ?? ''));
    if ($byline === '') {
        throw new InvalidArgumentException('Galerijas paraksts obligāts');
    }

    $gapMobile = efpic_sanitize_gallery_feed_gap($_POST['gallery_feed_gap'] ?? null);
    $gapTablet = efpic_sanitize_gallery_feed_gap($_POST['gallery_feed_gap_tablet'] ?? null, 20);
    $gapDesktop = efpic_sanitize_gallery_feed_gap($_POST['gallery_feed_gap_desktop'] ?? null, 24);

    efpic_save_app_settings($config, [
        'gallery_byline' => $byline,
        'gallery_feed_gap' => $gapMobile,
        'gallery_feed_gap_tablet' => $gapTablet,
        'gallery_feed_gap_desktop' => $gapDesktop,
    ]);
}

function efpic_admin_settings_page(array $config): void
{
    $settings = efpic_load_app_settings($config);
    $saved = isset($_GET['saved']);
    $error = trim((string) ($_GET['error'] ?? ''));

    $body = '';
    if ($saved) {
        $body .= '<p class="admin-ok">Saglabāts.</p>';
    }
    if ($error !== '') {
        $body .= '<p class="err">' . efpic_admin_esc($error) . '</p>';
    }

    $body .= '<form method="post" class="admin-form">';
    $body .= '<div class="admin-sticky-bar"><button type="submit" class="btn primary" name="save" value="1">Saglabāt</button></div>';
    $body .= '<div class="admin-form-layout">';
    $body .= '<fieldset><legend>Galerijas izskats</legend>';
    $body .= '<label>Galerijas paraksts (virs vāka)<input name="gallery_byline" required value="'
        . efpic_admin_esc((string) ($settings['gallery_byline'] ?? '')) . '" placeholder="Gallery by EdgarsFoto"></label>';
    $body .= '<p class="muted">Parādās visu galeriju sākuma ekrānā, piem. «Gallery by EdgarsFoto». Pamatkrāsu katra galerija nosaka pati (adminā vai klienta panelī).</p>';
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
    $body .= '<p class="muted">Attiecas uz visām EdgarsFoto tēmām: atstarpe starp bildēm un malu atkāpes režģī.</p>';
    $body .= '</fieldset></div></form>';

    efpic_admin_layout(
        'Iestatījumi',
        $body,
        'settings',
        'Iestatījumi',
        'Globālie iestatījumi visām publiskajām galerijām (paraksts, režģa atstarpes).',
        $config
    );
}

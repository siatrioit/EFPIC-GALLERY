<?php

declare(strict_types=1);

require_once __DIR__ . '/gallery_access.php';
require_once __DIR__ . '/failiem_client.php';

function efpic_client_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function efpic_client_icon(string $name): string
{
    $icons = [
        'share' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.59 13.51l6.83 3.98M15.41 6.51l-6.82 3.98"/></svg>',
        'download' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
        'close' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
        'chev-left' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>',
        'chev-right' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>',
        'zip' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
    ];

    return $icons[$name] ?? '';
}

function efpic_client_effective_theme(array $meta): string
{
    $t = (string) ($meta['client_theme'] ?? '');
    if ($t !== '') {
        return $t;
    }

    return (string) ($meta['theme'] ?? 'classic');
}

function efpic_client_share_modal(string $title): string
{
    $t = efpic_client_esc($title);
    $html = '<div class="modal-backdrop" id="shareModal" hidden role="dialog" aria-labelledby="shareModalTitle">';
    $html .= '<div class="modal"><button type="button" class="icon-btn modal-close" data-share-close aria-label="Aizvērt">';
    $html .= efpic_client_icon('close') . '</button>';
    $html .= '<h2 id="shareModalTitle">' . $t . '</h2><div class="share-row">';
    $html .= '<a class="share-item share-email" href="#" data-share-mail><span>@</span><span>E-pasts</span></a>';
    $html .= '<a class="share-item share-whatsapp" href="#" data-share-whatsapp><span>W</span><span>WhatsApp</span></a>';
    $html .= '<a class="share-item share-sms" href="#" data-share-sms><span>S</span><span>SMS</span></a>';
    $html .= '<button type="button" class="share-item share-copy" data-share-copy><span>&#128279;</span><span>Kopēt saiti</span></button>';
    $html .= '</div><p class="share-copied" id="shareCopied"></p></div></div>';

    return $html;
}

function efpic_client_download_modal(): string
{
    $html = '<div class="modal-backdrop" id="downloadModal" hidden role="dialog" aria-labelledby="downloadModalTitle">';
    $html .= '<div class="modal"><button type="button" class="icon-btn modal-close" data-dl-close aria-label="Aizvērt">';
    $html .= efpic_client_icon('close') . '</button>';
    $html .= '<h2 id="downloadModalTitle">Lejupielādes izmērs</h2>';
    $html .= '<div class="dl-size-row">';
    $html .= '<a class="btn primary dl-size-btn" href="#" data-dl-size="web">Web (ātrs)</a>';
    $html .= '<a class="btn dl-size-btn" href="#" data-dl-size="full">Pilns izmērs</a>';
    $html .= '</div></div></div>';

    return $html;
}

function efpic_client_topbar(string $title, string $rightHtml, string $extraClass = ''): string
{
    $cls = 'topbar' . ($extraClass !== '' ? ' ' . efpic_client_esc($extraClass) : '');

    return '<header class="' . $cls . '"><h1 class="topbar-title">' . efpic_client_esc($title)
        . '</h1>' . $rightHtml . '</header>';
}

function efpic_client_navigable_images(array $meta, array $ctx): array
{
    $out = [];
    foreach (efpic_sort_images_for_display($meta) as $img) {
        if (!is_array($img)) {
            continue;
        }
        $tok = (string) ($img['token'] ?? '');
        if ($tok === '' || !efpic_image_visible_to_viewer($img, $meta, $ctx)) {
            continue;
        }
        if (!efpic_can_view_image_file($meta, $tok)) {
            continue;
        }
        $out[] = $img;
    }

    return $out;
}

function efpic_client_html(
    string $title,
    string $body,
    array $config,
    string $pageClass = '',
    string $shareUrl = '',
    array $extraScriptVars = [],
    ?array $meta = null,
): void {
    $base = efpic_base_url($config);
    if ($shareUrl === '') {
        $shareUrl = $base;
        if (!empty($_SERVER['REQUEST_URI'])) {
            $shareUrl .= parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '';
        }
    }
    $class = $pageClass !== '' ? ' class="' . efpic_client_esc($pageClass) . '"' : '';

    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="lv"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . efpic_client_esc($title) . '</title>';
    echo '<link rel="stylesheet" href="' . efpic_client_esc($base . '/client/assets/client.css') . '">';
    if ($meta !== null) {
        $accent = efpic_client_hero_accent_color($meta);
        $heroText = efpic_client_hero_text_color($accent);
        $pageBg = efpic_client_page_bg_color($config, $meta);
        $gaps = efpic_client_gallery_feed_gaps($config);
        echo '<style>:root{--hero-accent:' . efpic_client_esc($accent) . ';--hero-text:' . efpic_client_esc($heroText)
            . ';--page-bg:' . efpic_client_esc($pageBg)
            . ';--pic-feed-gap:' . (int) $gaps['mobile'] . 'px'
            . ';--pic-feed-gap-tablet:' . (int) $gaps['tablet'] . 'px'
            . ';--pic-feed-gap-desktop:' . (int) $gaps['desktop'] . 'px;}</style>';
    }
    echo '</head><body' . $class . '>';
    echo $body;
    echo '<script>window.EFPIC_SHARE_URL=' . json_encode($shareUrl, JSON_UNESCAPED_SLASHES) . ';';
    echo 'window.EFPIC_SHARE_TITLE=' . json_encode($title, JSON_UNESCAPED_UNICODE) . ';';
    foreach ($extraScriptVars as $k => $v) {
        echo 'window.' . $k . '=' . json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';';
    }
    echo '</script>';
    echo '<script src="' . efpic_client_esc($base . '/client/assets/client.js') . '" defer></script>';
    echo '</body></html>';
    exit;
}

function efpic_client_render_cover(array $config, array $meta, array $images, string $theme = ''): string
{
    $name = (string) ($meta['name'] ?? '');
    $dateRaw = (string) ($meta['event_date'] ?? '');
    $theme = $theme !== '' ? $theme : efpic_client_effective_theme($meta);
    $isPicTime = $theme === 'pic-time';
    $coverTok = efpic_resolve_gallery_cover_token($meta, $images);
    $imgUrl = '';
    if ($coverTok !== '') {
        foreach ($images as $img) {
            if (is_array($img) && ($img['token'] ?? '') === $coverTok) {
                $imgUrl = efpic_client_media_url($config, $img, 'web', 1400);
                break;
            }
        }
        if ($imgUrl === '') {
            $imgUrl = efpic_client_media_url_for_token($config, $meta, $coverTok, 'web', 1400);
        }
    }

    if ($isPicTime) {
        $byline = efpic_client_gallery_byline($config);
        $date = efpic_client_format_event_date($dateRaw);
        $html = '<section class="gallery-intro" id="galleryHero">';
        $html .= '<p class="gallery-intro-byline">' . efpic_client_esc($byline) . '</p>';
        $html .= '<div class="gallery-intro-head">';
        $html .= '<figure class="gallery-intro-figure">';
        if ($imgUrl !== '') {
            $html .= '<img class="gallery-intro-photo" src="' . efpic_client_esc($imgUrl) . '" alt="">';
        }
        if ($date !== '') {
            $html .= '<figcaption class="gallery-intro-date">' . efpic_client_esc($date) . '</figcaption>';
        }
        $html .= '</figure></div>';
        $html .= '<h1 class="gallery-intro-title">' . efpic_client_esc($name) . '</h1>';
        $html .= '</section>';

        return $html;
    }

    $html = '<section class="gallery-cover">';
    if ($imgUrl !== '') {
        $html .= '<img class="gallery-cover-img" src="' . efpic_client_esc($imgUrl) . '" alt="">';
    }
    $html .= '<div class="gallery-cover-text"><h2>' . efpic_client_esc($name) . '</h2>';
    if ($dateRaw !== '') {
        $html .= '<p class="gallery-cover-date">' . efpic_client_esc($dateRaw) . '</p>';
    }
    $html .= '</div></section>';

    return $html;
}

function efpic_client_render_gallery_grid(array $config, array $meta, array $images, string $theme = ''): string
{
    if ($images === []) {
        return '<p class="feed-empty">Vēl nav bilžu.</p>';
    }

    $theme = $theme !== '' ? $theme : efpic_client_effective_theme($meta);
    if ($theme === 'pic-time') {
        $html = '<div class="pic-feed" data-masonry-gallery data-justified-gallery>';
        foreach ($images as $img) {
            if (!is_array($img)) {
                continue;
            }
            $tok = (string) ($img['token'] ?? '');
            if ($tok === '') {
                continue;
            }
            $imgUrl = efpic_client_media_url($config, $img, 'web', 1600);
            $pageUrl = efpic_image_view_url($config, $tok);
            $html .= '<a class="pic-feed-item" id="pic-' . efpic_client_esc($tok) . '" data-token="' . efpic_client_esc($tok) . '" href="'
                . efpic_client_esc($pageUrl) . '">';
            $html .= '<img src="' . efpic_client_esc($imgUrl) . '" alt="" loading="lazy"></a>';
        }
        $html .= '</div>';

        return $html;
    }

    $scenes = $meta['scenes'] ?? [];
    if (!is_array($scenes) || $scenes === []) {
        $scenes = [['id' => 'main', 'title' => 'Galerija', 'sort' => 1]];
    }

    usort($scenes, static fn ($a, $b) => ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0)));

    $byScene = [];
    foreach ($images as $img) {
        $sid = (string) ($img['scene_id'] ?? 'main');
        $byScene[$sid][] = $img;
    }

    $html = '';
    foreach ($scenes as $scene) {
        if (!is_array($scene)) {
            continue;
        }
        $sid = (string) ($scene['id'] ?? 'main');
        $sceneImages = $byScene[$sid] ?? [];
        if ($sceneImages === []) {
            continue;
        }
        $title = (string) ($scene['title'] ?? $sid);
        $html .= '<section class="scene-block"><h2 class="scene-title">' . efpic_client_esc($title) . '</h2>';
        $html .= '<div class="grid">';
        foreach ($sceneImages as $img) {
            $tok = (string) ($img['token'] ?? '');
            $imgUrl = efpic_client_media_url($config, $img, 'web');
            $pageUrl = efpic_image_view_url($config, $tok);
            $html .= '<a class="grid-card" href="' . efpic_client_esc($pageUrl) . '">';
            $html .= '<img src="' . efpic_client_esc($imgUrl) . '" alt="" loading="lazy"></a>';
        }
        $html .= '</div></section>';
    }

    return $html;
}

function efpic_handle_client_gallery(array $config, string $galleryToken, string $method): void
{
    $found = efpic_find_gallery_by_token($config, $galleryToken);
    if ($found === null) {
        efpic_client_html('Nav atrasts', '<p class="feed-empty err">Galerija nav atrasta.</p>', $config, 'page-auth');
    }

    $meta = $found['meta'];
    $slug = $found['slug'];
    efpic_ensure_gallery_indexed($config, $slug, $meta);
    $name = (string) ($meta['name'] ?? $slug);

    if (efpic_gallery_expired($meta)) {
        efpic_client_html($name, '<p class="feed-empty err">Galerijas derīguma termiņš ir beidzies.</p>', $config, 'page-auth');
    }

    if ($method === 'POST' && isset($_POST['gallery_password'])) {
        if (efpic_verify_gallery_password($meta, (string) $_POST['gallery_password'])) {
            efpic_set_gallery_session_unlocked($galleryToken);
            $guest = trim((string) ($_GET['g'] ?? ''));
            header('Location: ' . efpic_gallery_view_url($config, $galleryToken, $guest !== '' ? $guest : null));
            exit;
        }
    }

    if (efpic_gallery_has_password($meta) && !efpic_gallery_session_unlocked($galleryToken)) {
        $body = '<main class="page-auth"><div class="auth-card"><h1>' . efpic_client_esc($name) . '</h1>';
        $body .= '<p class="muted">Ievadi galerijas paroli.</p><form method="post" class="stack">';
        $body .= '<label>Parole<input type="password" name="gallery_password" required autofocus></label>';
        $body .= '<button type="submit" class="btn primary">Atvērt</button></form></div></main>';
        efpic_client_html($name, $body, $config, 'page-auth', efpic_gallery_view_url($config, $galleryToken));
    }

    efpic_client_session_start();
    unset($_SESSION['efpic_single_entry']);
    efpic_record_gallery_view($config, $slug, $meta);

    $ctx = efpic_viewer_context($config, $meta);
    $images = efpic_client_navigable_images($meta, $ctx);
    $theme = efpic_client_effective_theme($meta);
    $galleryUrl = efpic_gallery_view_url($config, $galleryToken, $ctx['guest_token'] !== '' ? $ctx['guest_token'] : null);

    $right = '<div class="topbar-actions"><button type="button" class="icon-btn" data-share-open aria-label="Dalīties">';
    $right .= efpic_client_icon('share') . '</button></div>';

    $isPicTime = $theme === 'pic-time';
    $body = '';
    if ($isPicTime) {
        $body .= efpic_client_render_cover($config, $meta, $images, $theme);
        $body .= efpic_client_topbar($name, $right, 'topbar-floating');
    } else {
        $body .= efpic_client_topbar($name, $right);
        $body .= efpic_client_render_cover($config, $meta, $images, $theme);
    }

    $failiemParent = (string) ($meta['failiem']['folder_parent_hash'] ?? '');
    $failiemHint = '';
    if ($failiemParent !== '' && efpic_is_delivery_gallery($meta)) {
        $searchUrl = 'https://failiem.lv/u/' . rawurlencode($failiemParent);
        $failiemHint = '<p class="gallery-ai-hint"><a href="' . efpic_client_esc($searchUrl) . '" target="_blank" rel="noopener">Meklēt bildes Failiem.lv (sejas, atslēgvārdi)</a></p>';
    }

    if (!$isPicTime && $failiemHint !== '') {
        $body .= $failiemHint;
    }

    if (efpic_is_delivery_gallery($meta) || in_array($theme, ['masonry', 'dark', 'pic-time'], true)) {
        $body .= '<main class="gallery-main">' . efpic_client_render_gallery_grid($config, $meta, $images, $theme) . '</main>';
    } else {
        $body .= '<main class="feed">';
        foreach ($images as $img) {
            $tok = (string) ($img['token'] ?? '');
            $body .= '<a class="feed-card" href="' . efpic_client_esc(efpic_image_view_url($config, $tok)) . '">';
            $body .= '<img src="' . efpic_client_esc(efpic_client_media_url($config, $img, 'web')) . '" alt="" loading="lazy"></a>';
        }
        $body .= '</main>';
    }

    if ($isPicTime && $failiemHint !== '') {
        $body .= $failiemHint;
    }

    $body .= '<section class="downloads" id="downloads"><h2>Lejupielādes</h2><div class="download-row">';
    $body .= '<a class="download-btn" href="' . efpic_client_esc($galleryUrl . '/download.zip?size=web') . '">';
    $body .= efpic_client_icon('zip') . ' Visas (web)</a>';
    if (efpic_can_download_size($meta, $ctx, 'full')) {
        $body .= '<a class="download-btn" href="' . efpic_client_esc($galleryUrl . '/download.zip?size=full') . '">';
        $body .= efpic_client_icon('zip') . ' Visas (pilns)</a>';
    }
    $body .= '</div></section>';

    $body .= efpic_client_share_modal($name);
    if ($isPicTime) {
        $body .= '<nav class="gallery-float-bar" aria-label="Galerijas darbības">';
        $body .= '<button type="button" class="float-btn" data-share-open aria-label="Dalīties">';
        $body .= efpic_client_icon('share') . '<span>Dalīties</span></button>';
        $body .= '<a class="float-btn" href="#downloads" aria-label="Lejupielādes">';
        $body .= efpic_client_icon('download') . '<span>Lejupielādēt</span></a></nav>';
    }
    $pageClass = 'page-gallery theme-' . preg_replace('/[^a-z0-9-]/', '', $theme);
    efpic_client_html($name, $body, $config, $pageClass, $galleryUrl, [
        'EFPIC_GALLERY_TOKEN' => $galleryToken,
    ], $meta);
}

function efpic_client_render_pic_time_viewer(
    array $config,
    array $meta,
    string $imageToken,
    string $mediaUrl,
    string $galleryUrl,
    string $closeUrl,
    array $navImages,
    int $index,
    int $total,
    string $prevUrl,
    string $nextUrl,
): string {
    $name = (string) ($meta['name'] ?? '');
    $html = '<div class="pt-viewer">';
    $html .= '<header class="pt-viewer-bar">';
    $html .= '<a class="pt-viewer-back" href="' . efpic_client_esc($closeUrl) . '" aria-label="Atpakaļ">';
    $html .= efpic_client_icon('chev-left') . '</a>';
    $html .= '<span class="pt-viewer-title">' . efpic_client_esc($name) . '</span>';
    $html .= '<div class="pt-viewer-actions">';
    $html .= '<button type="button" class="icon-btn" data-dl-open aria-label="Lejupielādēt">';
    $html .= efpic_client_icon('download') . '</button>';
    $html .= '<button type="button" class="icon-btn" data-share-open aria-label="Dalīties">';
    $html .= efpic_client_icon('share') . '</button>';
    $html .= '<a class="icon-btn" href="' . efpic_client_esc($closeUrl) . '" aria-label="Aizvērt">';
    $html .= efpic_client_icon('close') . '</a></div></header>';
    $html .= '<div class="pt-viewer-stage" data-viewer-stage>';
    if ($prevUrl !== '') {
        $html .= '<a class="pt-viewer-zone prev" href="' . efpic_client_esc($prevUrl) . '" aria-label="Iepriekšējā">';
        $html .= efpic_client_icon('chev-left') . '</a>';
    }
    $html .= '<figure class="pt-viewer-figure"><img src="' . efpic_client_esc($mediaUrl) . '" alt=""></figure>';
    if ($nextUrl !== '') {
        $html .= '<a class="pt-viewer-zone next" href="' . efpic_client_esc($nextUrl) . '" aria-label="Nākamā">';
        $html .= efpic_client_icon('chev-right') . '</a>';
    }
    $html .= '</div>';
    if ($total > 1) {
        $html .= '<p class="pt-viewer-count">' . ($index + 1) . ' / ' . $total . '</p>';
    }
    $html .= '</div>';

    return $html;
}

function efpic_handle_client_image(array $config, string $imageToken, string $method): void
{
    $found = efpic_find_image_by_token($config, $imageToken);
    if ($found === null) {
        efpic_client_html('Nav atrasts', '<p class="feed-empty err">Bilde nav atrasta.</p>', $config, 'page-auth');
    }

    $meta = $found['meta'];
    $gt = (string) ($meta['gallery_token'] ?? '');
    $name = (string) ($meta['name'] ?? '');
    $canBrowseGallery = empty($meta['restrict_gallery_from_single_link']);
    $ctx = efpic_viewer_context($config, $meta);
    $galleryUrl = efpic_gallery_view_url($config, $gt, $ctx['guest_token'] !== '' ? $ctx['guest_token'] : null);
    $mediaUrl = efpic_client_media_url_for_token($config, $meta, $imageToken, 'web', 1920);
    $pageUrl = efpic_image_view_url($config, $imageToken);
    $theme = efpic_client_effective_theme($meta);

    efpic_client_session_start();
    if (!efpic_gallery_session_unlocked($gt)) {
        $_SESSION['efpic_single_entry'] = $imageToken;
    }

    $navImages = efpic_client_navigable_images($meta, $ctx);
    $index = 0;
    foreach ($navImages as $i => $img) {
        if (($img['token'] ?? '') === $imageToken) {
            $index = $i;
            break;
        }
    }
    $total = count($navImages);
    $prevUrl = $index > 0 ? efpic_image_view_url($config, (string) ($navImages[$index - 1]['token'] ?? '')) : '';
    $nextUrl = $index < $total - 1 ? efpic_image_view_url($config, (string) ($navImages[$index + 1]['token'] ?? '')) : '';

    $closeUrl = $canBrowseGallery ? ($galleryUrl . efpic_gallery_image_focus_hash($imageToken)) : $pageUrl;

    if ($theme === 'pic-time') {
        $body = efpic_client_render_pic_time_viewer(
            $config,
            $meta,
            $imageToken,
            $mediaUrl,
            $galleryUrl,
            $closeUrl,
            $navImages,
            $index,
            $total,
            $prevUrl,
            $nextUrl,
        );
        $body .= efpic_client_share_modal($name);
        $body .= efpic_client_download_modal();
        efpic_client_html($name, $body, $config, 'page-viewer theme-pic-time', $pageUrl, [
            'EFPIC_IMAGE_TOKEN' => $imageToken,
            'EFPIC_DOWNLOAD_BASE' => efpic_base_url($config) . '/v/i/' . rawurlencode($imageToken) . '/download',
            'EFPIC_VIEWER_PREV' => $prevUrl,
            'EFPIC_VIEWER_NEXT' => $nextUrl,
            'EFPIC_GALLERY_RETURN' => $closeUrl,
        ], $meta);

        return;
    }

    $actions = '<div class="topbar-actions">';
    $actions .= '<button type="button" class="icon-btn" data-dl-open data-image-token="' . efpic_client_esc($imageToken) . '" aria-label="Lejupielādēt">';
    $actions .= efpic_client_icon('download') . '</button>';
    $actions .= '<button type="button" class="icon-btn" data-share-open aria-label="Dalīties">' . efpic_client_icon('share') . '</button>';
    $actions .= '<a class="icon-btn" href="' . efpic_client_esc($closeUrl) . '" aria-label="Aizvērt">' . efpic_client_icon('close') . '</a></div>';

    $body = efpic_client_topbar($name, $actions);
    $body .= '<div class="viewer-wrap"><div class="viewer">';
    $prevClass = 'viewer-nav prev' . ($prevUrl === '' ? ' is-disabled' : '');
    $body .= '<a class="' . $prevClass . '" href="' . ($prevUrl !== '' ? efpic_client_esc($prevUrl) : '#') . '">' . efpic_client_icon('chev-left') . '</a>';
    $body .= '<figure class="viewer-stage"><img src="' . efpic_client_esc($mediaUrl) . '" alt="">';
    if ($total > 1) {
        $body .= '<span class="viewer-count">' . ($index + 1) . '/' . $total . '</span>';
    }
    $body .= '</figure>';
    $nextClass = 'viewer-nav next' . ($nextUrl === '' ? ' is-disabled' : '');
    $body .= '<a class="' . $nextClass . '" href="' . ($nextUrl !== '' ? efpic_client_esc($nextUrl) : '#') . '">' . efpic_client_icon('chev-right') . '</a></div></div>';

    if ($total > 1) {
        $body .= '<nav class="viewer-thumbs" aria-label="Sīktēli">';
        foreach ($navImages as $img) {
            $tok = (string) ($img['token'] ?? '');
            $active = $tok === $imageToken ? ' is-active' : '';
            $body .= '<a class="viewer-thumb' . $active . '" href="' . efpic_client_esc(efpic_image_view_url($config, $tok)) . '">';
            $body .= '<img src="' . efpic_client_esc(efpic_client_media_url($config, $img, 'web')) . '" alt=""></a>';
        }
        $body .= '</nav>';
    }

    $body .= efpic_client_share_modal($name);
    $body .= efpic_client_download_modal();
    efpic_client_html($name, $body, $config, 'page-viewer theme-' . preg_replace('/[^a-z0-9-]/', '', $theme), $pageUrl, [
        'EFPIC_IMAGE_TOKEN' => $imageToken,
        'EFPIC_DOWNLOAD_BASE' => efpic_base_url($config) . '/v/i/' . rawurlencode($imageToken) . '/download',
    ], $meta);
}

function efpic_handle_client_media(array $config, string $imageToken): void
{
    $found = efpic_find_image_by_token($config, $imageToken);
    if ($found === null) {
        http_response_code(404);
        exit;
    }

    $meta = $found['meta'];
    if (!efpic_can_view_image_file($meta, $imageToken)) {
        http_response_code(403);
        exit;
    }

    $size = strtolower((string) ($_GET['size'] ?? 'web'));
    if (!in_array($size, ['web', 'full'], true)) {
        $size = 'web';
    }

    $img = $found['image'] ?? [];
    $hash = efpic_delivery_file_hash(is_array($img) ? $img : [], $size);
    if ($hash !== '') {
        $thumb = $size === 'web' || isset($_GET['w']);
        $w = (int) ($_GET['w'] ?? 720);
        efpic_failiem_redirect_media($config, $hash, $thumb, $w > 0 ? $w : 720);
    }

    $path = $found['path'] ?? '';
    if ($path === '' || !is_file($path)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=3600');
    readfile($path);
    exit;
}

function efpic_can_view_image_file(array $meta, string $imageToken): bool
{
    if (efpic_admin_session_active()) {
        return true;
    }
    if (!efpic_gallery_has_password($meta)) {
        return true;
    }
    $gt = (string) ($meta['gallery_token'] ?? '');
    if (efpic_gallery_session_unlocked($gt)) {
        return true;
    }
    efpic_client_session_start();

    return ($_SESSION['efpic_single_entry'] ?? '') === $imageToken;
}

function efpic_handle_client_image_download(array $config, string $imageToken): void
{
    $found = efpic_find_image_by_token($config, $imageToken);
    if ($found === null) {
        http_response_code(404);
        exit;
    }
    if (!efpic_can_view_image_file($found['meta'], $imageToken)) {
        http_response_code(403);
        exit;
    }

    $size = strtolower((string) ($_GET['size'] ?? 'full'));
    if (!in_array($size, ['web', 'full'], true)) {
        $size = 'full';
    }

    $meta = $found['meta'];
    $ctx = efpic_viewer_context($config, $meta);
    if (!efpic_can_download_size($meta, $ctx, $size)) {
        http_response_code(403);
        exit;
    }

    $img = $found['image'] ?? [];
    $hash = efpic_delivery_file_hash(is_array($img) ? $img : [], $size);
    if ($hash !== '') {
        $name = is_array($img) ? (string) ($img['basename'] ?? 'image.jpg') : 'image.jpg';
        header('Location: ' . efpic_failiem_download_url($config, $hash), true, 302);
        header('Content-Disposition: attachment; filename="' . basename($name) . '"');
        exit;
    }

    $path = $found['path'] ?? '';
    if ($path === '' || !is_file($path)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Content-Disposition: attachment; filename="' . basename((string) ($found['file'] ?? 'image.jpg')) . '"');
    readfile($path);
    exit;
}

function efpic_handle_client_gallery_zip(array $config, string $galleryToken): void
{
    $found = efpic_find_gallery_by_token($config, $galleryToken);
    if ($found === null) {
        http_response_code(404);
        exit;
    }
    $meta = $found['meta'];
    if (efpic_gallery_has_password($meta) && !efpic_gallery_session_unlocked($galleryToken)) {
        http_response_code(403);
        exit;
    }

    $ctx = efpic_viewer_context($config, $meta);
    $size = strtolower((string) ($_GET['size'] ?? 'web'));
    if (!in_array($size, ['web', 'full'], true)) {
        $size = 'web';
    }
    if (!efpic_can_download_size($meta, $ctx, $size)) {
        http_response_code(403);
        exit;
    }

    $images = efpic_client_navigable_images($meta, $ctx);
    if ($images === []) {
        http_response_code(404);
        exit;
    }

    if (!class_exists('ZipArchive')) {
        http_response_code(501);
        echo 'ZIP nav pieejams serverī';
        exit;
    }

    $zipPath = sys_get_temp_dir() . '/efpic_' . bin2hex(random_bytes(8)) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        exit;
    }

    if (efpic_is_delivery_gallery($meta)) {
        foreach ($images as $img) {
            $hash = efpic_delivery_file_hash($img, $size);
            if ($hash === '') {
                continue;
            }
            $url = efpic_failiem_download_url($config, $hash);
            $data = @file_get_contents($url);
            if ($data === false) {
                continue;
            }
            $name = (string) ($img['basename'] ?? $hash . '.jpg');
            $zip->addFromString(basename($name), $data);
        }
    } else {
        $dir = $found['dir'];
        foreach ($images as $img) {
            $file = (string) ($img['file'] ?? '');
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if ($file !== '' && is_file($path)) {
                $zip->addFile($path, $file);
            }
        }
    }

    $zip->close();
    $slug = $found['slug'];
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $slug . '-' . $size . '.zip"');
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

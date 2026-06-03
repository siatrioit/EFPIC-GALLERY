<?php

declare(strict_types=1);

require_once __DIR__ . '/gallery_access.php';
require_once __DIR__ . '/failiem_client.php';
require_once __DIR__ . '/zip_build.php';
require_once __DIR__ . '/gallery_assets.php';

function efpic_client_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function efpic_client_color_field(string $name, string $label, string $value): string
{
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
        $value = '#ffffff';
    }
    $value = strtolower($value);

    return '<label class="portal-color-field">' . efpic_client_esc($label)
        . '<span class="portal-color-control">'
        . '<span class="portal-color-swatch" style="background-color:' . efpic_client_esc($value) . ';" aria-hidden="true"></span>'
        . '<input type="color" class="portal-color-input" name="' . efpic_client_esc($name) . '" value="' . efpic_client_esc($value) . '">'
        . '<code class="portal-color-value">' . efpic_client_esc($value) . '</code>'
        . '</span></label>';
}

function efpic_client_icon(string $name): string
{
    $icons = [
        'share' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.59 13.51l6.83 3.98M15.41 6.51l-6.82 3.98"/></svg>',
        'download' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
        'close' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
        'chev-left' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>',
        'chev-right' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>',
        'chev-down' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>',
        'heart' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z"/></svg>',
        'heart-fill' => '<svg viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z"/></svg>',
        'pick' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8 12l2.5 2.5L16 9"/></svg>',
        'pick-empty' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/></svg>',
        'zip' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
    ];

    return $icons[$name] ?? '';
}

function efpic_client_effective_theme(array $meta): string
{
    return efpic_gallery_effective_theme($meta);
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
    $html .= '<a class="btn primary dl-size-btn" href="#" data-dl-size="web">WEB</a>';
    $html .= '<a class="btn dl-size-btn" href="#" data-dl-size="full">PRINT</a>';
    $html .= '</div></div></div>';

    return $html;
}

function efpic_client_zip_progress_modal(): string
{
    return '<div class="modal-backdrop" id="zipProgressModal" hidden role="dialog" aria-labelledby="zipProgressTitle" aria-busy="true">'
        . '<div class="modal modal--zip-progress">'
        . '<div class="zip-progress-spinner" id="zipProgressSpinner" aria-hidden="true"></div>'
        . '<h2 id="zipProgressTitle">Sagatavo lejupielādi…</h2>'
        . '<p class="muted" id="zipProgressHint">Lūdzu uzgaidiet…</p>'
        . '<button type="button" class="btn primary" id="zipProgressOkBtn" data-zip-progress-ok hidden>Labi</button>'
        . '</div></div>';
}

function efpic_client_gallery_download_modal(array $meta, array $ctx): string
{
    $canAllWeb = efpic_can_download_all_gallery_zip($meta, $ctx, 'web');
    $canAllFull = efpic_can_download_all_gallery_zip($meta, $ctx, 'full');

    if (!$canAllWeb && !$canAllFull) {
        return '';
    }

    $html = '<div class="modal-backdrop" id="galleryDownloadModal" hidden role="dialog" aria-labelledby="galleryDownloadModalTitle">';
    $html .= '<div class="modal"><button type="button" class="icon-btn modal-close" data-gdl-close aria-label="Aizvērt">';
    $html .= efpic_client_icon('close') . '</button>';
    $html .= '<h2 id="galleryDownloadModalTitle">Lejupielāde</h2>';
    $html .= '<p class="modal-kicker">Visas bildes</p>';
    $html .= '<div class="dl-size-row">';
    if ($canAllWeb) {
        $html .= '<a class="btn primary gdl-btn" href="#" data-gdl-scope="all" data-gdl-size="web">WEB</a>';
    }
    if ($canAllFull) {
        $html .= '<a class="btn gdl-btn" href="#" data-gdl-scope="all" data-gdl-size="full">PRINT</a>';
    }
    $html .= '</div></div></div>';

    return $html;
}

function efpic_client_collection_download_modal(array $meta, array $ctx, int $collectionCount): string
{
    $canColWeb = efpic_can_download_collection_zip($meta, $ctx, 'web');
    $canColFull = efpic_can_download_collection_zip($meta, $ctx, 'full');

    $colLabel = $collectionCount === 1
        ? 'Atlasītā (1) bilde'
        : ($collectionCount > 0
            ? 'Atlasītās (' . $collectionCount . ') bildes'
            : 'Atlasītās bildes');

    $html = '<div class="modal-backdrop" id="collectionDownloadModal" hidden role="dialog" aria-labelledby="collectionDownloadModalTitle">';
    $html .= '<div class="modal"><button type="button" class="icon-btn modal-close" data-cdl-close aria-label="Aizvērt">';
    $html .= efpic_client_icon('close') . '</button>';
    $html .= '<h2 id="collectionDownloadModalTitle">' . efpic_client_esc($colLabel) . '</h2>';
    $html .= '<div class="dl-size-row" id="collectionDownloadModalActions">';
    if ($canColWeb) {
        $html .= '<a class="btn primary cdl-btn" href="#" data-cdl-size="web">WEB</a>';
    }
    if ($canColFull) {
        $html .= '<a class="btn cdl-btn" href="#" data-cdl-size="full">PRINT</a>';
    }
    if (!$canColWeb && !$canColFull) {
        $html .= '<p class="muted">Lejupielāde šim izmēram nav atļauta.</p>';
    }
    $html .= '</div></div></div>';

    return $html;
}

/**
 * @param callable(string, string): void $add
 */
function efpic_zip_populate_delivery_images(
    callable $add,
    array $config,
    array $meta,
    array $images,
    string $sizeMode
): void {
    $sizeMode = strtolower($sizeMode);
    $sizes = $sizeMode === 'both' ? ['web', 'full'] : [$sizeMode];
    foreach ($images as $img) {
        if (!is_array($img)) {
            continue;
        }
        $baseName = basename((string) ($img['basename'] ?? 'image.jpg'));
        foreach ($sizes as $size) {
            $hash = efpic_delivery_file_hash($img, $size);
            if ($hash === '') {
                continue;
            }
            $data = efpic_failiem_fetch_file($config, $hash);
            if ($data === null) {
                continue;
            }
            $zipPath = $sizeMode === 'both'
                ? ($size === 'full' ? 'print/' : 'web/') . $baseName
                : $baseName;
            $add($zipPath, $data);
        }
    }
}

/**
 * @param callable(string, string): void $add
 */
function efpic_zip_populate_live_images(callable $add, string $dir, array $images): void
{
    foreach ($images as $img) {
        if (!is_array($img)) {
            continue;
        }
        $file = (string) ($img['file'] ?? '');
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        if ($file === '' || !is_file($path)) {
            continue;
        }
        $data = file_get_contents($path);
        if ($data === false) {
            continue;
        }
        $add($file, $data);
    }
}

function efpic_client_send_zip_download(string $zipPath, string $filename): void
{
    if (!is_file($zipPath) || filesize($zipPath) === 0) {
        @unlink($zipPath);
        http_response_code(500);
        echo 'Neizdevās izveidot ZIP arhīvu';
        exit;
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string) filesize($zipPath));
    readfile($zipPath);
    @unlink($zipPath);
    exit;
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
        $pageText = efpic_client_hero_text_color($pageBg);
        $gaps = efpic_client_gallery_feed_gaps($config);
        echo '<style>:root{--hero-accent:' . efpic_client_esc($accent) . ';--hero-text:' . efpic_client_esc($heroText)
            . ';--page-bg:' . efpic_client_esc($pageBg) . ';--page-text:' . efpic_client_esc($pageText)
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

/** @return array{viewer_key: string, collection: array<string, true>, base: string} */
function efpic_client_build_grid_context(array $config, string $galleryToken): array
{
    $viewerKey = efpic_viewer_like_key();
    $collection = [];
    foreach (efpic_client_collection_tokens($galleryToken) as $tok) {
        $collection[$tok] = true;
    }

    return [
        'viewer_key' => $viewerKey,
        'collection' => $collection,
        'base' => efpic_base_url($config),
    ];
}

function efpic_client_render_image_grid_actions(array $gridCtx, array $img): string
{
    $tok = (string) ($img['token'] ?? '');
    if ($tok === '') {
        return '';
    }
    $liked = efpic_image_liked_by_viewer($img, $gridCtx['viewer_key']);
    $inCollection = isset($gridCtx['collection'][$tok]);
    $likeUrl = $gridCtx['base'] . '/v/i/' . rawurlencode($tok) . '/like';

    $html = '<div class="grid-image-actions">';
    $html .= '<button type="button" class="grid-collection-btn' . ($inCollection ? ' is-selected' : '') . '" data-collection-toggle data-image-token="'
        . efpic_client_esc($tok) . '" aria-label="Izvēlēta lejupielādei" aria-pressed="' . ($inCollection ? 'true' : 'false') . '">';
    $html .= ($inCollection ? efpic_client_icon('pick') : efpic_client_icon('pick-empty')) . '</button>';
    $html .= '<button type="button" class="grid-like-btn' . ($liked ? ' is-liked' : '') . '" data-like-toggle data-like-url="'
        . efpic_client_esc($likeUrl) . '" aria-label="Patīk" aria-pressed="' . ($liked ? 'true' : 'false') . '">';
    $html .= ($liked ? efpic_client_icon('heart-fill') : efpic_client_icon('heart')) . '</button>';
    $html .= '</div>';

    return $html;
}

function efpic_client_render_collection_tray(string $galleryUrl, int $count, array $meta, array $ctx): string
{
    $hidden = $count > 0 ? '' : ' hidden';
    $html = '<aside class="collection-tray' . ($count > 0 ? ' is-visible' : '') . '" id="collectionTray"' . $hidden . ' aria-live="polite">';
    $html .= '<p class="collection-tray-text"><strong id="collectionTrayCount">' . $count . '</strong> '
        . ($count === 1 ? 'bilde izvēlēta' : 'bildes izvēlētas') . '</p>';
    $html .= '<div class="collection-tray-actions">';
    $html .= '<button type="button" class="btn" data-collection-clear>Notīrīt</button>';
    $html .= '<button type="button" class="btn primary" id="collectionDlBtn" data-collection-dl-open'
        . ($count > 0 ? '' : ' hidden') . '>Lejupielādēt</button>';
    $html .= '</div></aside>';

    return $html;
}

function efpic_client_render_pic_feed_items(array $config, array $images, array $gridCtx): string
{
    $html = '';
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
        $html .= '<div class="pic-feed-item" id="pic-' . efpic_client_esc($tok) . '" data-token="' . efpic_client_esc($tok) . '">';
        $html .= '<a class="pic-feed-link" href="' . efpic_client_esc($pageUrl) . '">';
        $html .= '<img src="' . efpic_client_esc($imgUrl) . '" alt="" loading="lazy"></a>';
        $html .= efpic_client_render_image_grid_actions($gridCtx, $img);
        $html .= '</div>';
    }

    return $html;
}

function efpic_client_render_scene_jump_nav(array $meta, array $images): string
{
    $visible = efpic_gallery_scenes_with_content($meta, $images);
    if (count($visible) < 2) {
        return '';
    }

    $html = '<nav class="gallery-scene-nav" aria-label="Galerijas sadaļas"><div class="gallery-scene-nav__inner">';
    foreach ($visible as $scene) {
        $anchor = efpic_scene_element_id($scene['id']);
        $html .= '<a class="gallery-scene-nav__link" href="#' . efpic_client_esc($anchor) . '">'
            . efpic_client_esc($scene['title']) . '</a>';
    }
    $html .= '</div></nav>';

    return $html;
}

function efpic_client_render_scene_next_button(string $nextAnchor, string $nextTitle): string
{
    return '<div class="gallery-scene-next-wrap">'
        . '<button type="button" class="gallery-scene-next" data-scene-target="#' . efpic_client_esc($nextAnchor) . '">'
        . '<span class="gallery-scene-next__text"><span class="gallery-scene-next__kicker">Nākamā sadaļa</span>'
        . '<span class="gallery-scene-next__title">' . efpic_client_esc($nextTitle) . '</span></span>'
        . efpic_client_icon('chev-down')
        . '</button></div>';
}

/** @param list<array{id: string, title: string}> $scenesWithImages */
function efpic_client_scene_next_button_for_index(array $scenesWithImages, int $index): string
{
    if (!isset($scenesWithImages[$index + 1])) {
        return '';
    }
    $next = $scenesWithImages[$index + 1];

    return efpic_client_render_scene_next_button(
        efpic_scene_element_id($next['id']),
        $next['title']
    );
}

function efpic_client_render_pic_time_scenes(array $config, array $meta, array $images, array $gridCtx): string
{
    $visible = efpic_gallery_scenes_with_content($meta, $images);

    $byScene = [];
    foreach ($images as $img) {
        if (!is_array($img)) {
            continue;
        }
        $sid = (string) ($img['scene_id'] ?? 'main');
        $byScene[$sid][] = $img;
    }

    $scenesWithImages = [];
    foreach ($visible as $scene) {
        if (($byScene[$scene['id']] ?? []) !== []) {
            $scenesWithImages[] = $scene;
        }
    }
    $multiScene = count($scenesWithImages) > 1;

    $html = '';
    foreach ($scenesWithImages as $i => $scene) {
        $sid = $scene['id'];
        $sceneImages = $byScene[$sid] ?? [];
        $title = $scene['title'];
        $anchor = efpic_scene_element_id($sid);
        if ($multiScene) {
            $html .= '<section id="' . efpic_client_esc($anchor) . '" class="scene-block scene-block--pic" data-scene-id="'
                . efpic_client_esc($sid) . '"><h2 class="scene-title">' . efpic_client_esc($title) . '</h2>';
        }
        $html .= '<div class="pic-feed" data-masonry-gallery data-justified-gallery>';
        $html .= efpic_client_render_pic_feed_items($config, $sceneImages, $gridCtx);
        $html .= '</div>';
        if ($multiScene) {
            $html .= efpic_client_scene_next_button_for_index($scenesWithImages, $i);
            $html .= '</section>';
        }
    }

    if ($html === '') {
        $html = '<div class="pic-feed" data-masonry-gallery data-justified-gallery>';
        $html .= efpic_client_render_pic_feed_items($config, $images, $gridCtx);
        $html .= '</div>';
    }

    return $html;
}

function efpic_client_render_gallery_videos(array $config, array $meta, array $ctx): string
{
    $videos = $meta['videos'] ?? [];
    if (!is_array($videos) || $videos === []) {
        return '';
    }
    usort($videos, static fn ($a, $b) => ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0)));
    $gt = (string) ($meta['gallery_token'] ?? '');
    $scenes = efpic_gallery_scene_options($meta);
    $sceneTitles = [];
    foreach ($scenes as $s) {
        $sceneTitles[$s['id']] = $s['title'];
    }

    $byScene = [];
    foreach ($videos as $video) {
        if (!is_array($video)) {
            continue;
        }
        $sid = (string) ($video['scene_id'] ?? 'main');
        $byScene[$sid][] = $video;
    }

    $html = '';
    foreach ($scenes as $scene) {
        $sid = $scene['id'];
        $list = $byScene[$sid] ?? [];
        if ($list === []) {
            continue;
        }
        $hasImages = false;
        foreach ($meta['images'] ?? [] as $img) {
            if (is_array($img) && (string) ($img['scene_id'] ?? 'main') === $sid) {
                $hasImages = true;
                break;
            }
        }
        $anchor = efpic_scene_element_id($sid);
        $idAttr = $hasImages ? '' : ' id="' . efpic_client_esc($anchor) . '"';
        $html .= '<section' . $idAttr . ' class="gallery-videos scene-block" data-scene-id="'
            . efpic_client_esc($sid) . '"><h2 class="scene-title">' . efpic_client_esc((string) ($sceneTitles[$sid] ?? 'Video')) . ' — video</h2>';
        foreach ($list as $video) {
            $title = trim((string) ($video['title'] ?? ''));
            if ($title !== '') {
                $html .= '<h3 class="gallery-video-title">' . efpic_client_esc($title) . '</h3>';
            }
            $kind = (string) ($video['kind'] ?? 'file');
            if ($kind === 'embed') {
                $provider = (string) ($video['provider'] ?? '');
                $embedId = (string) ($video['embed_id'] ?? '');
                if ($embedId === '') {
                    continue;
                }
                $src = $provider === 'vimeo'
                    ? 'https://player.vimeo.com/video/' . rawurlencode($embedId)
                    : 'https://www.youtube-nocookie.com/embed/' . rawurlencode($embedId);
                $html .= '<div class="gallery-video-embed"><iframe src="' . efpic_client_esc($src) . '" allowfullscreen loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe></div>';
            } else {
                $file = (string) ($video['file'] ?? '');
                if ($file === '') {
                    continue;
                }
                $url = efpic_gallery_asset_url($config, $gt, $file);
                $html .= '<div class="gallery-video-file"><video controls playsinline preload="metadata" src="' . efpic_client_esc($url) . '"></video></div>';
            }
        }
        $html .= '</section>';
    }

    return $html;
}

function efpic_client_render_slideshow_overlay(array $config, array $meta, array $ctx): string
{
    $resolved = efpic_resolve_public_slideshow($meta, $ctx, $config);
    if ($resolved === null) {
        return '';
    }
    $slideshow = $resolved['slideshow'];
    $favs = $resolved['images'];
    if ($favs === []) {
        return '';
    }
    $gt = (string) ($meta['gallery_token'] ?? '');
    $slides = [];
    foreach ($favs as $img) {
        $slides[] = efpic_client_media_url($config, $img, 'web', 1920);
    }
    $audioUrl = efpic_gallery_asset_url($config, $gt, $slideshow['audio_file']);

    $html = '<div id="efpic-slideshow" class="efpic-slideshow" hidden data-interval="' . (int) $slideshow['interval_sec'] . '" data-owner="' . efpic_client_esc($resolved['owner']) . '">';
    $html .= '<button type="button" class="efpic-slideshow-close" aria-label="Aizvērt">&times;</button>';
    $html .= '<div class="efpic-slideshow-stage"><img src="" alt=""></div>';
    $html .= '<audio class="efpic-slideshow-audio" src="' . efpic_client_esc($audioUrl) . '" loop></audio>';
    $json = json_encode($slides, JSON_UNESCAPED_SLASHES);
    $html .= '<script type="application/json" id="efpic-slideshow-data">' . str_replace('</', '<\/', (string) $json) . '</script>';
    $html .= '</div>';

    return $html;
}

function efpic_client_render_gallery_grid(array $config, array $meta, array $images, string $theme, array $gridCtx): string
{
    if ($images === []) {
        return '<p class="feed-empty">Vēl nav bilžu.</p>';
    }

    $theme = $theme !== '' ? $theme : efpic_client_effective_theme($meta);
    if ($theme === 'pic-time') {
        return efpic_client_render_pic_time_scenes($config, $meta, $images, $gridCtx);
    }

    $visible = efpic_gallery_scenes_with_content($meta, $images);

    $byScene = [];
    foreach ($images as $img) {
        if (!is_array($img)) {
            continue;
        }
        $sid = (string) ($img['scene_id'] ?? 'main');
        $byScene[$sid][] = $img;
    }

    $scenesWithImages = [];
    foreach ($visible as $scene) {
        if (($byScene[$scene['id']] ?? []) !== []) {
            $scenesWithImages[] = $scene;
        }
    }
    $multiScene = count($scenesWithImages) > 1;

    $html = '';
    foreach ($scenesWithImages as $i => $scene) {
        $sid = $scene['id'];
        $sceneImages = $byScene[$sid] ?? [];
        $title = $scene['title'];
        $anchor = efpic_scene_element_id($sid);
        $html .= '<section id="' . efpic_client_esc($anchor) . '" class="scene-block" data-scene-id="'
            . efpic_client_esc($sid) . '"><h2 class="scene-title">' . efpic_client_esc($title) . '</h2>';
        $html .= '<div class="grid">';
        foreach ($sceneImages as $img) {
            $tok = (string) ($img['token'] ?? '');
            $imgUrl = efpic_client_media_url($config, $img, 'web');
            $pageUrl = efpic_image_view_url($config, $tok);
            $html .= '<div class="grid-card" data-token="' . efpic_client_esc($tok) . '">';
            $html .= '<a class="grid-card-link" href="' . efpic_client_esc($pageUrl) . '">';
            $html .= '<img src="' . efpic_client_esc($imgUrl) . '" alt="" loading="lazy"></a>';
            $html .= efpic_client_render_image_grid_actions($gridCtx, $img);
            $html .= '</div>';
        }
        $html .= '</div>';
        if ($multiScene) {
            $html .= efpic_client_scene_next_button_for_index($scenesWithImages, $i);
        }
        $html .= '</section>';
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
    $gridCtx = efpic_client_build_grid_context($config, $galleryToken);
    $collectionCount = count($gridCtx['collection']);
    $galleryDlModal = efpic_client_gallery_download_modal($meta, $ctx);
    $hasGalleryDl = $galleryDlModal !== '';

    $right = '<div class="topbar-actions">';
    if ($hasGalleryDl) {
        $right .= '<button type="button" class="icon-btn" data-gallery-dl-open aria-label="Lejupielādēt">';
        $right .= efpic_client_icon('download') . '</button>';
    }
    $right .= '<button type="button" class="icon-btn" data-share-open aria-label="Dalīties">';
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

    if (is_array($ctx['share_image_tokens'] ?? null)) {
        $shareLabel = trim((string) ($ctx['share_label'] ?? ''));
        if ($shareLabel === '') {
            $shareLabel = 'Izlase';
        }
        $body .= '<p class="gallery-share-banner">Izlase «' . efpic_client_esc($shareLabel) . '» — '
            . count($images) . ' bildes</p>';
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
        $sceneNav = efpic_client_render_scene_jump_nav($meta, $images);
        $body .= '<main class="gallery-main">';
        if ($sceneNav !== '') {
            $body .= $sceneNav;
        }
        $body .= efpic_client_render_gallery_videos($config, $meta, $ctx);
        $body .= efpic_client_render_gallery_grid($config, $meta, $images, $theme, $gridCtx);
        $body .= '</main>';
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

    $body .= efpic_client_share_modal($name);
    $body .= $galleryDlModal;
    $body .= efpic_client_collection_download_modal($meta, $ctx, $collectionCount);
    $body .= efpic_client_zip_progress_modal();
    $body .= efpic_client_render_collection_tray($galleryUrl, $collectionCount, $meta, $ctx);
    if ($isPicTime) {
        $hasSlideshow = efpic_resolve_public_slideshow($meta, $ctx, $config) !== null;
        $body .= '<nav class="gallery-float-bar" aria-label="Galerijas darbības">';
        if ($hasSlideshow) {
            $body .= '<button type="button" class="float-btn" data-slideshow-open aria-label="Slideshow">';
            $body .= '<span>▶</span><span>Slideshow</span></button>';
        }
        $body .= '<button type="button" class="float-btn" data-share-open aria-label="Dalīties">';
        $body .= efpic_client_icon('share') . '<span>Dalīties</span></button>';
        if ($hasGalleryDl) {
            $body .= '<button type="button" class="float-btn" data-gallery-dl-open aria-label="Lejupielādēt">';
            $body .= efpic_client_icon('download') . '<span>Lejupielādēt</span></button>';
        }
        $body .= '</nav>';
        $body .= efpic_client_render_slideshow_overlay($config, $meta, $ctx);
    }
    $pageClass = 'page-gallery theme-' . preg_replace('/[^a-z0-9-]/', '', $theme);
    efpic_client_html($name, $body, $config, $pageClass, $galleryUrl, [
        'EFPIC_GALLERY_TOKEN' => $galleryToken,
        'EFPIC_GALLERY_DL_URL' => $galleryUrl,
        'EFPIC_COLLECTION_TOGGLE_URL' => $galleryUrl . '/collection/toggle',
        'EFPIC_COLLECTION_CLEAR_URL' => $galleryUrl . '/collection/clear',
        'EFPIC_COLLECTION_COUNT' => $collectionCount,
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
    bool $liked = false,
): string {
    $name = (string) ($meta['name'] ?? '');
    $html = '<div class="pt-viewer" data-image-token="' . efpic_client_esc($imageToken) . '">';
    $html .= '<header class="pt-viewer-bar">';
    $html .= '<a class="pt-viewer-back" href="' . efpic_client_esc($closeUrl) . '" aria-label="Atpakaļ">';
    $html .= efpic_client_icon('chev-left') . '</a>';
    $html .= '<span class="pt-viewer-title">' . efpic_client_esc($name) . '</span>';
    $html .= '<div class="pt-viewer-actions">';
    $html .= '<button type="button" class="icon-btn pt-like-btn' . ($liked ? ' is-liked' : '') . '" data-like-toggle aria-label="Patīk" aria-pressed="'
        . ($liked ? 'true' : 'false') . '">';
    $html .= ($liked ? efpic_client_icon('heart-fill') : efpic_client_icon('heart')) . '</button>';
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

    $viewerKey = efpic_viewer_like_key();
    $liked = false;
    foreach ($meta['images'] ?? [] as $imgRow) {
        if (is_array($imgRow) && ($imgRow['token'] ?? '') === $imageToken) {
            $liked = efpic_image_liked_by_viewer($imgRow, $viewerKey);
            break;
        }
    }

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
            $liked,
        );
        $body .= efpic_client_share_modal($name);
        $body .= efpic_client_download_modal();
        efpic_client_html($name, $body, $config, 'page-viewer theme-pic-time', $pageUrl, [
            'EFPIC_IMAGE_TOKEN' => $imageToken,
            'EFPIC_DOWNLOAD_BASE' => efpic_base_url($config) . '/v/i/' . rawurlencode($imageToken) . '/download',
            'EFPIC_VIEWER_PREV' => $prevUrl,
            'EFPIC_VIEWER_NEXT' => $nextUrl,
            'EFPIC_GALLERY_RETURN' => $closeUrl,
            'EFPIC_LIKE_URL' => efpic_base_url($config) . '/v/i/' . rawurlencode($imageToken) . '/like',
            'EFPIC_IMAGE_LIKED' => $liked ? '1' : '0',
        ], $meta);

        return;
    }

    $actions = '<div class="topbar-actions">';
    $actions .= '<button type="button" class="icon-btn pt-like-btn' . ($liked ? ' is-liked' : '') . '" data-like-toggle aria-label="Patīk" aria-pressed="'
        . ($liked ? 'true' : 'false') . '">';
    $actions .= ($liked ? efpic_client_icon('heart-fill') : efpic_client_icon('heart')) . '</button>';
    $actions .= '<button type="button" class="icon-btn" data-dl-open data-image-token="' . efpic_client_esc($imageToken) . '" aria-label="Lejupielādēt">';
    $actions .= efpic_client_icon('download') . '</button>';
    $actions .= '<button type="button" class="icon-btn" data-share-open aria-label="Dalīties">' . efpic_client_icon('share') . '</button>';
    $actions .= '<a class="icon-btn" href="' . efpic_client_esc($closeUrl) . '" aria-label="Aizvērt">' . efpic_client_icon('close') . '</a></div>';

    $body = efpic_client_topbar($name, $actions);
    $body .= '<div class="viewer-wrap" data-image-token="' . efpic_client_esc($imageToken) . '"><div class="viewer">';
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
        'EFPIC_LIKE_URL' => efpic_base_url($config) . '/v/i/' . rawurlencode($imageToken) . '/like',
        'EFPIC_IMAGE_LIKED' => $liked ? '1' : '0',
    ], $meta);
}

function efpic_handle_client_image_like(array $config, string $imageToken, string $method): void
{
    if ($method !== 'POST') {
        efpic_json_response(405, ['ok' => false, 'error' => 'method_not_allowed']);
    }

    $found = efpic_find_image_by_token($config, $imageToken);
    if ($found === null) {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_found']);
    }

    $meta = $found['meta'];
    $slug = $found['slug'];
    $gt = (string) ($meta['gallery_token'] ?? '');
    if (!efpic_can_view_image_file($meta, $imageToken)) {
        efpic_json_response(403, ['ok' => false, 'error' => 'forbidden']);
    }

    $imageIndex = null;
    foreach ($meta['images'] ?? [] as $i => $img) {
        if (is_array($img) && ($img['token'] ?? '') === $imageToken) {
            $imageIndex = $i;
            break;
        }
    }
    if ($imageIndex === null) {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_found']);
    }

    $viewerKey = efpic_viewer_like_key();
    $result = efpic_toggle_image_like($meta, $imageIndex, $viewerKey);
    efpic_save_gallery_meta($config, $slug, $meta);

    efpic_json_response(200, [
        'ok' => true,
        'liked' => $result['liked'],
        'count' => $result['count'],
    ]);
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

function efpic_client_zip_filename(string $slug, string $size, string $scope): string
{
    if ($scope === 'collection') {
        return $slug . '-izlase-' . $size . '.zip';
    }

    return $slug . '-' . $size . '.zip';
}

function efpic_client_zip_files_from_images(array $config, array $images, string $size): array
{
    $sizeKey = $size === 'full' ? 'full' : 'web';
    $files = [];
    foreach ($images as $img) {
        if (!is_array($img)) {
            continue;
        }
        $hash = efpic_delivery_file_hash($img, $sizeKey);
        if ($hash === '') {
            continue;
        }
        $files[] = [
            'url' => efpic_failiem_download_url($config, $hash),
            'name' => basename((string) ($img['basename'] ?? 'image.jpg')),
        ];
    }

    return $files;
}

function efpic_client_zip_prepare_response(
    array $config,
    array $found,
    array $meta,
    array $ctx,
    string $size,
    string $scope,
    string $galleryToken = ''
): void {
    $slug = (string) ($found['slug'] ?? 'galerija');
    $filename = efpic_client_zip_filename($slug, $size, $scope);

    if ($scope === 'collection') {
        $images = efpic_client_collection_images($meta, $ctx, $galleryToken);
        if ($images === []) {
            efpic_json_response(400, ['ok' => false, 'error' => 'Nav atlasītu bildes']);
        }
        $files = efpic_client_zip_files_from_images($config, $images, $size);
        if ($files === []) {
            efpic_json_response(500, ['ok' => false, 'error' => 'Nav lejupielādējamu failu']);
        }
        if (count($files) === 1) {
            efpic_json_response(200, [
                'ok' => true,
                'mode' => 'failiem',
                'url' => $files[0]['url'],
                'filename' => $files[0]['name'],
                'hint' => 'Lejupielāde sākas no Failiem.lv…',
            ]);
        }
        efpic_json_response(200, [
            'ok' => true,
            'mode' => 'server',
            'filename' => $filename,
            'hint' => 'Sagatavo ZIP ar ' . count($files) . ' atlasītajām bildēm…',
        ]);
    }

    if ($scope === 'all' && $size !== 'both' && efpic_can_failiem_folder_zip($meta, $ctx)) {
        $folderHash = efpic_failiem_delivery_folder_hash($meta, $size);
        if ($folderHash !== '') {
            efpic_json_response(200, [
                'ok' => true,
                'mode' => 'failiem',
                'url' => efpic_failiem_folder_zip_url($config, $folderHash),
                'filename' => $filename,
                'hint' => 'Lejupielāde sākas no Failiem.lv — lieliem arhīviem tas var aizņemt ilgi.',
            ]);
        }
    }

    if ($scope === 'all' && $size !== 'both') {
        $images = efpic_client_navigable_images($meta, $ctx);
        if ($images === []) {
            efpic_json_response(400, ['ok' => false, 'error' => 'Nav lejupielādējamu bildes']);
        }
        $files = efpic_client_zip_files_from_images($config, $images, $size);
        if ($files === []) {
            efpic_json_response(500, ['ok' => false, 'error' => 'Nav lejupielādējamu failu']);
        }
        if (count($files) === 1) {
            efpic_json_response(200, [
                'ok' => true,
                'mode' => 'failiem',
                'url' => $files[0]['url'],
                'filename' => $files[0]['name'],
                'hint' => 'Lejupielāde sākas no Failiem.lv…',
            ]);
        }
        efpic_json_response(200, [
            'ok' => true,
            'mode' => 'server',
            'filename' => $filename,
            'hint' => 'Sagatavo ZIP ar ' . count($files) . ' redzamajām bildēm…',
        ]);
    }

    efpic_json_response(500, [
        'ok' => false,
        'error' => 'Lejupielāde šim izmēram nav pieejama.',
    ]);
}

function efpic_client_build_delivery_zip(
    array $config,
    array $found,
    array $meta,
    array $images,
    string $size,
    string $filename
): void {
    if (!efpic_zip_supported()) {
        http_response_code(501);
        echo 'ZIP nav pieejams serverī';
        exit;
    }

    $zipPath = sys_get_temp_dir() . '/efpic_' . bin2hex(random_bytes(8)) . '.zip';
    $entryCount = 0;
    $ok = efpic_zip_build_file($zipPath, function (callable $add) use ($config, $meta, $images, $size, $found): void {
        if (efpic_is_delivery_gallery($meta)) {
            efpic_zip_populate_delivery_images($add, $config, $meta, $images, $size);
        } else {
            efpic_zip_populate_live_images($add, $found['dir'], $images);
        }
    }, $entryCount);

    if (!$ok || $entryCount === 0) {
        @unlink($zipPath);
        http_response_code(500);
        echo 'Neizdevās lejupielādēt bildes no Failiem (0 faili ZIP). Pārbaudi servera savienojumu ar failiem.lv.';
        exit;
    }

    efpic_client_send_zip_download($zipPath, $filename);
}

function efpic_handle_client_gallery_zip(array $config, string $galleryToken): void
{
    @set_time_limit(0);
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
    if (!in_array($size, ['web', 'full', 'both'], true)) {
        $size = 'web';
    }
    if (!efpic_can_download_all_gallery_zip($meta, $ctx, $size)) {
        http_response_code(403);
        exit;
    }

    $images = efpic_client_navigable_images($meta, $ctx);
    if ($images === []) {
        http_response_code(404);
        exit;
    }

    $filename = efpic_client_zip_filename($found['slug'], $size, 'all');

    if (isset($_GET['prepare']) && (string) $_GET['prepare'] === '1') {
        efpic_client_zip_prepare_response($config, $found, $meta, $ctx, $size, 'all', $galleryToken);
    }

    if ($size !== 'both' && efpic_can_failiem_folder_zip($meta, $ctx)) {
        $folderHash = efpic_failiem_delivery_folder_hash($meta, $size);
        if ($folderHash !== '') {
            header('Location: ' . efpic_failiem_folder_zip_url($config, $folderHash), true, 302);
            exit;
        }
    }

    efpic_client_build_delivery_zip($config, $found, $meta, $images, $size, $filename);
}

function efpic_client_collection_images(array $meta, array $ctx, string $galleryToken): array
{
    $wanted = array_flip(efpic_client_collection_tokens($galleryToken));
    if ($wanted === []) {
        return [];
    }
    $out = [];
    foreach (efpic_client_navigable_images($meta, $ctx) as $img) {
        $tok = (string) ($img['token'] ?? '');
        if ($tok !== '' && isset($wanted[$tok])) {
            $out[] = $img;
        }
    }

    return $out;
}

function efpic_handle_client_collection_toggle(array $config, string $galleryToken): void
{
    $found = efpic_find_gallery_by_token($config, $galleryToken);
    if ($found === null) {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_found']);
    }
    $meta = $found['meta'];
    if (efpic_gallery_has_password($meta) && !efpic_gallery_session_unlocked($galleryToken)) {
        efpic_json_response(403, ['ok' => false, 'error' => 'locked']);
    }

    $imageToken = trim((string) ($_POST['image_token'] ?? ''));
    if ($imageToken === '') {
        efpic_json_response(400, ['ok' => false, 'error' => 'missing_token']);
    }

    $ctx = efpic_viewer_context($config, $meta);
    $allowed = false;
    foreach (efpic_client_navigable_images($meta, $ctx) as $img) {
        if (is_array($img) && ($img['token'] ?? '') === $imageToken) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_visible']);
    }

    $result = efpic_client_collection_toggle($galleryToken, $imageToken);
    efpic_json_response(200, [
        'ok' => true,
        'in_collection' => $result['in_collection'],
        'count' => $result['count'],
    ]);
}

function efpic_handle_client_collection_clear(array $config, string $galleryToken): void
{
    $found = efpic_find_gallery_by_token($config, $galleryToken);
    if ($found === null) {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_found']);
    }
    $meta = $found['meta'];
    if (efpic_gallery_has_password($meta) && !efpic_gallery_session_unlocked($galleryToken)) {
        efpic_json_response(403, ['ok' => false, 'error' => 'locked']);
    }

    efpic_client_collection_clear($galleryToken);
    efpic_json_response(200, ['ok' => true, 'count' => 0]);
}

function efpic_handle_client_collection_zip(array $config, string $galleryToken): void
{
    @set_time_limit(0);
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
    if (!in_array($size, ['web', 'full', 'both'], true)) {
        $size = 'web';
    }
    if (!efpic_can_download_collection_zip($meta, $ctx, $size)) {
        http_response_code(403);
        exit;
    }

    $images = efpic_client_collection_images($meta, $ctx, $galleryToken);
    if ($images === []) {
        http_response_code(404);
        exit;
    }

    $filename = efpic_client_zip_filename($found['slug'], $size, 'collection');

    if (isset($_GET['prepare']) && (string) $_GET['prepare'] === '1') {
        efpic_client_zip_prepare_response($config, $found, $meta, $ctx, $size, 'collection', $galleryToken);
    }

    efpic_client_build_delivery_zip($config, $found, $meta, $images, $size, $filename);
}

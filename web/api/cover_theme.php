<?php

declare(strict_types=1);

/** @return array<string, string> */
function efpic_gallery_cover_layout_options(): array
{
    return [
        'left' => 'Pa kreisi',
        'center' => 'Centrēts',
        'right' => 'Pa labi',
        'full' => 'Pa visu vāku',
        'half-right' => 'Pus vāka — labā puse',
        'half-left' => 'Pus vāka — kreisā puse',
    ];
}

function efpic_is_valid_cover_layout(string $layout): bool
{
    return array_key_exists($layout, efpic_gallery_cover_layout_options());
}

function efpic_gallery_cover_layout(array $meta): string
{
    $layout = trim((string) ($meta['cover_layout'] ?? ''));
    if (efpic_is_valid_cover_layout($layout)) {
        return $layout;
    }

    return 'right';
}

function efpic_sanitize_cover_focal($value): float
{
    if (!is_numeric($value)) {
        return 50.0;
    }
    $n = (float) $value;

    return max(0.0, min(100.0, round($n, 2)));
}

/** @return array{x: float, y: float} */
function efpic_gallery_cover_focal(array $meta): array
{
    return [
        'x' => efpic_sanitize_cover_focal($meta['cover_focal_x'] ?? 50),
        'y' => efpic_sanitize_cover_focal($meta['cover_focal_y'] ?? 50),
    ];
}

function efpic_gallery_cover_object_position(array $meta): string
{
    $focal = efpic_gallery_cover_focal($meta);

    return $focal['x'] . '% ' . $focal['y'] . '%';
}

function efpic_gallery_cover_image_style_attr(array $meta): string
{
    return ' style="object-position:' . efpic_gallery_cover_object_position($meta) . ';"';
}

/** @return array<string, string> */
function efpic_gallery_mood_font_options(): array
{
    return [
        'serif' => 'Serif (Cormorant)',
        'sans' => 'Sans-serif',
    ];
}

function efpic_gallery_mood_font_family_key(array $meta): string
{
    $key = trim((string) ($meta['mood_font_family'] ?? 'serif'));

    return array_key_exists($key, efpic_gallery_mood_font_options()) ? $key : 'serif';
}

function efpic_gallery_mood_font_family_css(array $meta): string
{
    if (efpic_gallery_mood_font_family_key($meta) === 'sans') {
        return 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif';
    }

    return '"Cormorant Garamond", Georgia, "Times New Roman", serif';
}

/** @return array<string, string> */
function efpic_gallery_mood_date_format_options(): array
{
    return [
        'lv' => 'Latviešu (28. aprīlis 2026)',
        'en' => 'Angļu (April 28, 2026)',
        'iso' => 'ISO (2026-04-28)',
    ];
}

function efpic_gallery_mood_date_format_key(array $meta): string
{
    $key = trim((string) ($meta['mood_date_format'] ?? 'lv'));

    return array_key_exists($key, efpic_gallery_mood_date_format_options()) ? $key : 'lv';
}

/** @return array<string, string> */
function efpic_gallery_mood_title_size_options(): array
{
    return [
        'sm' => 'Mazs',
        'md' => 'Vidējs',
        'lg' => 'Liels',
    ];
}

/** @return array<string, string> */
function efpic_gallery_mood_date_size_options(): array
{
    return [
        'sm' => 'Mazs',
        'md' => 'Vidējs',
        'lg' => 'Liels',
    ];
}

function efpic_gallery_mood_title_size_key(array $meta): string
{
    $key = trim((string) ($meta['mood_title_font_size'] ?? 'md'));

    return array_key_exists($key, efpic_gallery_mood_title_size_options()) ? $key : 'md';
}

function efpic_gallery_mood_date_size_key(array $meta): string
{
    $key = trim((string) ($meta['mood_date_font_size'] ?? 'md'));

    return array_key_exists($key, efpic_gallery_mood_date_size_options()) ? $key : 'md';
}

function efpic_gallery_mood_title_size_css(array $meta): string
{
    return match (efpic_gallery_mood_title_size_key($meta)) {
        'sm' => 'clamp(1.35rem, 3.2vw, 1.85rem)',
        'lg' => 'clamp(2rem, 5.5vw, 3.2rem)',
        default => 'clamp(1.6rem, 4.5vw, 2.4rem)',
    };
}

function efpic_gallery_mood_date_size_css(array $meta): string
{
    return match (efpic_gallery_mood_date_size_key($meta)) {
        'sm' => '0.85rem',
        'lg' => '1.25rem',
        default => 'clamp(0.95rem, 2.5vw, 1.1rem)',
    };
}

function efpic_gallery_mood_intro_style_attr(array $meta): string
{
    $font = efpic_gallery_mood_font_family_css($meta);
    $title = efpic_gallery_mood_title_size_css($meta);
    $date = efpic_gallery_mood_date_size_css($meta);

    return ' style="--mood-font:' . $font . ';--mood-title-size:' . $title . ';--mood-date-size:' . $date . ';"';
}

function efpic_cover_theme_esc(string $s): string
{
    if (function_exists('efpic_admin_esc')) {
        return efpic_admin_esc($s);
    }
    if (function_exists('efpic_client_esc')) {
        return efpic_client_esc($s);
    }

    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function efpic_client_gallery_byline_display(array $config): string
{
    $line = efpic_client_gallery_byline($config);
    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper($line, 'UTF-8');
    }

    return strtoupper($line);
}

function efpic_client_format_event_date_mood(array $meta, string $date): string
{
    $date = trim($date);
    if ($date === '') {
        return '';
    }
    $ts = strtotime(substr($date, 0, 10));
    if ($ts === false) {
        return $date;
    }

    return match (efpic_gallery_mood_date_format_key($meta)) {
        'en' => date('F j, Y', $ts),
        'iso' => date('Y-m-d', $ts),
        default => efpic_client_format_event_date($date),
    };
}

function efpic_client_format_event_date_for_gallery(array $meta, string $date, string $theme = ''): string
{
    $theme = $theme !== '' ? efpic_normalize_gallery_theme($theme) : efpic_gallery_effective_theme($meta);
    if ($theme === 'efpic-mood') {
        return efpic_client_format_event_date_mood($meta, $date);
    }

    return efpic_client_format_event_date($date);
}

function efpic_apply_cover_theme_from_post(array &$meta): void
{
    $layout = trim((string) ($_POST['cover_layout'] ?? ''));
    if ($layout !== '' && efpic_is_valid_cover_layout($layout)) {
        $meta['cover_layout'] = $layout;
    }
    if (isset($_POST['cover_focal_x'])) {
        $meta['cover_focal_x'] = efpic_sanitize_cover_focal($_POST['cover_focal_x']);
    }
    if (isset($_POST['cover_focal_y'])) {
        $meta['cover_focal_y'] = efpic_sanitize_cover_focal($_POST['cover_focal_y']);
    }
}

function efpic_apply_mood_theme_from_post(array &$meta): void
{
    $themeFromPost = trim((string) ($_POST['theme'] ?? ''));
    $theme = $themeFromPost !== ''
        ? efpic_normalize_gallery_theme($themeFromPost)
        : efpic_gallery_effective_theme($meta);
    if ($theme !== 'efpic-mood') {
        return;
    }

    $font = trim((string) ($_POST['mood_font_family'] ?? ''));
    if ($font !== '' && array_key_exists($font, efpic_gallery_mood_font_options())) {
        $meta['mood_font_family'] = $font;
    }
    $dateFmt = trim((string) ($_POST['mood_date_format'] ?? ''));
    if ($dateFmt !== '' && array_key_exists($dateFmt, efpic_gallery_mood_date_format_options())) {
        $meta['mood_date_format'] = $dateFmt;
    }
    $titleSize = trim((string) ($_POST['mood_title_font_size'] ?? ''));
    if ($titleSize !== '' && array_key_exists($titleSize, efpic_gallery_mood_title_size_options())) {
        $meta['mood_title_font_size'] = $titleSize;
    }
    $dateSize = trim((string) ($_POST['mood_date_font_size'] ?? ''));
    if ($dateSize !== '' && array_key_exists($dateSize, efpic_gallery_mood_date_size_options())) {
        $meta['mood_date_font_size'] = $dateSize;
    }
}

function efpic_admin_cover_preview_url(array $config, array $meta): string
{
    $images = [];
    foreach ($meta['images'] ?? [] as $img) {
        if (is_array($img)) {
            $images[] = $img;
        }
    }
    if ($images === []) {
        return '';
    }
    $visibleTokens = [];
    foreach ($images as $img) {
        $tok = (string) ($img['token'] ?? '');
        if ($tok !== '') {
            $visibleTokens[] = $tok;
        }
    }
    if ($visibleTokens === []) {
        return '';
    }
    $coverTok = efpic_resolve_gallery_cover_admin_token($meta, $visibleTokens);
    if ($coverTok === '') {
        return '';
    }
    foreach ($images as $img) {
        if (is_array($img) && ($img['token'] ?? '') === $coverTok) {
            return efpic_client_media_url($config, $img, 'web', 1200);
        }
    }

    return efpic_client_media_url_for_token($config, $meta, $coverTok, 'web', 1200);
}

function efpic_render_cover_theme_controls(
    array $config,
    array $formMeta,
    string $theme,
    bool $standaloneForm,
    string $formAction = '',
): string {
    $theme = efpic_normalize_gallery_theme($theme);
    $isMood = $theme === 'efpic-mood';
    $layout = efpic_gallery_cover_layout($formMeta);
    $focal = efpic_gallery_cover_focal($formMeta);
    $coverUrl = efpic_admin_cover_preview_url($config, $formMeta);
    $hasCover = $coverUrl !== '';

    $html = '';
    if ($standaloneForm) {
        $html .= '<form method="post" class="admin-cover-theme-form" id="admin-cover-theme-form">';
        $html .= '<input type="hidden" name="portal_action" value="' . efpic_cover_theme_esc($formAction) . '">';
    }

    $html .= '<div class="admin-cover-theme" id="admin-cover-theme" data-theme="' . efpic_cover_theme_esc($theme) . '">';

    $html .= '<fieldset class="admin-cover-theme__block' . ($isMood ? ' is-disabled' : '') . '" id="admin-cover-layout-block"'
        . ($isMood ? ' disabled' : '') . '>';
    $html .= '<legend>Vāka bildes novietojums</legend>';
    if ($isMood) {
        $html .= '<p class="muted">Mood tēmā vāka novietojumu nevar mainīt — tiek rādīts centrēts burbulis.</p>';
    }
    $html .= '<div class="admin-cover-layout-grid" role="radiogroup" aria-label="Vāka bildes novietojums">';
    foreach (efpic_gallery_cover_layout_options() as $key => $label) {
        $checked = $key === $layout ? ' checked' : '';
        $disabled = $isMood ? ' disabled' : '';
        $html .= '<label class="admin-cover-layout-option"><input type="radio" name="cover_layout" value="'
            . efpic_cover_theme_esc($key) . '"' . $checked . $disabled . '><span>' . efpic_cover_theme_esc($label) . '</span></label>';
    }
    $html .= '</div>';

    $html .= '<input type="hidden" name="cover_focal_x" id="cover_focal_x" value="' . efpic_cover_theme_esc((string) $focal['x']) . '">';
    $html .= '<input type="hidden" name="cover_focal_y" id="cover_focal_y" value="' . efpic_cover_theme_esc((string) $focal['y']) . '">';

    if (!$hasCover) {
        $html .= '<p class="muted admin-cover-theme__hint" id="admin-cover-crop-hint">Izvēlieties vāka bildi cilnē <strong>Bildes</strong>, lai redzētu priekšskatījumu un pārkadrētu.</p>';
    }
    $cropHidden = $isMood || !$hasCover ? ' hidden' : '';
    $html .= '<div class="admin-cover-crop' . $cropHidden . '" id="admin-cover-crop" data-layout="' . efpic_cover_theme_esc($layout) . '">';
    $html .= '<p class="admin-cover-crop__label">Pārkadrējiet vāka bildi — velciet, lai mainītu redzamo apgabalu.</p>';
    $html .= '<div class="admin-cover-crop__frame" id="admin-cover-crop-frame" tabindex="0" role="img" aria-label="Vāka bildes pārkadrēšana">';
    $html .= '<img src="' . efpic_cover_theme_esc($coverUrl) . '" alt="" id="admin-cover-crop-img" draggable="false"'
        . ' style="object-position:' . efpic_cover_theme_esc(efpic_gallery_cover_object_position($formMeta)) . ';">';
    $html .= '</div></div>';
    $html .= '</fieldset>';

    $moodHidden = $isMood ? '' : ' hidden';
    $html .= '<fieldset class="admin-cover-theme__block admin-mood-theme' . $moodHidden . '" id="admin-mood-theme-block">';
    $html .= '<legend>Mood tēmas iestatījumi</legend>';
    $html .= '<div class="admin-form-layout admin-form-layout--basic">';
    $moodFont = efpic_gallery_mood_font_family_key($formMeta);
    $html .= '<label>Šrifts<select name="mood_font_family">';
    foreach (efpic_gallery_mood_font_options() as $k => $lbl) {
        $html .= '<option value="' . efpic_cover_theme_esc($k) . '"' . ($k === $moodFont ? ' selected' : '') . '>' . efpic_cover_theme_esc($lbl) . '</option>';
    }
    $html .= '</select></label>';
    $moodDateFmt = efpic_gallery_mood_date_format_key($formMeta);
    $html .= '<label>Datuma formāts<select name="mood_date_format">';
    foreach (efpic_gallery_mood_date_format_options() as $k => $lbl) {
        $html .= '<option value="' . efpic_cover_theme_esc($k) . '"' . ($k === $moodDateFmt ? ' selected' : '') . '>' . efpic_cover_theme_esc($lbl) . '</option>';
    }
    $html .= '</select></label>';
    $titleSize = efpic_gallery_mood_title_size_key($formMeta);
    $html .= '<label>Nosaukuma izmērs<select name="mood_title_font_size">';
    foreach (efpic_gallery_mood_title_size_options() as $k => $lbl) {
        $html .= '<option value="' . efpic_cover_theme_esc($k) . '"' . ($k === $titleSize ? ' selected' : '') . '>' . efpic_cover_theme_esc($lbl) . '</option>';
    }
    $html .= '</select></label>';
    $dateSize = efpic_gallery_mood_date_size_key($formMeta);
    $html .= '<label>Datuma izmērs<select name="mood_date_font_size">';
    foreach (efpic_gallery_mood_date_size_options() as $k => $lbl) {
        $html .= '<option value="' . efpic_cover_theme_esc($k) . '"' . ($k === $dateSize ? ' selected' : '') . '>' . efpic_cover_theme_esc($lbl) . '</option>';
    }
    $html .= '</select></label>';
    $html .= '</div></fieldset>';

    $html .= '</div>';

    if ($standaloneForm) {
        $html .= '<button type="submit" class="btn primary">Saglabāt vāka iestatījumus</button></form>';
    }

    return $html;
}

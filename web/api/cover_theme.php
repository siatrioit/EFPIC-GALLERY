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

function efpic_gallery_cover_image_style_attr(array $meta, bool $fill = false): string
{
    $style = 'object-position:' . efpic_gallery_cover_object_position($meta);
    if ($fill) {
        $style .= ';object-fit:cover';
    }

    return ' style="' . $style . '"';
}

/** @return array<string, array{label: string, group: string, family: string, google: string}> */
function efpic_gallery_intro_font_catalog(): array
{
    return [
        'cormorant' => [
            'label' => 'Cormorant Garamond',
            'group' => 'serif',
            'family' => "'Cormorant Garamond', Georgia, 'Times New Roman', serif",
            'google' => 'Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400',
        ],
        'lora' => [
            'label' => 'Lora',
            'group' => 'serif',
            'family' => "'Lora', Georgia, 'Times New Roman', serif",
            'google' => 'Lora:ital,wght@0,400;0,500;0,600;1,400',
        ],
        'libre-baskerville' => [
            'label' => 'Libre Baskerville',
            'group' => 'serif',
            'family' => "'Libre Baskerville', Georgia, 'Times New Roman', serif",
            'google' => 'Libre+Baskerville:ital,wght@0,400;0,700;1,400',
        ],
        'merriweather' => [
            'label' => 'Merriweather',
            'group' => 'serif',
            'family' => "'Merriweather', Georgia, 'Times New Roman', serif",
            'google' => 'Merriweather:ital,wght@0,300;0,400;0,700;1,400',
        ],
        'montserrat' => [
            'label' => 'Montserrat',
            'group' => 'sans',
            'family' => "'Montserrat', system-ui, -apple-system, 'Segoe UI', sans-serif",
            'google' => 'Montserrat:ital,wght@0,300;0,400;0,500;0,600;1,400',
        ],
        'open-sans' => [
            'label' => 'Open Sans',
            'group' => 'sans',
            'family' => "'Open Sans', system-ui, -apple-system, 'Segoe UI', sans-serif",
            'google' => 'Open+Sans:ital,wght@0,300;0,400;0,600;1,400',
        ],
        'raleway' => [
            'label' => 'Raleway',
            'group' => 'sans',
            'family' => "'Raleway', system-ui, -apple-system, 'Segoe UI', sans-serif",
            'google' => 'Raleway:ital,wght@0,300;0,400;0,500;0,600;1,400',
        ],
        'lato' => [
            'label' => 'Lato',
            'group' => 'sans',
            'family' => "'Lato', system-ui, -apple-system, 'Segoe UI', sans-serif",
            'google' => 'Lato:ital,wght@0,300;0,400;0,700;1,400',
        ],
    ];
}

function efpic_gallery_intro_font_key(string $key): string
{
    $key = trim($key);
    $legacy = [
        'serif' => 'cormorant',
        'sans' => 'montserrat',
        'playfair' => 'cormorant',
        'dm-serif' => 'cormorant',
        'cinzel' => 'cormorant',
        'poppins' => 'montserrat',
        'josefin' => 'montserrat',
        'dm-sans' => 'montserrat',
        'inter' => 'lato',
    ];
    if (isset($legacy[$key])) {
        $key = $legacy[$key];
    }

    return array_key_exists($key, efpic_gallery_intro_font_catalog()) ? $key : 'cormorant';
}

/** @return array<string, string> */
function efpic_gallery_mood_font_options(): array
{
    $out = [];
    foreach (efpic_gallery_intro_font_catalog() as $key => $font) {
        $out[$key] = (string) $font['label'];
    }

    return $out;
}

function efpic_gallery_mood_font_family_key(array $meta): string
{
    return efpic_gallery_intro_font_key((string) ($meta['mood_font_family'] ?? 'cormorant'));
}

function efpic_gallery_intro_style_var(string $value): string
{
    return str_replace('"', "'", $value);
}

function efpic_gallery_mood_font_family_css(array $meta): string
{
    $key = efpic_gallery_mood_font_family_key($meta);
    $catalog = efpic_gallery_intro_font_catalog();
    $family = (string) ($catalog[$key]['family'] ?? $catalog['cormorant']['family']);

    return efpic_gallery_intro_style_var($family);
}

/** @return list<string> */
function efpic_gallery_intro_fonts_google_urls(): array
{
    $families = [];
    foreach (efpic_gallery_intro_font_catalog() as $font) {
        $families[] = 'family=' . $font['google'];
    }
    $urls = [];
    foreach (array_chunk($families, 4) as $chunk) {
        $urls[] = 'https://fonts.googleapis.com/css2?' . implode('&', $chunk) . '&display=swap';
    }

    return $urls;
}

function efpic_gallery_intro_fonts_google_url(): string
{
    $urls = efpic_gallery_intro_fonts_google_urls();

    return $urls[0] ?? '';
}

function efpic_gallery_intro_font_google_url(string $key): string
{
    $key = efpic_gallery_intro_font_key($key);
    $catalog = efpic_gallery_intro_font_catalog();
    if (!isset($catalog[$key])) {
        return '';
    }

    return 'https://fonts.googleapis.com/css2?family=' . $catalog[$key]['google'] . '&display=swap';
}

function efpic_gallery_intro_font_google_url_for_meta(?array $meta): string
{
    if ($meta === null) {
        return efpic_gallery_intro_font_google_url('cormorant');
    }

    return efpic_gallery_intro_font_google_url(efpic_gallery_mood_font_family_key($meta));
}

function efpic_gallery_intro_fonts_google_link_tags(): string
{
    $html = '';
    foreach (efpic_gallery_intro_fonts_google_urls() as $url) {
        $html .= '<link href="' . efpic_cover_theme_esc($url) . '" rel="stylesheet">';
    }

    return $html;
}

/** @return array<string, string> */
function efpic_gallery_intro_fonts_family_map(): array
{
    $out = [];
    foreach (efpic_gallery_intro_font_catalog() as $key => $font) {
        $out[$key] = efpic_gallery_intro_style_var((string) $font['family']);
    }

    return $out;
}

/** @return array<string, string> */
function efpic_gallery_intro_fonts_group_map(): array
{
    $out = [];
    foreach (efpic_gallery_intro_font_catalog() as $key => $font) {
        $out[$key] = (string) ($font['group'] ?? 'serif');
    }

    return $out;
}

function efpic_gallery_intro_all_caps(array $meta): bool
{
    return !empty($meta['intro_all_caps']);
}

function efpic_gallery_intro_text_color(array $meta): string
{
    $color = trim((string) ($meta['intro_text_color'] ?? ''));
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1) {
        return strtolower($color);
    }

    return efpic_client_hero_text_color(efpic_client_hero_accent_color($meta));
}

function efpic_gallery_intro_extra_class(array $meta): string
{
    return efpic_gallery_intro_all_caps($meta) ? ' gallery-intro--all-caps' : '';
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

function efpic_gallery_intro_font_group(array $meta): string
{
    $key = efpic_gallery_mood_font_family_key($meta);
    $catalog = efpic_gallery_intro_font_catalog();

    return (string) ($catalog[$key]['group'] ?? 'serif');
}

function efpic_gallery_intro_title_weight_css(array $meta): string
{
    return efpic_gallery_intro_font_group($meta) === 'sans' ? '500' : '400';
}

function efpic_gallery_intro_title_tracking_css(array $meta): string
{
    return efpic_gallery_intro_font_group($meta) === 'sans' ? '0.06em' : '0.03em';
}

function efpic_gallery_intro_title_tracking_caps_css(array $meta): string
{
    return efpic_gallery_intro_font_group($meta) === 'sans' ? '0.1em' : '0.12em';
}

function efpic_gallery_mood_title_size_css(array $meta): string
{
    return match (efpic_gallery_mood_title_size_key($meta)) {
        'sm' => 'clamp(1.15rem, 3vw, 1.55rem)',
        'lg' => 'clamp(2.35rem, 6.5vw, 3.75rem)',
        default => 'clamp(1.65rem, 4.8vw, 2.5rem)',
    };
}

function efpic_gallery_mood_date_size_css(array $meta): string
{
    return match (efpic_gallery_mood_date_size_key($meta)) {
        'sm' => '0.78rem',
        'lg' => '1.35rem',
        default => 'clamp(0.95rem, 2.5vw, 1.1rem)',
    };
}

function efpic_gallery_intro_title_size_css(array $meta, string $theme = ''): string
{
    $theme = $theme !== '' ? efpic_normalize_gallery_theme($theme) : '';
    if ($theme === 'efpic-mood') {
        return efpic_gallery_mood_title_size_css($meta);
    }

    return match (efpic_gallery_mood_title_size_key($meta)) {
        'sm' => 'clamp(1.25rem, 3.5vw, 1.75rem)',
        'lg' => 'clamp(2.6rem, 7.5vw, 4.25rem)',
        default => 'clamp(1.85rem, 5.5vw, 3.15rem)',
    };
}

function efpic_gallery_intro_date_size_css(array $meta, string $theme = ''): string
{
    $theme = $theme !== '' ? efpic_normalize_gallery_theme($theme) : '';
    if ($theme === 'efpic-mood') {
        return efpic_gallery_mood_date_size_css($meta);
    }

    return match (efpic_gallery_mood_date_size_key($meta)) {
        'sm' => '0.8rem',
        'lg' => '1.35rem',
        default => '1.05rem',
    };
}

function efpic_gallery_intro_byline_size_css(array $meta): string
{
    return match (efpic_gallery_mood_date_size_key($meta)) {
        'sm' => '0.65rem',
        'lg' => '1.05rem',
        default => 'clamp(0.75rem, 2vw, 0.95rem)',
    };
}

function efpic_gallery_intro_typography_style_vars(array $meta, string $theme = ''): string
{
    $theme = $theme !== '' ? efpic_normalize_gallery_theme($theme) : efpic_gallery_effective_theme($meta);

    return '--intro-font:' . efpic_gallery_mood_font_family_css($meta)
        . ';--intro-title-size:' . efpic_gallery_intro_title_size_css($meta, $theme)
        . ';--intro-date-size:' . efpic_gallery_intro_date_size_css($meta, $theme)
        . ';--intro-byline-size:' . efpic_gallery_intro_byline_size_css($meta)
        . ';--intro-title-weight:' . efpic_gallery_intro_title_weight_css($meta)
        . ';--intro-title-tracking:' . efpic_gallery_intro_title_tracking_css($meta)
        . ';--intro-title-tracking-caps:' . efpic_gallery_intro_title_tracking_caps_css($meta)
        . ';--intro-text-color:' . efpic_gallery_intro_text_color($meta) . ';';
}

function efpic_gallery_intro_typography_style_attr(array $meta, string $theme = ''): string
{
    return ' style="' . efpic_gallery_intro_typography_style_vars($meta, $theme) . '"';
}

function efpic_gallery_mood_intro_style_attr(array $meta): string
{
    return efpic_gallery_intro_typography_style_attr($meta, 'efpic-mood');
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
    return efpic_client_format_event_date_mood($meta, $date);
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
    $meta['intro_all_caps'] = !empty($_POST['intro_all_caps']);
    $textColor = trim((string) ($_POST['intro_text_color'] ?? ''));
    if ($textColor !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $textColor) === 1) {
        $meta['intro_text_color'] = strtolower($textColor);
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

/** @return array<string, mixed> */
function efpic_cover_theme_preview_payload(array $config, array $formMeta, string $theme): array
{
    $theme = efpic_normalize_gallery_theme($theme);
    $focal = efpic_gallery_cover_focal($formMeta);
    $dateRaw = substr((string) ($formMeta['event_date'] ?? ''), 0, 10);

    return [
        'theme' => $theme,
        'name' => (string) ($formMeta['name'] ?? 'Galerija'),
        'dateRaw' => $dateRaw,
        'dateFormatted' => efpic_client_format_event_date_for_gallery($formMeta, $dateRaw, $theme),
        'byline' => efpic_client_gallery_byline_display($config),
        'coverUrl' => efpic_admin_cover_preview_url($config, $formMeta),
        'heroAccent' => efpic_client_hero_accent_color($formMeta),
        'layout' => efpic_gallery_cover_layout($formMeta),
        'focalX' => $focal['x'],
        'focalY' => $focal['y'],
        'fontFamily' => efpic_gallery_mood_font_family_key($formMeta),
        'dateFormat' => efpic_gallery_mood_date_format_key($formMeta),
        'titleSize' => efpic_gallery_mood_title_size_key($formMeta),
        'dateSize' => efpic_gallery_mood_date_size_key($formMeta),
        'allCaps' => efpic_gallery_intro_all_caps($formMeta),
        'introTextColor' => efpic_gallery_intro_text_color($formMeta),
        'fonts' => efpic_gallery_intro_fonts_family_map(),
    ];
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
    $previewJson = json_encode(
        efpic_cover_theme_preview_payload($config, $formMeta, $theme),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );

    $html = '';
    if ($standaloneForm) {
        $html .= '<form method="post" class="admin-cover-theme-form" id="admin-cover-theme-form">';
        $html .= '<input type="hidden" name="portal_action" value="' . efpic_cover_theme_esc($formAction) . '">';
    }

    $fontsJson = json_encode(efpic_gallery_intro_fonts_family_map(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $fontGroupsJson = json_encode(efpic_gallery_intro_fonts_group_map(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $fontUrlsJson = json_encode(efpic_gallery_intro_fonts_google_urls(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $clientCssUrl = efpic_asset_url('/client/assets/client.css');
    $html .= '<div class="admin-cover-theme" id="admin-cover-theme" data-theme="' . efpic_cover_theme_esc($theme) . '"'
        . ' data-preview="' . efpic_cover_theme_esc($previewJson !== false ? $previewJson : '{}') . '"'
        . ' data-fonts="' . efpic_cover_theme_esc($fontsJson !== false ? $fontsJson : '{}') . '"'
        . ' data-font-groups="' . efpic_cover_theme_esc($fontGroupsJson !== false ? $fontGroupsJson : '{}') . '"'
        . ' data-font-urls="' . efpic_cover_theme_esc($fontUrlsJson !== false ? $fontUrlsJson : '[]') . '"'
        . ' data-client-css="' . efpic_cover_theme_esc($clientCssUrl) . '">';

    $html .= '<fieldset class="admin-cover-theme__block' . ($isMood ? ' is-disabled' : '') . '" id="admin-cover-layout-block">';
    $html .= '<legend>Vāka bildes novietojums</legend>';
    if ($isMood) {
        $html .= '<p class="muted" id="admin-cover-layout-mood-note">Mood tēmā vāka novietojumu nevar mainīt — tiek rādīts centrēts burbulis.</p>';
    }
    $html .= '<div class="admin-cover-layout-grid" role="radiogroup" aria-label="Vāka bildes novietojums">';
    foreach (efpic_gallery_cover_layout_options() as $key => $label) {
        $checked = $key === $layout ? ' checked' : '';
        $html .= '<label class="admin-cover-layout-option"><input type="radio" name="cover_layout" value="'
            . efpic_cover_theme_esc($key) . '"' . $checked . '><span>' . efpic_cover_theme_esc($label) . '</span></label>';
    }
    $html .= '</div>';
    $html .= '<input type="hidden" name="cover_focal_x" id="cover_focal_x" value="' . efpic_cover_theme_esc((string) $focal['x']) . '">';
    $html .= '<input type="hidden" name="cover_focal_y" id="cover_focal_y" value="' . efpic_cover_theme_esc((string) $focal['y']) . '">';
    $html .= '</fieldset>';

    $html .= '<fieldset class="admin-cover-theme__block admin-intro-typography" id="admin-intro-typography-block">';
    $html .= '<legend>Vāka tipogrāfija</legend>';
    $html .= '<p class="muted">Attiecas uz visām tēmām — parakstu, nosaukumu un datumu sākuma ekrānā. Šrifts ar pilnu latviešu alfabēta atbalstu (ā, č, ē, ģ, ī, ķ, ļ, ņ, š, ū, ž).</p>';
    $html .= '<div class="admin-form-layout admin-form-layout--basic">';
    $moodFont = efpic_gallery_mood_font_family_key($formMeta);
    $html .= '<label>Šrifts<select name="mood_font_family" id="mood_font_family">';
    $groups = ['serif' => 'Serif', 'sans' => 'Sans-serif'];
    foreach ($groups as $groupKey => $groupLabel) {
        $html .= '<optgroup label="' . efpic_cover_theme_esc($groupLabel) . '">';
        foreach (efpic_gallery_intro_font_catalog() as $k => $font) {
            if (($font['group'] ?? '') !== $groupKey) {
                continue;
            }
            $html .= '<option value="' . efpic_cover_theme_esc($k) . '"' . ($k === $moodFont ? ' selected' : '') . '>'
                . efpic_cover_theme_esc((string) $font['label']) . '</option>';
        }
        $html .= '</optgroup>';
    }
    $html .= '</select></label>';
    $introTextColor = efpic_gallery_intro_text_color($formMeta);
    if (function_exists('efpic_admin_color_field')) {
        $html .= efpic_admin_color_field('intro_text_color', 'Teksta krāsa', $introTextColor);
    } elseif (function_exists('efpic_client_color_field')) {
        $html .= efpic_client_color_field('intro_text_color', 'Teksta krāsa', $introTextColor);
    }
    $allCaps = efpic_gallery_intro_all_caps($formMeta);
    $html .= '<label class="admin-check admin-fieldset-full"><input type="checkbox" name="intro_all_caps" id="intro_all_caps" value="1"'
        . ($allCaps ? ' checked' : '') . '> Nosaukums ar lielajiem burtiem</label>';
    $moodDateFmt = efpic_gallery_mood_date_format_key($formMeta);
    $html .= '<label>Datuma formāts<select name="mood_date_format" id="mood_date_format">';
    foreach (efpic_gallery_mood_date_format_options() as $k => $lbl) {
        $html .= '<option value="' . efpic_cover_theme_esc($k) . '"' . ($k === $moodDateFmt ? ' selected' : '') . '>' . efpic_cover_theme_esc($lbl) . '</option>';
    }
    $html .= '</select></label>';
    $titleSize = efpic_gallery_mood_title_size_key($formMeta);
    $html .= '<label>Nosaukuma izmērs<select name="mood_title_font_size" id="mood_title_font_size">';
    foreach (efpic_gallery_mood_title_size_options() as $k => $lbl) {
        $html .= '<option value="' . efpic_cover_theme_esc($k) . '"' . ($k === $titleSize ? ' selected' : '') . '>' . efpic_cover_theme_esc($lbl) . '</option>';
    }
    $html .= '</select></label>';
    $dateSize = efpic_gallery_mood_date_size_key($formMeta);
    $html .= '<label>Datuma izmērs<select name="mood_date_font_size" id="mood_date_font_size">';
    foreach (efpic_gallery_mood_date_size_options() as $k => $lbl) {
        $html .= '<option value="' . efpic_cover_theme_esc($k) . '"' . ($k === $dateSize ? ' selected' : '') . '>' . efpic_cover_theme_esc($lbl) . '</option>';
    }
    $html .= '</select></label>';
    $html .= '</div></fieldset>';

    $previewDevices = [
        ['id' => 'desktop', 'label' => 'WEB', 'width' => 1440, 'height' => 900],
        ['id' => 'tablet', 'label' => 'Planšete', 'width' => 768, 'height' => 1024],
        ['id' => 'phone', 'label' => 'Telefons', 'width' => 390, 'height' => 844],
    ];
    $html .= '<div class="admin-cover-live" id="admin-cover-live">';
    $html .= '<p class="admin-cover-live__heading">Priekšskatījums <span class="muted">(reāllaikā)</span></p>';
    $html .= '<div class="admin-cover-live-grid" id="admin-cover-live-grid">';
    foreach ($previewDevices as $device) {
        $html .= '<div class="admin-cover-live-device" data-device="' . efpic_cover_theme_esc($device['id']) . '"'
            . ' data-width="' . (int) $device['width'] . '" data-height="' . (int) $device['height'] . '">';
        $html .= '<p class="admin-cover-live-device__label">' . efpic_cover_theme_esc($device['label']) . '</p>';
        $html .= '<div class="admin-cover-live-device__shell">';
        $html .= '<div class="admin-cover-live-device__viewport">';
        $html .= '<iframe class="admin-cover-live-device__iframe" title="Priekšskatījums: '
            . efpic_cover_theme_esc($device['label']) . '" loading="lazy"></iframe>';
        $html .= '</div></div></div>';
    }
    $html .= '</div>';
    if (!$hasCover) {
        $html .= '<p class="muted admin-cover-theme__hint" id="admin-cover-crop-hint">Izvēlieties vāka bildi cilnē <strong>Bildes</strong>, lai redzētu bildi priekšskatījumā.</p>';
    }
    $cropHidden = $isMood || !$hasCover ? ' hidden' : '';
    $html .= '<div class="admin-cover-crop' . $cropHidden . '" id="admin-cover-crop" data-layout="' . efpic_cover_theme_esc($layout) . '">';
    $html .= '<p class="admin-cover-crop__label">Pārkadrējiet vāka bildi — velciet, lai mainītu redzamo apgabalu.</p>';
    $html .= '<div class="admin-cover-crop__frame" id="admin-cover-crop-frame" tabindex="0" role="img" aria-label="Vāka bildes pārkadrēšana">';
    $html .= '<img src="' . efpic_cover_theme_esc($coverUrl) . '" alt="" id="admin-cover-crop-img" draggable="false"'
        . ' style="object-position:' . efpic_cover_theme_esc(efpic_gallery_cover_object_position($formMeta)) . ';">';
    $html .= '</div></div>';
    $html .= '</div>';

    $html .= '</div>';

    if ($standaloneForm) {
        $html .= '<button type="submit" class="btn primary">Saglabāt vāka iestatījumus</button></form>';
    }

    return $html;
}

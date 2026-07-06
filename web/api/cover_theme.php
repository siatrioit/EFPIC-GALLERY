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

/** @return array<string, array{label: string, hero_accent: string, page_bg: string, intro_text_color: string}> */
function efpic_gallery_design_palette_catalog(): array
{
    return [
        'classic-warm' => [
            'label' => 'Silts klasiskais',
            'hero_accent' => '#9a9578',
            'page_bg' => '#f7f5f0',
            'intro_text_color' => '#1a1a1a',
        ],
        'minimal-light' => [
            'label' => 'Gaišs minimālisms',
            'hero_accent' => '#e8e6e1',
            'page_bg' => '#ffffff',
            'intro_text_color' => '#111111',
        ],
        'editorial-dark' => [
            'label' => 'Redakcionāls tumšs',
            'hero_accent' => '#1c1c1c',
            'page_bg' => '#0f0f0f',
            'intro_text_color' => '#f5f2eb',
        ],
        'forest-natural' => [
            'label' => 'Dabas zaļš',
            'hero_accent' => '#3d5c45',
            'page_bg' => '#eef3ee',
            'intro_text_color' => '#1a2e1f',
        ],
        'sandstone' => [
            'label' => 'Smilškrāsa',
            'hero_accent' => '#c4b59a',
            'page_bg' => '#faf8f4',
            'intro_text_color' => '#2c2418',
        ],
        'blush-romance' => [
            'label' => 'Blāvi rozā',
            'hero_accent' => '#e8d5d0',
            'page_bg' => '#fff9f7',
            'intro_text_color' => '#3d2c2a',
        ],
        'slate-cool' => [
            'label' => 'Vēsais pelēkzils',
            'hero_accent' => '#5c6b7a',
            'page_bg' => '#f4f6f8',
            'intro_text_color' => '#1a2332',
        ],
        'ink-contrast' => [
            'label' => 'Tintes kontrasts',
            'hero_accent' => '#0d0d0d',
            'page_bg' => '#ffffff',
            'intro_text_color' => '#ffffff',
        ],
        'champagne' => [
            'label' => 'Šampanietis',
            'hero_accent' => '#d4c4a8',
            'page_bg' => '#fdfbf7',
            'intro_text_color' => '#2a2218',
        ],
        'moody-blue' => [
            'label' => 'Dziļš zils',
            'hero_accent' => '#2a3d5c',
            'page_bg' => '#eef1f6',
            'intro_text_color' => '#e8ecf2',
        ],
    ];
}

function efpic_gallery_design_palette_key(array $meta): string
{
    $key = trim((string) ($meta['design_palette'] ?? ''));

    return array_key_exists($key, efpic_gallery_design_palette_catalog()) ? $key : '';
}

/** @return array<string, string> */
function efpic_gallery_cover_animation_options(): array
{
    return [
        'none' => 'Bez animācijas',
        'ken-burns' => 'Kinematogrāfisks (Ken Burns)',
        'zoom-in' => 'Lēns tuvinājums',
        'pan-left' => 'Lēna panorāma',
    ];
}

function efpic_gallery_cover_animation(array $meta): string
{
    $key = trim((string) ($meta['cover_animation'] ?? 'none'));

    return array_key_exists($key, efpic_gallery_cover_animation_options()) ? $key : 'none';
}

function efpic_gallery_cover_animation_class(array $meta): string
{
    $anim = efpic_gallery_cover_animation($meta);
    if ($anim === 'none') {
        return '';
    }

    return ' gallery-intro-cover-anim--' . preg_replace('/[^a-z0-9-]/', '', $anim);
}

function efpic_gallery_cover_media_type(array $meta): string
{
    return trim((string) ($meta['cover_media_type'] ?? 'image')) === 'video' ? 'video' : 'image';
}

function efpic_gallery_cover_video_id(array $meta): string
{
    return trim((string) ($meta['cover_video_id'] ?? ''));
}

/** @return array<string, mixed>|null */
function efpic_gallery_video_by_id(array $meta, string $id): ?array
{
    $id = trim($id);
    if ($id === '') {
        return null;
    }
    foreach ($meta['videos'] ?? [] as $video) {
        if (is_array($video) && (string) ($video['id'] ?? '') === $id) {
            return $video;
        }
    }

    return null;
}

function efpic_gallery_cover_video_url(array $config, array $meta, ?array $ctx = null): string
{
    if (efpic_gallery_cover_media_type($meta) !== 'video') {
        return '';
    }
    $video = efpic_gallery_video_by_id($meta, efpic_gallery_cover_video_id($meta));
    if ($video === null || (string) ($video['kind'] ?? '') !== 'file') {
        return '';
    }
    $file = trim((string) ($video['file'] ?? ''));
    if ($file === '') {
        return '';
    }
    $gt = (string) ($meta['gallery_token'] ?? '');
    $guestQ = is_array($ctx) ? trim((string) ($ctx['guest_token'] ?? '')) : '';

    return efpic_gallery_asset_url($config, $gt, $file, $guestQ !== '' ? $guestQ : null);
}

/** @return list<array{id: string, label: string, url: string}> */
function efpic_admin_cover_video_options(array $config, array $meta): array
{
    $gt = (string) ($meta['gallery_token'] ?? '');
    $out = [];
    foreach ($meta['videos'] ?? [] as $video) {
        if (!is_array($video)) {
            continue;
        }
        if ((string) ($video['kind'] ?? '') !== 'file') {
            continue;
        }
        $file = trim((string) ($video['file'] ?? ''));
        $id = trim((string) ($video['id'] ?? ''));
        if ($file === '' || $id === '') {
            continue;
        }
        $title = trim((string) ($video['title'] ?? ''));
        $out[] = [
            'id' => $id,
            'label' => $title !== '' ? $title : $file,
            'url' => efpic_gallery_asset_url($config, $gt, $file),
        ];
    }

    return $out;
}

/** @return list<string> */
function efpic_design_template_setting_keys(): array
{
    return [
        'hero_accent_color',
        'page_bg_color',
        'intro_text_color',
        'cover_style',
        'cover_layout',
        'cover_focal_x',
        'cover_focal_y',
        'mosaic_max_columns',
        'mood_font_family',
        'mood_date_format',
        'mood_title_font_size',
        'mood_date_font_size',
        'intro_all_caps',
        'design_palette',
        'cover_animation',
        'cover_media_type',
        'cover_from_favorites',
    ];
}

/** @return array<string, mixed> */
function efpic_design_template_extract_from_meta(array $meta): array
{
    $out = [];
    foreach (efpic_design_template_setting_keys() as $key) {
        if (array_key_exists($key, $meta)) {
            $out[$key] = $meta[$key];
        }
    }

    return $out;
}

/** @param array<string, mixed> $settings */
function efpic_design_template_apply_to_meta(array &$meta, array $settings): void
{
    foreach (efpic_design_template_setting_keys() as $key) {
        if (!array_key_exists($key, $settings)) {
            continue;
        }
        $meta[$key] = $settings[$key];
    }
    if (isset($meta['mosaic_max_columns'])) {
        $meta['mosaic_max_columns'] = efpic_sanitize_mosaic_max_columns($meta['mosaic_max_columns']);
    }
    if (isset($meta['cover_style'])) {
        $meta['cover_style'] = efpic_gallery_cover_style($meta);
    }
    $meta['theme'] = 'efpic-base';
}

/** @return list<array{id: string, name: string, settings: array<string, mixed>, created_at: string, updated_at: string}> */
function efpic_load_design_templates(array $config): array
{
    $settings = efpic_load_app_settings($config);
    $list = $settings['design_templates'] ?? [];
    if (!is_array($list)) {
        return [];
    }
    $out = [];
    foreach ($list as $item) {
        if (!is_array($item) || trim((string) ($item['id'] ?? '')) === '') {
            continue;
        }
        $out[] = $item;
    }

    return $out;
}

/** @param list<array<string, mixed>> $templates */
function efpic_save_design_templates(array $config, array $templates): void
{
    efpic_save_app_settings($config, ['design_templates' => array_values($templates)]);
}

/** @param array<string, mixed> $settings */
function efpic_design_template_save(array $config, string $name, array $settings): array
{
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('Šablona nosaukums obligāts');
    }
    $templates = efpic_load_design_templates($config);
    $entry = [
        'id' => bin2hex(random_bytes(8)),
        'name' => $name,
        'settings' => $settings,
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
    ];
    $templates[] = $entry;
    efpic_save_design_templates($config, $templates);

    return $entry;
}

function efpic_design_template_delete(array $config, string $id): bool
{
    $id = trim($id);
    if ($id === '') {
        return false;
    }
    $templates = efpic_load_design_templates($config);
    $next = array_values(array_filter($templates, static fn ($t) => is_array($t) && (string) ($t['id'] ?? '') !== $id));
    if (count($next) === count($templates)) {
        return false;
    }
    efpic_save_design_templates($config, $next);

    return true;
}

function efpic_render_design_palette_picker(array $formMeta): string
{
    $active = efpic_gallery_design_palette_key($formMeta);
    $html = '<div class="admin-design-palettes admin-fieldset-full" id="admin-design-palettes">';
    $html .= '<p class="admin-design-palettes__label">Krāsu palete</p>';
    $html .= '<input type="hidden" name="design_palette" id="design_palette" value="' . efpic_cover_theme_esc($active) . '">';
    $html .= '<div class="admin-design-palettes__grid" role="listbox" aria-label="Krāsu paletes">';
    foreach (efpic_gallery_design_palette_catalog() as $key => $palette) {
        $sel = $key === $active ? ' is-selected' : '';
        $html .= '<button type="button" class="admin-design-palette' . $sel . '" role="option"'
            . ' data-palette="' . efpic_cover_theme_esc($key) . '"'
            . ' data-hero="' . efpic_cover_theme_esc($palette['hero_accent']) . '"'
            . ' data-page="' . efpic_cover_theme_esc($palette['page_bg']) . '"'
            . ' data-text="' . efpic_cover_theme_esc($palette['intro_text_color']) . '"'
            . ' aria-selected="' . ($key === $active ? 'true' : 'false') . '">';
        $html .= '<span class="admin-design-palette__swatches" aria-hidden="true">';
        $html .= '<span style="background:' . efpic_cover_theme_esc($palette['hero_accent']) . '"></span>';
        $html .= '<span style="background:' . efpic_cover_theme_esc($palette['page_bg']) . '"></span>';
        $html .= '<span style="background:' . efpic_cover_theme_esc($palette['intro_text_color']) . '"></span>';
        $html .= '</span>';
        $html .= '<span class="admin-design-palette__name">' . efpic_cover_theme_esc($palette['label']) . '</span>';
        $html .= '</button>';
    }
    $html .= '</div>';
    $html .= '<p class="muted">Palete aizpilda vāka, fona un teksta krāsas. Pēc tam vari tās pielāgot manuāli.</p>';
    $html .= '</div>';

    return $html;
}

function efpic_render_design_template_controls(array $config, array $formMeta): string
{
    $templates = efpic_load_design_templates($config);
    $map = [];
    foreach ($templates as $tpl) {
        if (!is_array($tpl)) {
            continue;
        }
        $id = (string) ($tpl['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $map[$id] = [
            'name' => (string) ($tpl['name'] ?? ''),
            'settings' => is_array($tpl['settings'] ?? null) ? $tpl['settings'] : [],
        ];
    }
    $json = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $html = '<fieldset class="admin-fieldset-full admin-design-templates" id="admin-design-templates"'
        . ' data-templates="' . efpic_cover_theme_esc($json !== false ? $json : '{}') . '">';
    $html .= '<legend>Dizaina šabloni</legend>';
    $html .= '<p class="muted">Saglabā pašreizējo izskatu kā šablonu un atkārtoti lieto citās galerijās (bez vāka bildes/video).</p>';
    $html .= '<div class="admin-design-templates__row">';
    $html .= '<label>Lietot šablonu<select id="design_template_apply" name="design_template_apply">';
    $html .= '<option value="">— Izvēlēties —</option>';
    foreach ($templates as $tpl) {
        if (!is_array($tpl)) {
            continue;
        }
        $id = (string) ($tpl['id'] ?? '');
        $name = (string) ($tpl['name'] ?? '');
        if ($id === '' || $name === '') {
            continue;
        }
        $html .= '<option value="' . efpic_cover_theme_esc($id) . '">' . efpic_cover_theme_esc($name) . '</option>';
    }
    $html .= '</select></label>';
    $html .= '<button type="button" class="btn admin-btn-inline" id="design_template_apply_btn">Lietot</button>';
    $html .= '</div>';
    $html .= '<div class="admin-design-templates__row">';
    $html .= '<label>Jauns šablons<input type="text" name="design_template_name" id="design_template_name" placeholder="piem. Kāzas 2026"></label>';
    $html .= '<button type="submit" class="btn admin-btn-inline" name="design_template_save" value="1" formnovalidate>Saglabāt kā šablonu</button>';
    $html .= '</div>';
    if ($templates !== []) {
        $html .= '<div class="admin-design-templates__row">';
        $html .= '<label>Dzēst šablonu<select name="design_template_id" id="design_template_delete_select">';
        $html .= '<option value="">— Izvēlēties —</option>';
        foreach ($templates as $tpl) {
            if (!is_array($tpl)) {
                continue;
            }
            $id = (string) ($tpl['id'] ?? '');
            $name = (string) ($tpl['name'] ?? '');
            if ($id === '' || $name === '') {
                continue;
            }
            $html .= '<option value="' . efpic_cover_theme_esc($id) . '">' . efpic_cover_theme_esc($name) . '</option>';
        }
        $html .= '</select></label>';
        $html .= '<button type="submit" class="btn admin-btn-danger admin-btn-inline" name="design_template_delete" value="1" formnovalidate>Dzēst šablonu</button>';
        $html .= '</div>';
    }
    $html .= '</fieldset>';

    return $html;
}

/** @return array<string, array{label: string, settings: array<string, mixed>}> */
function efpic_gallery_design_presets(): array
{
    return [
        'modern' => [
            'label' => 'Modern',
            'settings' => [
                'cover_style' => 'standard',
                'cover_layout' => 'right',
                'mosaic_max_columns' => 4,
                'design_palette' => 'minimal-light',
                'hero_accent_color' => '#e8e6e1',
                'page_bg_color' => '#ffffff',
                'intro_text_color' => '#111111',
                'mood_font_family' => 'montserrat',
                'cover_animation' => 'none',
            ],
        ],
        'high-five' => [
            'label' => 'High Five',
            'settings' => [
                'cover_style' => 'standard',
                'cover_layout' => 'right',
                'mosaic_max_columns' => 5,
                'design_palette' => 'minimal-light',
                'hero_accent_color' => '#e8e6e1',
                'page_bg_color' => '#ffffff',
                'intro_text_color' => '#111111',
                'mood_font_family' => 'montserrat',
                'cover_animation' => 'none',
            ],
        ],
        'forest' => [
            'label' => 'Forest',
            'settings' => [
                'cover_style' => 'standard',
                'cover_layout' => 'right',
                'mosaic_max_columns' => 3,
                'design_palette' => 'forest-natural',
                'hero_accent_color' => '#3d5c45',
                'page_bg_color' => '#eef3ee',
                'intro_text_color' => '#1a2e1f',
                'mood_font_family' => 'lora',
                'cover_animation' => 'none',
            ],
        ],
        'mood' => [
            'label' => 'Mood',
            'settings' => [
                'cover_style' => 'mood-blob',
                'cover_layout' => 'center',
                'mosaic_max_columns' => 4,
                'design_palette' => 'editorial-dark',
                'hero_accent_color' => '#1c1c1c',
                'page_bg_color' => '#0f0f0f',
                'intro_text_color' => '#f5f2eb',
                'mood_font_family' => 'cormorant',
                'cover_animation' => 'ken-burns',
            ],
        ],
        'cinematic' => [
            'label' => 'Kinematogrāfisks',
            'settings' => [
                'cover_style' => 'cinematic-full',
                'cover_layout' => 'full',
                'mosaic_max_columns' => 4,
                'design_palette' => 'editorial-dark',
                'hero_accent_color' => '#1c1c1c',
                'page_bg_color' => '#0f0f0f',
                'intro_text_color' => '#f5f2eb',
                'mood_font_family' => 'cormorant',
                'cover_animation' => 'ken-burns',
            ],
        ],
    ];
}

function efpic_render_design_preset_picker(): string
{
    $presets = efpic_gallery_design_presets();
    $json = json_encode($presets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $html = '<div class="admin-design-presets admin-fieldset-full" id="admin-design-presets"'
        . ' data-presets="' . efpic_cover_theme_esc($json !== false ? $json : '{}') . '">';
    $html .= '<p class="admin-design-presets__label">Ātrais starts</p>';
    $html .= '<div class="admin-design-presets__grid">';
    foreach ($presets as $key => $preset) {
        $html .= '<button type="button" class="btn admin-btn-inline admin-design-preset" data-preset="'
            . efpic_cover_theme_esc($key) . '">' . efpic_cover_theme_esc((string) $preset['label']) . '</button>';
    }
    $html .= '</div>';
    $html .= '<p class="muted">Iestata vāka stilu, paleti, kolonnas un fontus. Pēc tam vari pielāgot katru daļu atsevišķi.</p>';
    $html .= '</div>';

    return $html;
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

function efpic_render_intro_all_caps_toggle(array $formMeta): string
{
    return efpic_render_admin_toggle('Nosaukums ar lielajiem burtiem', efpic_gallery_intro_all_caps($formMeta), [
        'name' => 'intro_all_caps',
        'id' => 'intro_all_caps',
    ]);
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

function efpic_gallery_intro_title_size_css(array $meta): string
{
    if (efpic_gallery_uses_mood_blob_cover($meta)) {
        return efpic_gallery_mood_title_size_css($meta);
    }

    return match (efpic_gallery_mood_title_size_key($meta)) {
        'sm' => 'clamp(1.25rem, 3.5vw, 1.75rem)',
        'lg' => 'clamp(2.6rem, 7.5vw, 4.25rem)',
        default => 'clamp(1.85rem, 5.5vw, 3.15rem)',
    };
}

function efpic_gallery_intro_date_size_css(array $meta): string
{
    if (efpic_gallery_uses_mood_blob_cover($meta)) {
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

function efpic_gallery_intro_typography_style_vars(array $meta): string
{
    return '--intro-font:' . efpic_gallery_mood_font_family_css($meta)
        . ';--intro-title-size:' . efpic_gallery_intro_title_size_css($meta)
        . ';--intro-date-size:' . efpic_gallery_intro_date_size_css($meta)
        . ';--intro-byline-size:' . efpic_gallery_intro_byline_size_css($meta)
        . ';--intro-title-weight:' . efpic_gallery_intro_title_weight_css($meta)
        . ';--intro-title-tracking:' . efpic_gallery_intro_title_tracking_css($meta)
        . ';--intro-title-tracking-caps:' . efpic_gallery_intro_title_tracking_caps_css($meta)
        . ';--intro-text-color:' . efpic_gallery_intro_text_color($meta) . ';';
}

function efpic_gallery_intro_typography_style_attr(array $meta): string
{
    return ' style="' . efpic_gallery_intro_typography_style_vars($meta) . '"';
}

function efpic_gallery_mood_intro_style_attr(array $meta): string
{
    return efpic_gallery_intro_typography_style_attr($meta);
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

function efpic_client_format_event_date_for_gallery(array $meta, string $date): string
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
    $palette = trim((string) ($_POST['design_palette'] ?? ''));
    if ($palette === '' || array_key_exists($palette, efpic_gallery_design_palette_catalog())) {
        $meta['design_palette'] = $palette;
    }
    $animation = trim((string) ($_POST['cover_animation'] ?? ''));
    if ($animation !== '' && array_key_exists($animation, efpic_gallery_cover_animation_options())) {
        $meta['cover_animation'] = $animation;
    }
    $coverStyle = trim((string) ($_POST['cover_style'] ?? ''));
    if ($coverStyle !== '' && array_key_exists($coverStyle, efpic_gallery_cover_style_options())) {
        $meta['cover_style'] = $coverStyle;
    }
    if (isset($_POST['mosaic_max_columns'])) {
        $meta['mosaic_max_columns'] = efpic_sanitize_mosaic_max_columns($_POST['mosaic_max_columns']);
    }
    $meta['theme'] = 'efpic-base';
}

function efpic_apply_cover_media_from_post(array &$meta): void
{
    $mediaType = trim((string) ($_POST['cover_media_type'] ?? ''));
    if ($mediaType === 'video' || $mediaType === 'image') {
        $meta['cover_media_type'] = $mediaType;
    }
    $videoId = trim((string) ($_POST['cover_video_id'] ?? ''));
    if ($videoId === '') {
        $meta['cover_video_id'] = null;
    } elseif (efpic_gallery_video_by_id($meta, $videoId) !== null) {
        $meta['cover_video_id'] = $videoId;
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

function efpic_gallery_social_cover_image_url(array $config, array $meta, ?array $ctx = null): string
{
    $images = [];
    foreach ($meta['images'] ?? [] as $img) {
        if (!is_array($img) || !empty($img['client_hidden'])) {
            continue;
        }
        $images[] = $img;
    }
    if ($images === []) {
        return '';
    }

    $coverTok = efpic_resolve_gallery_cover_token($meta, $images);
    if ($coverTok === '') {
        return '';
    }
    foreach ($images as $img) {
        if (($img['token'] ?? '') === $coverTok) {
            return efpic_client_media_url($config, $img, 'web', 1200, $ctx);
        }
    }

    return efpic_client_media_url_for_token($config, $meta, $coverTok, 'web', 1200, $ctx);
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
function efpic_cover_theme_preview_payload(array $config, array $formMeta): array
{
    $focal = efpic_gallery_cover_focal($formMeta);
    $dateRaw = substr((string) ($formMeta['event_date'] ?? ''), 0, 10);

    return [
        'coverStyle' => efpic_gallery_cover_style($formMeta),
        'mosaicMaxColumns' => efpic_gallery_mosaic_max_columns($formMeta),
        'name' => (string) ($formMeta['name'] ?? 'Galerija'),
        'dateRaw' => $dateRaw,
        'dateFormatted' => efpic_client_format_event_date_for_gallery($formMeta, $dateRaw),
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
        'designPalette' => efpic_gallery_design_palette_key($formMeta),
        'coverAnimation' => efpic_gallery_cover_animation($formMeta),
        'coverMediaType' => efpic_gallery_cover_media_type($formMeta),
        'coverVideoId' => efpic_gallery_cover_video_id($formMeta),
        'coverVideos' => efpic_admin_cover_video_options($config, $formMeta),
    ];
}

function efpic_render_cover_theme_controls(
    array $config,
    array $formMeta,
    bool $standaloneForm,
    string $formAction = '',
): string {
    $layoutLocked = efpic_gallery_cover_style_locks_layout($formMeta);
    $isMoodBlob = efpic_gallery_uses_mood_blob_cover($formMeta);
    $coverStyle = efpic_gallery_cover_style($formMeta);
    $mosaicCols = efpic_gallery_mosaic_max_columns($formMeta);
    $layout = efpic_gallery_cover_layout($formMeta);
    $focal = efpic_gallery_cover_focal($formMeta);
    $coverUrl = efpic_admin_cover_preview_url($config, $formMeta);
    $hasCover = $coverUrl !== '';
    $previewJson = json_encode(
        efpic_cover_theme_preview_payload($config, $formMeta),
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
    $html .= '<div class="admin-cover-theme" id="admin-cover-theme"'
        . ' data-preview="' . efpic_cover_theme_esc($previewJson !== false ? $previewJson : '{}') . '"'
        . ' data-fonts="' . efpic_cover_theme_esc($fontsJson !== false ? $fontsJson : '{}') . '"'
        . ' data-font-groups="' . efpic_cover_theme_esc($fontGroupsJson !== false ? $fontGroupsJson : '{}') . '"'
        . ' data-font-urls="' . efpic_cover_theme_esc($fontUrlsJson !== false ? $fontUrlsJson : '[]') . '"'
        . ' data-client-css="' . efpic_cover_theme_esc($clientCssUrl) . '">';

    $html .= '<div class="admin-form-layout admin-form-layout--basic admin-fieldset-full">';
    $html .= '<label>Vāka stils<select name="cover_style" id="cover_style">';
    foreach (efpic_gallery_cover_style_options() as $k => $lbl) {
        $html .= '<option value="' . efpic_cover_theme_esc($k) . '"' . ($k === $coverStyle ? ' selected' : '') . '>'
            . efpic_cover_theme_esc($lbl) . '</option>';
    }
    $html .= '</select></label>';
    $html .= '<label>Mozaīkas kolonnas (lielos ekrānos)<select name="mosaic_max_columns" id="mosaic_max_columns">';
    foreach (efpic_gallery_mosaic_max_column_options() as $k => $lbl) {
        $html .= '<option value="' . efpic_cover_theme_esc($k) . '"' . ((string) $mosaicCols === $k ? ' selected' : '') . '>'
            . efpic_cover_theme_esc($lbl) . '</option>';
    }
    $html .= '</select></label>';
    $html .= '</div>';

    $coverMediaType = efpic_gallery_cover_media_type($formMeta);
    $coverVideoId = efpic_gallery_cover_video_id($formMeta);
    $coverVideos = efpic_admin_cover_video_options($config, $formMeta);
    $coverAnimation = efpic_gallery_cover_animation($formMeta);

    $html .= '<fieldset class="admin-cover-theme__block admin-cover-media-block" id="admin-cover-media-block">';
    $html .= '<legend>Vāka medijs</legend>';
    $html .= '<div class="admin-cover-media-type" role="radiogroup" aria-label="Vāka medija veids">';
    $html .= '<label class="admin-cover-media-option"><input type="radio" name="cover_media_type" value="image"'
        . ($coverMediaType === 'image' ? ' checked' : '') . '> Bilde</label>';
    $html .= '<label class="admin-cover-media-option"><input type="radio" name="cover_media_type" value="video"'
        . ($coverMediaType === 'video' ? ' checked' : '') . ($coverVideos === [] ? ' disabled' : '') . '> Video</label>';
    $html .= '</div>';
    if ($coverVideos === []) {
        $html .= '<p class="muted">Lai lietotu video vāku, vispirms pievieno MP4 failu cilnē <strong>Video</strong>.</p>';
    } else {
        $html .= '<label class="admin-cover-video-select' . ($coverMediaType === 'video' ? '' : ' is-hidden') . '" id="admin-cover-video-select">'
            . 'Vāka video<select name="cover_video_id" id="cover_video_id">';
        $html .= '<option value="">— Izvēlēties —</option>';
        foreach ($coverVideos as $video) {
            $sel = $video['id'] === $coverVideoId ? ' selected' : '';
            $html .= '<option value="' . efpic_cover_theme_esc($video['id']) . '" data-url="' . efpic_cover_theme_esc($video['url']) . '"' . $sel . '>'
                . efpic_cover_theme_esc($video['label']) . '</option>';
        }
        $html .= '</select></label>';
    }
    $html .= '<label>Vāka animācija<select name="cover_animation" id="cover_animation">';
    foreach (efpic_gallery_cover_animation_options() as $k => $lbl) {
        $html .= '<option value="' . efpic_cover_theme_esc($k) . '"' . ($k === $coverAnimation ? ' selected' : '') . '>'
            . efpic_cover_theme_esc($lbl) . '</option>';
    }
    $html .= '</select></label>';
    $html .= '<p class="muted">Video vāks automātiski atskaņojas (bez skaņas). Animācija darbojas arī ar bildi.</p>';
    $html .= '</fieldset>';

    $html .= '<fieldset class="admin-cover-theme__block' . ($layoutLocked ? ' is-disabled' : '') . '" id="admin-cover-layout-block">';
    $html .= '<legend>Vāka bildes novietojums</legend>';
    if ($isMoodBlob) {
        $html .= '<p class="muted" id="admin-cover-layout-mood-note">Mood burbuļa stilā izkārtojumu nevar mainīt — tiek rādīts centrēts burbulis.</p>';
    } else {
        $html .= '<p class="muted" id="admin-cover-layout-mood-note" hidden>Mood burbuļa stilā izkārtojumu nevar mainīt — tiek rādīts centrēts burbulis.</p>';
    }
    if (efpic_gallery_uses_cinematic_full_cover($formMeta)) {
        $html .= '<p class="muted" id="admin-cover-layout-cinematic-note">Kinematogrāfiskajā stilā izkārtojumu nevar mainīt — bilde/video aizpilda visu ekrānu.</p>';
    } else {
        $html .= '<p class="muted" id="admin-cover-layout-cinematic-note" hidden>Kinematogrāfiskajā stilā izkārtojumu nevar mainīt — bilde/video aizpilda visu ekrānu.</p>';
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
    $html .= '<div class="admin-intro-typography-row">';
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
    $html .= efpic_render_intro_all_caps_toggle($formMeta);
    $html .= '</div>';
    $html .= '<div class="admin-form-layout admin-form-layout--basic">';
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
            . efpic_cover_theme_esc($device['label']) . '" loading="lazy" tabindex="-1"></iframe>';
        $html .= '</div></div></div>';
    }
    $html .= '</div>';
    if (!$hasCover) {
        $html .= '<p class="muted admin-cover-theme__hint" id="admin-cover-crop-hint">Izvēlieties vāka bildi cilnē <strong>Bildes</strong>, lai redzētu bildi priekšskatījumā.</p>';
    }
    $cropHidden = $isMoodBlob || !$hasCover ? ' hidden' : '';
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

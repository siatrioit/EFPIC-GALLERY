<?php

declare(strict_types=1);

require_once __DIR__ . '/failiem_face.php';
require_once __DIR__ . '/gallery_access.php';

function efpic_handle_client_face_status(array $config, string $galleryToken): void
{
    $found = efpic_find_gallery_by_token($config, $galleryToken);
    if ($found === null) {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_found']);
    }
    $meta = $found['meta'];
    $slug = $found['slug'];
    if (!efpic_gallery_face_search_enabled($meta)) {
        efpic_json_response(200, ['ok' => true, 'enabled' => false]);
    }
    try {
        $failiem = efpic_failiem_face_admin_status($config, $slug, $meta);
    } catch (Throwable $e) {
        $failiem = array_merge(
            efpic_failiem_face_cached_status($config, $slug, $meta),
            ['error' => $e->getMessage()]
        );
    }
    efpic_json_response(200, array_merge(['ok' => true, 'enabled' => true], $failiem));
}

function efpic_handle_client_face_persons(array $config, string $galleryToken): void
{
    $found = efpic_find_gallery_by_token($config, $galleryToken);
    if ($found === null) {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_found']);
    }
    $meta = $found['meta'];
    $slug = $found['slug'];
    if (!efpic_gallery_face_search_enabled($meta)) {
        efpic_json_response(403, ['ok' => false, 'error' => 'disabled']);
    }
    $ctx = efpic_viewer_context($config, $meta);
    $refresh = (($_GET['refresh'] ?? '') === '1');
    $payload = efpic_failiem_face_public_persons($config, $slug, $meta, $ctx, $refresh);
    efpic_json_response(200, $payload);
}

function efpic_handle_client_face_person_tokens(array $config, string $galleryToken): void
{
    $found = efpic_find_gallery_by_token($config, $galleryToken);
    if ($found === null) {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_found']);
    }
    $meta = $found['meta'];
    $slug = $found['slug'];
    if (!efpic_gallery_face_search_enabled($meta)) {
        efpic_json_response(403, ['ok' => false, 'error' => 'disabled']);
    }
    $rawIds = trim((string) ($_GET['ids'] ?? ''));
    if ($rawIds === '') {
        efpic_json_response(400, ['ok' => false, 'error' => 'missing_ids']);
    }
    $personIds = array_values(array_filter(array_map('trim', explode(',', $rawIds)), static fn ($id) => $id !== ''));
    if ($personIds === []) {
        efpic_json_response(400, ['ok' => false, 'error' => 'missing_ids']);
    }
    $ctx = efpic_viewer_context($config, $meta);
    $bundle = efpic_failiem_face_fetch_bundle($config, $slug, $meta);
    if (empty($bundle['ok'])) {
        efpic_json_response(502, ['ok' => false, 'error' => (string) ($bundle['error'] ?? 'failiem_error')]);
    }
    $tokens = efpic_failiem_face_tokens_for_persons(
        $meta,
        $ctx,
        $personIds,
        is_array($bundle['person_images'] ?? null) ? $bundle['person_images'] : [],
        is_array($bundle['person_images_ids_hashes'] ?? null) ? $bundle['person_images_ids_hashes'] : []
    );
    efpic_json_response(200, [
        'ok' => true,
        'count' => count($tokens),
        'tokens' => $tokens,
    ]);
}

function efpic_handle_client_face_no_face_tokens(array $config, string $galleryToken): void
{
    $found = efpic_find_gallery_by_token($config, $galleryToken);
    if ($found === null) {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_found']);
    }
    $meta = $found['meta'];
    $slug = $found['slug'];
    if (!efpic_gallery_face_search_enabled($meta)) {
        efpic_json_response(403, ['ok' => false, 'error' => 'disabled']);
    }
    $ctx = efpic_viewer_context($config, $meta);
    if (($ctx['role'] ?? '') === 'guest') {
        efpic_json_response(403, ['ok' => false, 'error' => 'disabled']);
    }
    $bundle = efpic_failiem_face_fetch_bundle($config, $slug, $meta);
    if (empty($bundle['ok'])) {
        efpic_json_response(502, ['ok' => false, 'error' => (string) ($bundle['error'] ?? 'failiem_error')]);
    }
    $tokens = efpic_failiem_face_tokens_without_faces(
        $meta,
        $ctx,
        is_array($bundle['person_images'] ?? null) ? $bundle['person_images'] : [],
        is_array($bundle['person_images_ids_hashes'] ?? null) ? $bundle['person_images_ids_hashes'] : [],
    );
    efpic_json_response(200, [
        'ok' => true,
        'count' => count($tokens),
        'tokens' => $tokens,
    ]);
}

function efpic_handle_portal_face_persons(array $config, string $portalToken): void
{
    $found = efpic_portal_find_by_token($config, $portalToken);
    if ($found === null) {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_found']);
    }
    efpic_portal_require_auth($config, $portalToken, $found);
    $meta = $found['meta'];
    $slug = $found['slug'];
    if (!efpic_gallery_face_search_enabled($meta)) {
        efpic_json_response(403, ['ok' => false, 'error' => 'disabled']);
    }
    $ctx = ['role' => 'client', 'guest_token' => '', 'hide_client_hidden' => false, 'share_image_tokens' => null, 'share_label' => '', 'share_include_videos' => false];
    $refresh = (($_GET['refresh'] ?? '') === '1');
    $payload = efpic_failiem_face_public_persons($config, $slug, $meta, $ctx, $refresh);
    efpic_json_response(200, $payload);
}

function efpic_handle_portal_face_person_tokens(array $config, string $portalToken): void
{
    $found = efpic_portal_find_by_token($config, $portalToken);
    if ($found === null) {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_found']);
    }
    efpic_portal_require_auth($config, $portalToken, $found);
    $meta = $found['meta'];
    $slug = $found['slug'];
    if (!efpic_gallery_face_search_enabled($meta)) {
        efpic_json_response(403, ['ok' => false, 'error' => 'disabled']);
    }
    $rawIds = trim((string) ($_GET['ids'] ?? ''));
    if ($rawIds === '') {
        efpic_json_response(400, ['ok' => false, 'error' => 'missing_ids']);
    }
    $personIds = array_values(array_filter(array_map('trim', explode(',', $rawIds)), static fn ($id) => $id !== ''));
    if ($personIds === []) {
        efpic_json_response(400, ['ok' => false, 'error' => 'missing_ids']);
    }
    $ctx = ['role' => 'client', 'guest_token' => '', 'hide_client_hidden' => false, 'share_image_tokens' => null, 'share_label' => '', 'share_include_videos' => false];
    $bundle = efpic_failiem_face_fetch_bundle($config, $slug, $meta);
    if (empty($bundle['ok'])) {
        efpic_json_response(502, ['ok' => false, 'error' => (string) ($bundle['error'] ?? 'failiem_error')]);
    }
    $tokens = efpic_failiem_face_tokens_for_persons(
        $meta,
        $ctx,
        $personIds,
        is_array($bundle['person_images'] ?? null) ? $bundle['person_images'] : [],
        is_array($bundle['person_images_ids_hashes'] ?? null) ? $bundle['person_images_ids_hashes'] : [],
    );
    efpic_json_response(200, [
        'ok' => true,
        'count' => count($tokens),
        'tokens' => $tokens,
    ]);
}

function efpic_handle_portal_face_no_face_tokens(array $config, string $portalToken): void
{
    $found = efpic_portal_find_by_token($config, $portalToken);
    if ($found === null) {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_found']);
    }
    efpic_portal_require_auth($config, $portalToken, $found);
    $meta = $found['meta'];
    $slug = $found['slug'];
    if (!efpic_gallery_face_search_enabled($meta)) {
        efpic_json_response(403, ['ok' => false, 'error' => 'disabled']);
    }
    $ctx = ['role' => 'client', 'guest_token' => '', 'hide_client_hidden' => false, 'share_image_tokens' => null, 'share_label' => '', 'share_include_videos' => false];
    $bundle = efpic_failiem_face_fetch_bundle($config, $slug, $meta);
    if (empty($bundle['ok'])) {
        efpic_json_response(502, ['ok' => false, 'error' => (string) ($bundle['error'] ?? 'failiem_error')]);
    }
    $tokens = efpic_failiem_face_tokens_without_faces(
        $meta,
        $ctx,
        is_array($bundle['person_images'] ?? null) ? $bundle['person_images'] : [],
        is_array($bundle['person_images_ids_hashes'] ?? null) ? $bundle['person_images_ids_hashes'] : [],
    );
    efpic_json_response(200, [
        'ok' => true,
        'count' => count($tokens),
        'tokens' => $tokens,
    ]);
}

/** @return array<string, mixed> */
function efpic_admin_face_failiem_refresh(array $config, string $slug): array
{
    $meta = efpic_load_gallery_meta($config, $slug);
    if ($meta === null) {
        return ['ok' => false, 'error' => 'not_found'];
    }
    if (!efpic_gallery_face_search_enabled($meta)) {
        return ['ok' => false, 'error' => 'disabled'];
    }
    @unlink(efpic_failiem_face_cache_path($config, $slug));
    efpic_failiem_face_fetch_bundle($config, $slug, $meta, true);
    $status = efpic_failiem_face_admin_status($config, $slug, $meta);

    return array_merge(['ok' => true], $status);
}

function efpic_admin_render_face_search_panel(array $config, array $meta, string $slug): string
{
    $fs = efpic_gallery_face_search($meta);
    $enabled = !empty($fs['enabled']);
    $failiemStatus = efpic_failiem_face_cached_status($config, $slug, $meta);
    $uploadHash = efpic_failiem_face_upload_hash($meta);
    $statusLabel = ($failiemStatus['ready'] ?? false)
        ? 'Gatavs (Failiem)'
        : (($failiemStatus['error'] ?? '') !== ''
            ? 'Nav ielādēts'
            : (($failiemStatus['processing_error'] ?? false) ? 'Failiem kļūda' : 'Gaida Failiem'));

    $html = '<fieldset class="admin-fieldset-full admin-fieldset-compact" id="admin-face-search-panel"><legend>Seju meklēšana (Failiem)</legend>';
    $html .= '<input type="hidden" name="face_search_enabled" value="0">';
    $html .= efpic_render_admin_toggle('Ieslēgt seju meklēšanu publiskajā galerijā', $enabled, [
        'name' => 'face_search_enabled',
        'value' => '1',
    ]);
    $html .= '<p class="muted">Izmanto Failiem mapes seju indeksu — viesi izvēlas seju no saraksta. '
        . 'Indeksēšanu veic Failiem (ne mūsu serveris).</p>';
    $html .= '<p class="muted">Web mape (Apskatei): <code>' . efpic_admin_esc($uploadHash ?: '—') . '</code>'
        . ' — no šīs mapes tiek lasītas personas un saistītas ar galerijas bildēm.</p>';
    $html .= '<p class="muted"><label>Pārrakstīt Failiem mapes hash (ja vajag): '
        . '<input type="text" name="face_search_failiem_upload_hash" class="admin-input-sm" value="'
        . efpic_admin_esc((string) ($fs['failiem_upload_hash'] ?? ''))
        . '" placeholder="tukšs = no Web mapes URL"></label></p>';
    if (($failiemStatus['error'] ?? '') !== '') {
        $html .= '<p class="muted" id="admin-face-failiem-hint">' . efpic_admin_esc((string) $failiemStatus['error']) . '</p>';
    }
    $html .= '<p class="muted admin-face-status" id="admin-face-status">Statuss: <strong id="admin-face-status-label">'
        . efpic_admin_esc($statusLabel) . '</strong>';
    $html .= ' · personas <strong id="admin-face-person-count">' . (int) ($failiemStatus['person_count'] ?? 0) . '</strong>';
    $html .= ' · hash <strong id="admin-face-failiem-hash">' . efpic_admin_esc((string) ($failiemStatus['upload_hash'] ?? '')) . '</strong>';
    $html .= '</p>';
    if (($fs['error'] ?? '') !== '') {
        $html .= '<p class="err" id="admin-face-error">' . efpic_admin_esc((string) $fs['error']) . '</p>';
    }
    $html .= '<button type="button" class="btn admin-btn-sm" id="admin-face-failiem-refresh-btn">Atsvaidzināt no Failiem</button>';
    $html .= ' <span class="admin-face-index-msg muted" id="admin-face-index-msg" hidden></span>';
    $html .= '</fieldset>';

    return $html;
}

function efpic_apply_face_search_from_post(array &$meta, ?array $config = null): void
{
    if (!isset($meta['face_search']) || !is_array($meta['face_search'])) {
        $meta['face_search'] = efpic_gallery_face_search_defaults();
    }
    $meta['face_search']['enabled'] = efpic_post_flag_is_on('face_search_enabled');
    $meta['face_search']['provider'] = 'failiem';
    $meta['face_search']['failiem_upload_hash'] = efpic_failiem_parse_folder_hash(
        trim((string) ($_POST['face_search_failiem_upload_hash'] ?? ''))
    );
    if ($meta['face_search']['enabled']) {
        $meta['face_search']['status'] = 'ready';
        $meta['face_search']['error'] = '';
        if ($config !== null) {
            efpic_face_legacy_queue_purge($config);
        }
    } else {
        $meta['face_search']['status'] = 'none';
    }
}

function efpic_client_render_face_filter_toolbar_panel(): string
{
    return '<div class="gallery-face-filter-status" id="faceSearchToolbar" hidden>'
        . '<div class="gallery-face-filter-faces" id="faceSearchToolbarFaces" aria-hidden="true"></div>'
        . '<span class="gallery-face-filter-text" id="faceSearchToolbarText"></span>'
        . '<button type="button" class="btn admin-btn-sm" id="faceSearchClear">Rādīt visas</button>'
        . '</div>';
}

function efpic_client_render_face_person_modal(): string
{
    return '<div class="face-search-modal" id="facePersonModal" hidden>'
        . '<div class="face-search-dialog face-person-dialog" role="dialog" aria-labelledby="facePersonTitle" aria-modal="true">'
        . '<button type="button" class="face-search-close" data-face-person-close aria-label="Aizvērt">&times;</button>'
        . '<h2 id="facePersonTitle">Meklēt pēc sejas</h2>'
        . '<p class="muted">Izvēlies vienu vai vairākas sejas — parādīsim tikai attiecīgās fotogrāfijas.</p>'
        . '<div class="face-person-grid" id="facePersonGrid" aria-live="polite"></div>'
        . '<p class="face-search-status muted" id="facePersonStatus" hidden></p>'
        . '<div class="face-person-actions">'
        . '<button type="button" class="btn" id="facePersonDeselect" disabled>Noņemt izvēli</button>'
        . '<button type="button" class="btn primary" id="facePersonApply">Rādīt bildes</button>'
        . '</div></div></div>';
}

function efpic_client_face_search_ready(array $config, string $slug, array $meta, array $ctx = []): bool
{
    if (($ctx['role'] ?? '') === 'guest') {
        return false;
    }
    if (!efpic_gallery_face_search_enabled($meta)) {
        return false;
    }

    return efpic_failiem_face_upload_hash($meta) !== '';
}

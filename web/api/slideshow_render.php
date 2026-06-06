<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/gallery_access.php';
require_once __DIR__ . '/gallery_assets.php';

/** @return array<string, mixed> */
function efpic_slideshow_render_field_defaults(): array
{
    return [
        'intro_title' => '',
        'bg_mode' => 'white',
        'image_source' => 'favorites',
        'image_order_tokens' => [],
        'video_file' => '',
        'render_status' => 'none',
        'render_job_id' => '',
        'render_error' => '',
        'render_updated_at' => '',
    ];
}

/** @param array<string, mixed> $slot */
function efpic_slideshow_slot_with_render(array $slot): array
{
    return array_merge(efpic_slideshow_render_field_defaults(), $slot);
}

function efpic_render_queue_dir(array $config): string
{
    $base = dirname(efpic_storage_path($config)) . DIRECTORY_SEPARATOR . 'render_queue';

    return $base;
}

function efpic_render_job_path(array $config, string $jobId): string
{
    if (preg_match('/^[a-f0-9]{32}$/', $jobId) !== 1) {
        throw new InvalidArgumentException('Nederīgs job ID');
    }

    return efpic_render_queue_dir($config) . DIRECTORY_SEPARATOR . $jobId . '.json';
}

function efpic_render_load_job(array $config, string $jobId): ?array
{
    return efpic_read_json_file(efpic_render_job_path($config, $jobId));
}

function efpic_render_save_job(array $config, array $job): void
{
    $id = (string) ($job['id'] ?? '');
    if ($id === '') {
        throw new InvalidArgumentException('Job bez ID');
    }
    $job['updated_at'] = gmdate('c');
    efpic_write_json_file(efpic_render_job_path($config, $id), $job);
}

function efpic_render_status_label(string $status): string
{
    return match ($status) {
        'queued' => 'Gaida render…',
        'processing' => 'Ģenerē video…',
        'ready' => 'Video gatavs',
        'failed' => 'Render kļūda',
        default => 'Nav ģenerēts',
    };
}

/** Aptuvenais MP3 garums sekundēs (128 kbps estimāts). */
function efpic_audio_duration_sec_estimate(string $path): ?float
{
    if (!is_file($path)) {
        return null;
    }
    $size = filesize($path);
    if ($size === false || $size <= 0) {
        return null;
    }

    return ($size * 8) / 128000.0;
}

/**
 * @return list<array<string, mixed>>
 */
function efpic_slideshow_collect_images_for_render(array $config, array $meta, string $owner, string $source): array
{
    $ctx = ['guest_token' => '', 'share_image_tokens' => null, 'share_include_videos' => false];
    if ($source === 'all') {
        $out = [];
        foreach (efpic_sort_images_for_display($meta) as $img) {
            if (!is_array($img)) {
                continue;
            }
            if (!efpic_image_visible_to_viewer($img, $meta, $ctx)) {
                continue;
            }
            $tok = (string) ($img['token'] ?? '');
            if ($tok === '') {
                continue;
            }
            $out[] = $img;
        }

        return $out;
    }

    return efpic_slideshow_favorite_images($meta, $ctx, $config, $owner);
}

/**
 * @param list<array<string, mixed>> $images
 * @return list<array<string, mixed>>
 */
function efpic_slideshow_sort_images_for_render(array $images, array $orderTokens): array
{
    if ($orderTokens === []) {
        usort($images, static function (array $a, array $b): int {
            $na = strtolower((string) ($a['basename'] ?? $a['filename'] ?? ''));
            $nb = strtolower((string) ($b['basename'] ?? $b['filename'] ?? ''));

            return $na <=> $nb;
        });

        return $images;
    }

    $byToken = [];
    foreach ($images as $img) {
        $tok = (string) ($img['token'] ?? '');
        if ($tok !== '') {
            $byToken[$tok] = $img;
        }
    }
    $out = [];
    $used = [];
    foreach ($orderTokens as $tok) {
        $tok = (string) $tok;
        if ($tok !== '' && isset($byToken[$tok])) {
            $out[] = $byToken[$tok];
            $used[$tok] = true;
        }
    }
    foreach ($images as $img) {
        $tok = (string) ($img['token'] ?? '');
        if ($tok !== '' && empty($used[$tok])) {
            $out[] = $img;
        }
    }

    return $out;
}

/**
 * @return array{ok: bool, error?: string, min_images?: int, have_images?: int}
 */
function efpic_slideshow_validate_render_request(array $config, string $slug, array $meta, string $owner): array
{
    $slots = efpic_gallery_slideshows_struct($meta);
    if (!isset($slots[$owner])) {
        return ['ok' => false, 'error' => 'Nederīgs slideshow īpašnieks'];
    }
    $slot = efpic_slideshow_slot_with_render($slots[$owner]);
    $audio = (string) ($slot['audio_file'] ?? '');
    if ($audio === '') {
        return ['ok' => false, 'error' => 'Augšupielādē MP3 pirms video ģenerēšanas.'];
    }

    $audioPath = efpic_gallery_assets_dir($config, $slug) . DIRECTORY_SEPARATOR . $audio;
    if (!is_file($audioPath)) {
        return ['ok' => false, 'error' => 'MP3 fails nav atrasts serverī.'];
    }

    $source = (string) ($slot['image_source'] ?? 'favorites');
    if (!in_array($source, ['favorites', 'all'], true)) {
        $source = 'favorites';
    }
    $images = efpic_slideshow_sort_images_for_render(
        efpic_slideshow_collect_images_for_render($config, $meta, $owner, $source),
        is_array($slot['image_order_tokens'] ?? null) ? $slot['image_order_tokens'] : [],
    );
    $count = count($images);
    if ($count === 0) {
        return ['ok' => false, 'error' => 'Nav nevienas bildes slideshow (favorīti vai redzamās).'];
    }

    $duration = efpic_audio_duration_sec_estimate($audioPath);
    if ($duration !== null && $duration > 0) {
        $minImages = (int) max(1, ceil($duration / 5));
        if ($source === 'favorites' && $count < $minImages) {
            $missing = $minImages - $count;

            return [
                'ok' => false,
                'error' => 'Pietrūkst vēl ' . $missing . ' favorītbildes video ģenerēšanai.',
                'min_images' => $minImages,
                'have_images' => $count,
            ];
        }
        if ($source === 'all') {
            $musicNeeded = $count * 4;
            if ($duration + 0.5 < $musicNeeded) {
                $missingSec = (int) ceil($musicNeeded - $duration);
                $min = intdiv($missingSec, 60);
                $sec = $missingSec % 60;

                return [
                    'ok' => false,
                    'error' => 'Pietrūkst mūzika ~' . $min . ' min ' . $sec . ' sek.',
                    'min_images' => $minImages,
                    'have_images' => $count,
                ];
            }
        }
    }

    return ['ok' => true, 'have_images' => $count];
}

/**
 * @return array<string, mixed>
 */
function efpic_slideshow_build_job(array $config, string $slug, array $meta, string $owner): array
{
    $slots = efpic_gallery_slideshows_struct($meta);
    $slot = efpic_slideshow_slot_with_render($slots[$owner]);
    $source = (string) ($slot['image_source'] ?? 'favorites');
    $order = is_array($slot['image_order_tokens'] ?? null) ? $slot['image_order_tokens'] : [];
    $images = efpic_slideshow_sort_images_for_render(
        efpic_slideshow_collect_images_for_render($config, $meta, $owner, $source),
        $order,
    );
    $gt = (string) ($meta['gallery_token'] ?? '');
    $jobId = efpic_random_hex(16);
    $imagePayload = [];
    foreach ($images as $img) {
        $tok = (string) ($img['token'] ?? '');
        if ($tok === '') {
            continue;
        }
        $imagePayload[] = [
            'token' => $tok,
            'basename' => (string) ($img['basename'] ?? $img['filename'] ?? ''),
        ];
    }

    return [
        'id' => $jobId,
        'slug' => $slug,
        'owner' => $owner,
        'status' => 'queued',
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
        'claimed_at' => '',
        'error' => '',
        'gallery_token' => $gt,
        'intro_title' => trim((string) ($slot['intro_title'] ?? '')),
        'bg_mode' => in_array((string) ($slot['bg_mode'] ?? 'white'), ['white', 'gallery'], true)
            ? (string) $slot['bg_mode'] : 'white',
        'page_bg_color' => efpic_client_page_bg_color($config, $meta),
        'image_source' => $source,
        'audio_file' => (string) ($slot['audio_file'] ?? ''),
        'images' => $imagePayload,
        'spec' => [
            'width' => 1920,
            'height' => 1080,
            'intro_sec' => 6,
            'music_start_sec' => 3,
            'video_lead_sec' => 3,
            'fade_in_sec' => 1.5,
            'fade_out_sec' => 2.5,
            'slide_min_sec' => 3,
            'slide_max_sec' => 5,
            'transition' => 'cut',
        ],
    ];
}

function efpic_render_cancel_pending_jobs(array $config, string $slug, string $owner): void
{
    if ($slug === '' || !in_array($owner, ['admin', 'client'], true)) {
        return;
    }
    $dir = efpic_render_queue_dir($config);
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
        $job = efpic_read_json_file($path);
        if (!is_array($job)) {
            continue;
        }
        if ((string) ($job['slug'] ?? '') !== $slug || (string) ($job['owner'] ?? '') !== $owner) {
            continue;
        }
        $status = (string) ($job['status'] ?? '');
        if (!in_array($status, ['queued', 'processing'], true)) {
            continue;
        }
        $job['status'] = 'cancelled';
        $job['error'] = 'Aizstāts ar jaunu render job';
        $job['updated_at'] = gmdate('c');
        efpic_render_save_job($config, $job);
    }
}

function efpic_slideshow_enqueue_render(array $config, string $slug, array &$meta, string $owner): bool
{
    if (!in_array($owner, ['admin', 'client'], true)) {
        throw new InvalidArgumentException('Nederīgs slideshow īpašnieks');
    }

    $validation = efpic_slideshow_validate_render_request($config, $slug, $meta, $owner);
    if (empty($validation['ok'])) {
        throw new InvalidArgumentException((string) ($validation['error'] ?? 'Nevar izveidot render job'));
    }

    $slots = efpic_gallery_slideshows_struct($meta);
    $slot = efpic_slideshow_slot_with_render($slots[$owner]);
    $status = (string) ($slot['render_status'] ?? 'none');
    if (in_array($status, ['queued', 'processing'], true)) {
        return false;
    }

    efpic_render_cancel_pending_jobs($config, $slug, $owner);

    $job = efpic_slideshow_build_job($config, $slug, $meta, $owner);
    efpic_render_save_job($config, $job);

    $slot['render_status'] = 'queued';
    $slot['render_job_id'] = (string) $job['id'];
    $slot['render_error'] = '';
    $slot['render_updated_at'] = gmdate('c');
    $slots[$owner] = $slot;
    $meta['slideshow'] = $slots;

    return true;
}

function efpic_render_claim_next_job(array $config): ?array
{
    $dir = efpic_render_queue_dir($config);
    if (!is_dir($dir)) {
        return null;
    }

    $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    sort($files);
    $now = time();
    foreach ($files as $path) {
        $job = efpic_read_json_file($path);
        if (!is_array($job)) {
            continue;
        }
        $status = (string) ($job['status'] ?? '');
        if (in_array($status, ['cancelled', 'ready', 'failed'], true)) {
            continue;
        }
        if ($status === 'queued') {
            $job['status'] = 'processing';
            $job['claimed_at'] = gmdate('c');
            efpic_render_save_job($config, $job);
            efpic_render_sync_slot_from_job($config, $job);

            return efpic_render_job_api_payload($config, $job);
        }
        if ($status === 'processing') {
            $claimed = strtotime((string) ($job['claimed_at'] ?? '')) ?: 0;
            if ($claimed > 0 && ($now - $claimed) > 1800) {
                $job['status'] = 'processing';
                $job['claimed_at'] = gmdate('c');
                $job['error'] = '';
                efpic_render_save_job($config, $job);
                efpic_render_sync_slot_from_job($config, $job);

                return efpic_render_job_api_payload($config, $job);
            }
            continue;
        }
    }

    return null;
}

/** @param array<string, mixed> $job */
function efpic_render_sync_slot_from_job(array $config, array $job): void
{
    $slug = (string) ($job['slug'] ?? '');
    $owner = (string) ($job['owner'] ?? '');
    if ($slug === '' || !in_array($owner, ['admin', 'client'], true)) {
        return;
    }
    $meta = efpic_load_gallery_meta($config, $slug);
    if ($meta === null) {
        return;
    }
    $slots = efpic_gallery_slideshows_struct($meta);
    $slot = efpic_slideshow_slot_with_render($slots[$owner]);
    $slot['render_status'] = (string) ($job['status'] ?? $slot['render_status']);
    $slot['render_job_id'] = (string) ($job['id'] ?? '');
    $slot['render_error'] = (string) ($job['error'] ?? '');
    $slot['render_updated_at'] = gmdate('c');
    $slots[$owner] = $slot;
    $meta['slideshow'] = $slots;
    efpic_save_gallery_meta($config, $slug, $meta);
}

/** @param array<string, mixed> $job */
function efpic_render_job_api_payload(array $config, array $job): array
{
    $base = efpic_base_url($config);
    $id = (string) ($job['id'] ?? '');

    return [
        'ok' => true,
        'job' => [
            'id' => $id,
            'slug' => (string) ($job['slug'] ?? ''),
            'owner' => (string) ($job['owner'] ?? ''),
            'status' => (string) ($job['status'] ?? ''),
            'intro_title' => (string) ($job['intro_title'] ?? ''),
            'bg_mode' => (string) ($job['bg_mode'] ?? 'white'),
            'page_bg_color' => (string) ($job['page_bg_color'] ?? '#ffffff'),
            'audio_file' => (string) ($job['audio_file'] ?? ''),
            'images' => $job['images'] ?? [],
            'spec' => $job['spec'] ?? [],
            'assets' => [
                'audio' => $base . '/api/render/jobs/' . rawurlencode($id) . '/audio',
                'images' => array_map(
                    static fn (array $img): array => [
                        'token' => (string) ($img['token'] ?? ''),
                        'url' => $base . '/api/render/jobs/' . rawurlencode($id) . '/image/'
                            . rawurlencode((string) ($img['token'] ?? '')),
                    ],
                    is_array($job['images'] ?? null) ? $job['images'] : [],
                ),
                'complete' => $base . '/api/render/jobs/' . rawurlencode($id) . '/complete',
                'fail' => $base . '/api/render/jobs/' . rawurlencode($id) . '/fail',
            ],
        ],
    ];
}

/** @param array<string, mixed> $job */
function efpic_render_complete_job(array $config, array $job, string $tmpMp4Path): void
{
    $slug = (string) ($job['slug'] ?? '');
    $owner = (string) ($job['owner'] ?? '');
    if ($slug === '' || !is_file($tmpMp4Path)) {
        throw new InvalidArgumentException('Trūkst MP4 fails');
    }

    $meta = efpic_load_gallery_meta($config, $slug);
    if ($meta === null) {
        throw new InvalidArgumentException('Galerija nav atrasta');
    }

    $slots = efpic_gallery_slideshows_struct($meta);
    $slot = efpic_slideshow_slot_with_render($slots[$owner]);
    $oldVideo = (string) ($slot['video_file'] ?? '');
    $newVideo = 'slideshow_' . $owner . '_' . efpic_random_hex(6) . '.mp4';
    $dest = efpic_ensure_gallery_assets_dir($config, $slug) . DIRECTORY_SEPARATOR . $newVideo;
    if (!rename($tmpMp4Path, $dest)) {
        if (!copy($tmpMp4Path, $dest)) {
            throw new RuntimeException('Neizdevās saglabāt MP4');
        }
        unlink($tmpMp4Path);
    }

    if ($oldVideo !== '' && $oldVideo !== $newVideo) {
        efpic_delete_gallery_asset_file($config, $slug, $oldVideo);
    }

    $job['status'] = 'ready';
    $job['error'] = '';
    efpic_render_save_job($config, $job);

    $slot['video_file'] = $newVideo;
    $slot['render_status'] = 'ready';
    $slot['render_job_id'] = (string) ($job['id'] ?? '');
    $slot['render_error'] = '';
    $slot['render_updated_at'] = gmdate('c');
    $slots[$owner] = $slot;
    $meta['slideshow'] = $slots;
    efpic_save_gallery_meta($config, $slug, $meta);
}

/** @param array<string, mixed> $job */
function efpic_render_fail_job(array $config, array $job, string $message): void
{
    $job['status'] = 'failed';
    $job['error'] = $message;
    efpic_render_save_job($config, $job);
    efpic_render_sync_slot_from_job($config, $job);
}

function efpic_render_stream_job_audio(array $config, string $jobId): void
{
    $job = efpic_render_load_job($config, $jobId);
    if (!is_array($job) || (string) ($job['status'] ?? '') === 'ready') {
        http_response_code(404);
        exit;
    }
    $slug = (string) ($job['slug'] ?? '');
    $audio = (string) ($job['audio_file'] ?? '');
    if ($slug === '' || $audio === '' || preg_match('/^[a-zA-Z0-9._-]+$/', $audio) !== 1) {
        http_response_code(404);
        exit;
    }
    $path = efpic_gallery_assets_dir($config, $slug) . DIRECTORY_SEPARATOR . $audio;
    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: audio/mpeg');
    header('Content-Length: ' . (string) filesize($path));
    readfile($path);
    exit;
}

function efpic_render_stream_job_image(array $config, string $jobId, string $token): void
{
    $job = efpic_render_load_job($config, $jobId);
    if (!is_array($job)) {
        http_response_code(404);
        exit;
    }
    $allowed = false;
    foreach ($job['images'] ?? [] as $img) {
        if (is_array($img) && (string) ($img['token'] ?? '') === $token) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed || !preg_match('/^[a-f0-9]{48}$/', $token)) {
        http_response_code(404);
        exit;
    }
    $slug = (string) ($job['slug'] ?? '');
    $meta = efpic_load_gallery_meta($config, $slug);
    if ($meta === null) {
        http_response_code(404);
        exit;
    }
    $img = null;
    foreach ($meta['images'] ?? [] as $row) {
        if (is_array($row) && (string) ($row['token'] ?? '') === $token) {
            $img = $row;
            break;
        }
    }
    if ($img === null) {
        http_response_code(404);
        exit;
    }
    $url = efpic_client_media_url($config, $img, 'web', 1920, ['guest_token' => '']);
    header('Location: ' . $url, true, 302);
    exit;
}

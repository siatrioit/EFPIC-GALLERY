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
        'render_fingerprint' => '',
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

function efpic_render_max_attempts(): int
{
    return 3;
}

function efpic_render_stuck_seconds(): int
{
    return 45 * 60;
}

function efpic_render_reclaim_seconds(): int
{
    return 30 * 60;
}

function efpic_render_worker_state_path(array $config): string
{
    return dirname(efpic_storage_path($config)) . DIRECTORY_SEPARATOR . 'render_worker_state.json';
}

function efpic_render_worker_touch(array $config, string $event = 'ping'): void
{
    $path = efpic_render_worker_state_path($config);
    $state = efpic_read_json_file($path) ?? [];
    $now = gmdate('c');
    $state['last_seen_at'] = $now;
    if ($event === 'ping') {
        $state['last_ping_at'] = $now;
    } elseif ($event === 'claim') {
        $state['last_claim_at'] = $now;
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    efpic_write_json_file($path, $state);
}

function efpic_render_format_ago(?int $seconds): string
{
    if ($seconds === null || $seconds < 0) {
        return 'nav datu';
    }
    if ($seconds < 60) {
        return $seconds . ' sek.';
    }
    if ($seconds < 3600) {
        return intdiv($seconds, 60) . ' min';
    }
    return intdiv($seconds, 3600) . ' h ' . intdiv($seconds % 3600, 60) . ' min';
}

/** @return array{online: bool, status: string, status_label: string, last_seen_at: string, last_seen_ago: string, last_ping_at: string, last_claim_at: string} */
function efpic_render_worker_status(array $config): array
{
    $state = efpic_read_json_file(efpic_render_worker_state_path($config)) ?? [];
    $lastSeenRaw = (string) ($state['last_seen_at'] ?? '');
    $lastSeen = $lastSeenRaw !== '' ? (strtotime($lastSeenRaw) ?: 0) : 0;
    $age = $lastSeen > 0 ? time() - $lastSeen : null;
    $online = $age !== null && $age <= 90;
    $stale = $age !== null && $age <= 300;
    $status = $online ? 'online' : ($stale ? 'stale' : 'offline');
    $statusLabel = match ($status) {
        'online' => 'Worker aktīvs',
        'stale' => 'Worker klusums (pārbaudi logus)',
        default => 'Worker bez signāla',
    };

    return [
        'online' => $online,
        'status' => $status,
        'status_label' => $statusLabel,
        'last_seen_at' => $lastSeenRaw,
        'last_seen_ago' => efpic_render_format_ago($age),
        'last_ping_at' => (string) ($state['last_ping_at'] ?? ''),
        'last_claim_at' => (string) ($state['last_claim_at'] ?? ''),
    ];
}

/** @return list<array<string, mixed>> */
function efpic_render_list_jobs(array $config, int $limit = 50): array
{
    $dir = efpic_render_queue_dir($config);
    if (!is_dir($dir)) {
        return [];
    }
    $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    $jobs = [];
    foreach ($files as $path) {
        $job = efpic_read_json_file($path);
        if (!is_array($job)) {
            continue;
        }
        $jobs[] = $job;
    }
    usort($jobs, static function (array $a, array $b): int {
        $ta = strtotime((string) ($a['updated_at'] ?? $a['created_at'] ?? '')) ?: 0;
        $tb = strtotime((string) ($b['updated_at'] ?? $b['created_at'] ?? '')) ?: 0;

        return $tb <=> $ta;
    });
    if ($limit > 0 && count($jobs) > $limit) {
        $jobs = array_slice($jobs, 0, $limit);
    }

    return $jobs;
}

/** @return array{queued: int, processing: int, failed: int, ready: int, cancelled: int, total: int} */
function efpic_render_queue_stats(array $config): array
{
    $stats = [
        'queued' => 0,
        'processing' => 0,
        'failed' => 0,
        'ready' => 0,
        'cancelled' => 0,
        'total' => 0,
    ];
    foreach (efpic_render_list_jobs($config, 0) as $job) {
        $status = (string) ($job['status'] ?? '');
        if (!isset($stats[$status])) {
            continue;
        }
        $stats[$status]++;
        $stats['total']++;
    }

    return $stats;
}

function efpic_render_run_maintenance(array $config): void
{
    $dir = efpic_render_queue_dir($config);
    if (!is_dir($dir)) {
        return;
    }
    $now = time();
    $stuckSec = efpic_render_stuck_seconds();
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
        $job = efpic_read_json_file($path);
        if (!is_array($job)) {
            continue;
        }
        $status = (string) ($job['status'] ?? '');
        if ($status !== 'processing') {
            continue;
        }
        $claimed = strtotime((string) ($job['claimed_at'] ?? '')) ?: 0;
        if ($claimed <= 0 || ($now - $claimed) <= $stuckSec) {
            continue;
        }
        efpic_render_fail_job(
            $config,
            $job,
            'Render timeout pēc ' . intdiv($stuckSec, 60) . ' min',
            true,
        );
    }
}

function efpic_render_slot_status_from_job(string $jobStatus): string
{
    return match ($jobStatus) {
        'ready' => 'ready',
        'failed' => 'failed',
        'queued', 'processing' => $jobStatus,
        default => 'none',
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
    $audioFiles = efpic_slideshow_slot_audio_files($slot);
    if ($audioFiles === []) {
        return ['ok' => false, 'error' => 'Augšupielādē MP3 pirms video ģenerēšanas.'];
    }

    $totalAudioSec = 0.0;
    foreach ($audioFiles as $audio) {
        $audioPath = efpic_gallery_assets_dir($config, $slug) . DIRECTORY_SEPARATOR . $audio;
        if (!is_file($audioPath)) {
            return ['ok' => false, 'error' => 'MP3 fails nav atrasts serverī: ' . $audio];
        }
        $dur = efpic_audio_duration_sec_estimate($audioPath);
        if ($dur !== null && $dur > 0) {
            $totalAudioSec += $dur;
        }
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

    $duration = $totalAudioSec > 0 ? $totalAudioSec : null;
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
    $audioFiles = efpic_slideshow_slot_audio_files($slot);
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
        'attempt' => 1,
        'max_attempts' => efpic_render_max_attempts(),
        'gallery_token' => $gt,
        'intro_title' => trim((string) ($slot['intro_title'] ?? '')),
        'bg_mode' => in_array((string) ($slot['bg_mode'] ?? 'white'), ['white', 'gallery'], true)
            ? (string) $slot['bg_mode'] : 'white',
        'page_bg_color' => efpic_client_page_bg_color($config, $meta),
        'image_source' => $source,
        'audio_file' => $audioFiles[0] ?? '',
        'audio_files' => $audioFiles,
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

/** Notīra saglabāto MP4 un atceļ gaidošos render darbus. */
function efpic_slideshow_clear_slot_video(array $config, string $slug, array &$slot, string $owner): void
{
    $video = (string) ($slot['video_file'] ?? '');
    if ($video !== '') {
        efpic_delete_gallery_asset_file($config, $slug, $video);
    }
    $slot['video_file'] = '';
    $slot['render_status'] = 'none';
    $slot['render_error'] = '';
    $slot['render_job_id'] = '';
    $slot['render_updated_at'] = gmdate('c');
    efpic_render_cancel_pending_jobs($config, $slug, $owner);
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
    efpic_render_run_maintenance($config);

    $dir = efpic_render_queue_dir($config);
    if (!is_dir($dir)) {
        return null;
    }

    $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    sort($files);
    $now = time();
    $reclaimSec = efpic_render_reclaim_seconds();
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
            if ($claimed > 0 && ($now - $claimed) > $reclaimSec) {
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
    $slot['render_status'] = efpic_render_slot_status_from_job((string) ($job['status'] ?? ''));
    $slot['render_job_id'] = (string) ($job['id'] ?? '');
    $slot['render_error'] = (string) ($job['error'] ?? '');
    $slot['render_updated_at'] = gmdate('c');
    $slots[$owner] = $slot;
    $meta['slideshow'] = $slots;
    efpic_save_gallery_meta($config, $slug, $meta);
}

function efpic_render_job_audio_files(array $job): array
{
    $files = [];
    if (is_array($job['audio_files'] ?? null)) {
        foreach ($job['audio_files'] as $file) {
            $file = (string) $file;
            if (preg_match('/^[a-zA-Z0-9._-]+$/', $file) === 1) {
                $files[] = $file;
            }
        }
    }
    if ($files === []) {
        $legacy = (string) ($job['audio_file'] ?? '');
        if (preg_match('/^[a-zA-Z0-9._-]+$/', $legacy) === 1) {
            $files[] = $legacy;
        }
    }

    return array_values(array_unique($files));
}

/** @param array<string, mixed> $job */
function efpic_render_job_api_payload(array $config, array $job): array
{
    $base = efpic_base_url($config);
    $id = (string) ($job['id'] ?? '');
    $audioFiles = efpic_render_job_audio_files($job);
    $audioTracks = [];
    foreach (array_keys($audioFiles) as $index) {
        $audioTracks[] = $base . '/api/render/jobs/' . rawurlencode($id) . '/audio/' . $index;
    }

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
            'audio_file' => $audioFiles[0] ?? '',
            'audio_files' => $audioFiles,
            'images' => $job['images'] ?? [],
            'spec' => $job['spec'] ?? [],
            'assets' => [
                'audio' => $audioTracks[0] ?? ($base . '/api/render/jobs/' . rawurlencode($id) . '/audio/0'),
                'audio_tracks' => $audioTracks,
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
    $slot['render_fingerprint'] = efpic_slideshow_render_config_fingerprint($slot);
    $slots[$owner] = $slot;
    $meta['slideshow'] = $slots;
    efpic_save_gallery_meta($config, $slug, $meta);
}

/** @param array<string, mixed> $job */
function efpic_render_fail_job(array $config, array $job, string $message, bool $allowRetry = true): bool
{
    $attempt = max(1, (int) ($job['attempt'] ?? 1));
    $maxAttempts = max(1, (int) ($job['max_attempts'] ?? efpic_render_max_attempts()));

    if ($allowRetry && $attempt < $maxAttempts) {
        $job['attempt'] = $attempt + 1;
        $job['status'] = 'queued';
        $job['claimed_at'] = '';
        $job['error'] = 'Mēģinājums ' . $attempt . '/' . $maxAttempts . ' neizdevās: ' . $message;
        efpic_render_save_job($config, $job);
        efpic_render_sync_slot_from_job($config, $job);

        return true;
    }

    $job['status'] = 'failed';
    $job['error'] = $attempt > 1
        ? 'Neizdevās pēc ' . $attempt . ' mēģinājumiem: ' . $message
        : $message;
    efpic_render_save_job($config, $job);
    efpic_render_sync_slot_from_job($config, $job);

    return false;
}

function efpic_render_admin_retry_job(array $config, string $jobId): void
{
    if (preg_match('/^[a-f0-9]{32}$/', $jobId) !== 1) {
        throw new InvalidArgumentException('Nederīgs job ID');
    }
    $job = efpic_render_load_job($config, $jobId);
    if (!is_array($job)) {
        throw new InvalidArgumentException('Render job nav atrasts');
    }
    if ((string) ($job['status'] ?? '') !== 'failed') {
        throw new InvalidArgumentException('Retry pieejams tikai neveiksmīgiem job');
    }
    $job['status'] = 'queued';
    $job['claimed_at'] = '';
    $job['error'] = '';
    $job['attempt'] = 1;
    $job['max_attempts'] = efpic_render_max_attempts();
    efpic_render_save_job($config, $job);
    efpic_render_sync_slot_from_job($config, $job);
}

function efpic_render_admin_cancel_job(array $config, string $jobId): void
{
    if (preg_match('/^[a-f0-9]{32}$/', $jobId) !== 1) {
        throw new InvalidArgumentException('Nederīgs job ID');
    }
    $job = efpic_render_load_job($config, $jobId);
    if (!is_array($job)) {
        throw new InvalidArgumentException('Render job nav atrasts');
    }
    $status = (string) ($job['status'] ?? '');
    if (!in_array($status, ['queued', 'processing'], true)) {
        throw new InvalidArgumentException('Atcelt var tikai rindā esošus job');
    }
    $job['status'] = 'cancelled';
    $job['error'] = 'Atcelts manuāli';
    efpic_render_save_job($config, $job);
    efpic_render_sync_slot_from_job($config, $job);
}

/** @return array<string, mixed> */
function efpic_render_admin_job_row(array $config, array $job): array
{
    $slug = (string) ($job['slug'] ?? '');
    $galleryName = $slug;
    if ($slug !== '') {
        $meta = efpic_load_gallery_meta($config, $slug);
        if ($meta !== null) {
            $galleryName = (string) ($meta['name'] ?? $slug);
        }
    }
    $status = (string) ($job['status'] ?? '');
    $updated = strtotime((string) ($job['updated_at'] ?? $job['created_at'] ?? '')) ?: 0;
    $attempt = max(1, (int) ($job['attempt'] ?? 1));
    $maxAttempts = max(1, (int) ($job['max_attempts'] ?? efpic_render_max_attempts()));

    return [
        'id' => (string) ($job['id'] ?? ''),
        'slug' => $slug,
        'gallery_name' => $galleryName,
        'owner' => (string) ($job['owner'] ?? ''),
        'owner_label' => ((string) ($job['owner'] ?? '')) === 'client' ? 'Klients' : 'Fotogrāfs',
        'status' => $status,
        'status_label' => efpic_render_status_label($status),
        'error' => (string) ($job['error'] ?? ''),
        'attempt' => $attempt,
        'max_attempts' => $maxAttempts,
        'updated_at' => (string) ($job['updated_at'] ?? ''),
        'updated_ago' => efpic_render_format_ago($updated > 0 ? time() - $updated : null),
        'can_retry' => $status === 'failed',
        'can_cancel' => in_array($status, ['queued', 'processing'], true),
    ];
}

/** @return array<string, mixed> */
function efpic_render_admin_monitor_payload(array $config): array
{
    efpic_render_run_maintenance($config);
    $stats = efpic_render_queue_stats($config);
    $worker = efpic_render_worker_status($config);
    $rows = [];
    foreach (efpic_render_list_jobs($config, 30) as $job) {
        $status = (string) ($job['status'] ?? '');
        if (in_array($status, ['ready', 'cancelled'], true)) {
            continue;
        }
        $rows[] = efpic_render_admin_job_row($config, $job);
    }

    return [
        'ok' => true,
        'app_version' => efpic_app_version(),
        'worker' => $worker,
        'stats' => $stats,
        'jobs' => $rows,
    ];
}

function efpic_render_stream_job_audio(array $config, string $jobId, int $index = 0): void
{
    $job = efpic_render_load_job($config, $jobId);
    if (!is_array($job) || (string) ($job['status'] ?? '') === 'ready') {
        http_response_code(404);
        exit;
    }
    $slug = (string) ($job['slug'] ?? '');
    $audioFiles = efpic_render_job_audio_files($job);
    if ($slug === '' || !isset($audioFiles[$index])) {
        http_response_code(404);
        exit;
    }
    $audio = $audioFiles[$index];
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

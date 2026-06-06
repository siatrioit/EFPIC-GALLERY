<?php

declare(strict_types=1);

require_once __DIR__ . '/slideshow_render.php';

function efpic_handle_render_ping(array $config): void
{
    efpic_require_token($config);
    efpic_json_response(200, [
        'ok' => true,
        'service' => 'efpic-render',
        'app_version' => efpic_app_version(),
    ]);
}

function efpic_handle_render_claim_job(array $config): void
{
    efpic_require_token($config);
    $job = efpic_render_claim_next_job($config);
    if ($job === null) {
        efpic_json_response(200, ['ok' => true, 'job' => null]);
    }
    efpic_json_response(200, $job);
}

function efpic_handle_render_get_job(array $config, string $jobId): void
{
    efpic_require_token($config);
    $job = efpic_render_load_job($config, $jobId);
    if (!is_array($job)) {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_found']);
    }
    efpic_json_response(200, efpic_render_job_api_payload($config, $job));
}

function efpic_handle_render_job_audio(array $config, string $jobId): void
{
    efpic_require_token($config);
    efpic_render_stream_job_audio($config, $jobId);
}

function efpic_handle_render_job_image(array $config, string $jobId, string $token): void
{
    efpic_require_token($config);
    efpic_render_stream_job_image($config, $jobId, strtolower($token));
}

function efpic_handle_render_job_complete(array $config, string $jobId): void
{
    efpic_require_token($config);
    $job = efpic_render_load_job($config, $jobId);
    if (!is_array($job)) {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_found']);
    }
    if ((string) ($job['status'] ?? '') === 'cancelled') {
        efpic_json_response(409, ['ok' => false, 'error' => 'job_cancelled']);
    }
    if (!isset($_FILES['video']) || !is_array($_FILES['video'])) {
        efpic_json_response(400, ['ok' => false, 'error' => 'missing_video']);
    }
    $file = $_FILES['video'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        efpic_json_response(400, ['ok' => false, 'error' => 'upload_error']);
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext !== 'mp4' || $tmp === '' || !is_uploaded_file($tmp)) {
        efpic_json_response(400, ['ok' => false, 'error' => 'invalid_video']);
    }
    $max = 512 * 1024 * 1024;
    if ((int) ($file['size'] ?? 0) > $max) {
        efpic_json_response(413, ['ok' => false, 'error' => 'file_too_large']);
    }

    try {
        efpic_render_complete_job($config, $job, $tmp);
    } catch (Throwable $e) {
        efpic_json_response(500, ['ok' => false, 'error' => $e->getMessage()]);
    }

    efpic_json_response(200, [
        'ok' => true,
        'job_id' => $jobId,
        'status' => 'ready',
    ]);
}

function efpic_handle_render_job_fail(array $config, string $jobId): void
{
    efpic_require_token($config);
    $job = efpic_render_load_job($config, $jobId);
    if (!is_array($job)) {
        efpic_json_response(404, ['ok' => false, 'error' => 'not_found']);
    }
    $raw = file_get_contents('php://input');
    $body = is_string($raw) ? json_decode($raw, true) : null;
    $message = trim((string) (is_array($body) ? ($body['error'] ?? $body['message'] ?? '') : ''));
    if ($message === '') {
        $message = 'Render worker kļūda';
    }
    efpic_render_fail_job($config, $job, $message);
    efpic_json_response(200, ['ok' => true, 'job_id' => $jobId, 'status' => 'failed']);
}

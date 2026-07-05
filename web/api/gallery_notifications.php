<?php

declare(strict_types=1);

require_once __DIR__ . '/guest_delivery_handlers.php';

function efpic_gallery_notify_cfg(array $config): array
{
    $gn = $config['gallery_notifications'] ?? [];

    return is_array($gn) ? $gn : [];
}

function efpic_gallery_notify_enabled(array $config): bool
{
    $gn = efpic_gallery_notify_cfg($config);

    return !empty($gn['enabled']);
}

function efpic_gallery_email_cfg(array $config): array
{
    $app = efpic_load_app_settings($config);
    $fromApp = $app['gallery_email'] ?? [];
    if (is_array($fromApp) && trim((string) ($fromApp['from'] ?? '')) !== '') {
        return $fromApp;
    }

    $gn = efpic_gallery_notify_cfg($config);
    $email = $gn['email'] ?? [];
    if (is_array($email) && trim((string) ($email['from'] ?? '')) !== '') {
        return $email;
    }

    $gd = efpic_guest_delivery_cfg($config);
    $fallback = $gd['email'] ?? [];

    return is_array($fallback) ? $fallback : [];
}

function efpic_gallery_email_enabled(array $config): bool
{
    $app = efpic_load_app_settings($config);
    $appEmail = $app['gallery_email'] ?? [];
    if (is_array($appEmail) && !empty($appEmail['enabled'])) {
        return true;
    }

    $gn = efpic_gallery_notify_cfg($config);

    return !empty($gn['enabled']);
}

function efpic_gallery_email_ready(array $config): bool
{
    if (!efpic_gallery_email_enabled($config)) {
        return false;
    }
    $email = efpic_gallery_email_cfg($config);

    return trim((string) ($email['from'] ?? '')) !== ''
        && (!empty($email['smtp_host']) || !empty($email['use_php_mail']));
}

function efpic_gallery_email_signature_text(array $config): string
{
    $html = efpic_gallery_email_signature_html($config);
    if ($html === '') {
        return '';
    }

    return efpic_html_to_plain_text($html);
}

function efpic_gallery_email_signature_html(array $config): string
{
    $settings = efpic_load_app_settings($config);
    $raw = trim((string) ($settings['gallery_email_signature'] ?? ''));
    if ($raw !== '') {
        if (preg_match('/<[^>]+>/', $raw)) {
            $html = efpic_sanitize_email_signature_html($raw);

            return efpic_email_absolutize_image_urls($config, $html);
        }

        return '<div>' . nl2br(htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</div>';
    }

    $legacyImage = efpic_site_signature_image_url($config);
    if ($legacyImage !== '') {
        $img = htmlspecialchars($legacyImage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<p><img src="' . $img . '" alt="" style="max-width:320px;height:auto;"></p>';
    }

    return '';
}

function efpic_email_absolutize_image_urls(array $config, string $html): string
{
    if ($html === '' || stripos($html, '<img') === false) {
        return $html;
    }
    $base = rtrim(efpic_base_url($config), '/');

    return preg_replace_callback(
        '/<img\b([^>]*)\bsrc=(["\'])([^"\']+)\2/i',
        static function (array $m) use ($config, $base): string {
            $src = trim($m[3]);
            if ($src === '' || preg_match('#^https?://#i', $src) || str_starts_with($src, 'cid:')) {
                return $m[0];
            }
            if (str_starts_with($src, '//')) {
                $parsed = parse_url($base);
                $scheme = $parsed['scheme'] ?? 'https';

                return '<img' . $m[1] . 'src="' . htmlspecialchars($scheme . ':' . $src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
            if (str_starts_with($src, '/')) {
                $abs = $base . $src;
            } else {
                $abs = $base . '/' . ltrim($src, '/');
            }
            $resolved = efpic_email_resolve_local_image_path($config, $abs);
            if ($resolved !== null) {
                $abs = efpic_email_public_url_for_local_image($config, $resolved) ?: $abs;
            }

            return '<img' . $m[1] . 'src="' . htmlspecialchars($abs, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        },
        $html,
    ) ?? $html;
}

function efpic_email_resolve_local_image_path(array $config, string $src): ?string
{
    $src = trim($src);
    if ($src === '') {
        return null;
    }

    $base = rtrim(efpic_base_url($config), '/');
    if (str_starts_with($src, $base)) {
        $src = substr($src, strlen($base));
    }
    $src = '/' . ltrim($src, '/');

    if (preg_match('#^/site/asset/([^/?#]+)$#', $src, $m)) {
        $path = efpic_site_asset_path($config, rawurldecode($m[1]));

        return is_file($path) ? $path : null;
    }
    if ($src === '/site/signature') {
        $settings = efpic_load_app_settings($config);
        $file = trim((string) ($settings['gallery_email_signature_image'] ?? ''));
        if ($file === '') {
            return null;
        }
        $path = efpic_site_asset_path($config, $file);

        return is_file($path) ? $path : null;
    }
    if (preg_match('#^/site/logo$#', $src)) {
        $settings = efpic_load_app_settings($config);
        $file = trim((string) ($settings['site_logo'] ?? ''));
        if ($file === '') {
            return null;
        }
        $path = efpic_site_asset_path($config, $file);

        return is_file($path) ? $path : null;
    }

    return null;
}

function efpic_email_public_url_for_local_image(array $config, string $path): string
{
    $assetsDir = efpic_site_assets_dir($config);
    if (str_starts_with($path, $assetsDir . DIRECTORY_SEPARATOR)) {
        $name = basename($path);

        return efpic_site_asset_public_url($config, $name);
    }

    return '';
}

/**
 * @return array{html: string, inline: list<array{cid: string, path: string, mime: string}>}
 */
function efpic_email_embed_inline_images(array $config, string $html): array
{
    $inline = [];
    if ($html === '' || stripos($html, '<img') === false) {
        return ['html' => $html, 'inline' => []];
    }

    $html = efpic_email_absolutize_image_urls($config, $html);
    $html = preg_replace_callback(
        '/<img\b([^>]*)\bsrc=(["\'])([^"\']+)\2([^>]*)>/i',
        static function (array $m) use ($config, &$inline): string {
            $src = $m[3];
            $path = efpic_email_resolve_local_image_path($config, $src);
            if ($path === null && preg_match('#^https?://#i', $src)) {
                $path = efpic_email_resolve_local_image_path($config, $src);
            }
            if ($path === null || !is_file($path)) {
                return $m[0];
            }
            $cid = 'efpic_' . bin2hex(random_bytes(8)) . '@efpic';
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                default => 'image/jpeg',
            };
            $inline[] = ['cid' => $cid, 'path' => $path, 'mime' => $mime];

            return '<img' . $m[1] . 'src="cid:' . $cid . '"' . $m[4] . '>';
        },
        $html,
    ) ?? $html;

    return ['html' => $html, 'inline' => $inline];
}

function efpic_email_zip_size_label(string $size): string
{
    return strtolower($size) === 'full' ? 'PRINT' : 'WEB';
}

function efpic_email_zip_ready_intro(int $collectionCount, string $size): string
{
    $label = efpic_email_zip_size_label($size);
    if ($collectionCount === 1) {
        return 'Tava izlase ' . $label . ' izmērā ir gatava lejupielādei.';
    }

    return 'Tavas izlases ' . $label . ' izmērā ir gatavas lejupielādei.';
}

/**
 * @param list<array{cid: string, path: string, mime: string}> $inlineAttachments
 */
function efpic_gallery_deliver_rich_email(
    array $config,
    string $to,
    string $subject,
    string $plainBody,
    string $htmlBody,
    array $inlineAttachments = [],
): void {
    $emailCfg = efpic_gallery_email_cfg($config);

    if (!empty($emailCfg['use_php_mail'])) {
        $from = (string) ($emailCfg['from'] ?? '');
        $fromName = (string) ($emailCfg['from_name'] ?? 'EdgarsFoto');
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = [
            'From: ' . $fromName . ' <' . $from . '>',
            'Reply-To: ' . $from,
            'MIME-Version: 1.0',
        ];
        if ($inlineAttachments === []) {
            $boundary = 'efpic_' . bin2hex(random_bytes(8));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $message = "--{$boundary}\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
                . $plainBody . "\r\n"
                . "--{$boundary}\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
                . $htmlBody . "\r\n"
                . "--{$boundary}--";
        } else {
            $mixed = 'efpic_mixed_' . bin2hex(random_bytes(8));
            $alt = 'efpic_alt_' . bin2hex(random_bytes(8));
            $rel = 'efpic_rel_' . bin2hex(random_bytes(8));
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $mixed . '"';
            $message = "--{$mixed}\r\n"
                . 'Content-Type: multipart/alternative; boundary="' . $alt . '"' . "\r\n\r\n"
                . "--{$alt}\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
                . $plainBody . "\r\n"
                . "--{$alt}\r\n"
                . 'Content-Type: multipart/related; boundary="' . $rel . '"' . "\r\n\r\n"
                . "--{$rel}\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
                . $htmlBody . "\r\n";
            foreach ($inlineAttachments as $att) {
                $data = file_get_contents($att['path']);
                if ($data === false) {
                    continue;
                }
                $message .= "--{$rel}\r\n"
                    . 'Content-Type: ' . $att['mime'] . '; name="' . basename($att['path']) . '"' . "\r\n"
                    . 'Content-Transfer-Encoding: base64' . "\r\n"
                    . 'Content-ID: <' . $att['cid'] . '>' . "\r\n"
                    . 'Content-Disposition: inline; filename="' . basename($att['path']) . '"' . "\r\n\r\n"
                    . chunk_split(base64_encode($data));
            }
            $message .= "--{$rel}--\r\n"
                . "--{$alt}--\r\n"
                . "--{$mixed}--";
        }
        @mail($to, $encodedSubject, $message, implode("\r\n", $headers));

        return;
    }

    efpic_guest_send_email_message($emailCfg, $to, $subject, $plainBody, $htmlBody, $inlineAttachments);
}

function efpic_gallery_email_with_signature(array $config, string $body): string
{
    $signature = efpic_gallery_email_signature_text($config);
    if ($signature === '') {
        return $body;
    }

    return rtrim($body) . "\n\n--\n" . $signature;
}

function efpic_gallery_email_html_body_with_signature(array $config, string $plainBody): string
{
    $escaped = htmlspecialchars($plainBody, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<div style="font-family:sans-serif;font-size:14px;line-height:1.5;color:#111;">'
        . nl2br($escaped, false) . '</div>';
    $signature = efpic_gallery_email_signature_html($config);
    if ($signature !== '') {
        $html .= '<div style="margin-top:24px;padding-top:16px;border-top:1px solid #ddd;font-family:sans-serif;font-size:14px;color:#111;">'
            . $signature . '</div>';
    }

    return $html;
}

/** @deprecated */
function efpic_gallery_email_html_with_signature(array $config, string $plainBody, string $signatureImageUrl): string
{
    unset($signatureImageUrl);

    return efpic_gallery_email_html_body_with_signature($config, $plainBody);
}

function efpic_gallery_deliver_email(array $config, string $to, string $subject, string $body): void
{
    $plainBody = efpic_gallery_email_with_signature($config, $body);
    $emailCfg = efpic_gallery_email_cfg($config);
    $signatureHtml = efpic_gallery_email_signature_html($config);
    $htmlBody = $signatureHtml !== '' ? efpic_gallery_email_html_body_with_signature($config, $body) : null;

    if (!empty($emailCfg['use_php_mail'])) {
        $from = (string) ($emailCfg['from'] ?? '');
        $fromName = (string) ($emailCfg['from_name'] ?? 'EdgarsFoto');
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        if ($htmlBody !== null) {
            $boundary = 'efpic_' . bin2hex(random_bytes(8));
            $headers = [
                'From: ' . $fromName . ' <' . $from . '>',
                'Reply-To: ' . $from,
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            ];
            $message = "--{$boundary}\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
                . $plainBody . "\r\n"
                . "--{$boundary}\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
                . $htmlBody . "\r\n"
                . "--{$boundary}--";
            @mail($to, $encodedSubject, $message, implode("\r\n", $headers));
        } else {
            $headers = [
                'From: ' . $fromName . ' <' . $from . '>',
                'Reply-To: ' . $from,
                'Content-Type: text/plain; charset=UTF-8',
                'MIME-Version: 1.0',
            ];
            @mail($to, $encodedSubject, $plainBody, implode("\r\n", $headers));
        }

        return;
    }

    efpic_guest_send_email_message($emailCfg, $to, $subject, $plainBody, $htmlBody);
}

function efpic_telegram_cfg(array $config): array
{
    $gn = efpic_gallery_notify_cfg($config);
    $tg = $gn['telegram'] ?? [];
    if (!is_array($tg)) {
        return [];
    }

    return $tg;
}

function efpic_telegram_enabled(array $config): bool
{
    $tg = efpic_telegram_cfg($config);

    return trim((string) ($tg['bot_token'] ?? '')) !== ''
        && trim((string) ($tg['chat_id'] ?? '')) !== '';
}

function efpic_telegram_notify(array $config, string $message): bool
{
    if (!efpic_telegram_enabled($config)) {
        return false;
    }
    $tg = efpic_telegram_cfg($config);
    $token = trim((string) ($tg['bot_token'] ?? ''));
    $chatId = trim((string) ($tg['chat_id'] ?? ''));
    if ($token === '' || $chatId === '') {
        return false;
    }

    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $post = http_build_query([
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => 'false',
    ]);

    $ch = curl_init($url);
    if ($ch === false) {
        return false;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $code >= 200 && $code < 300 && is_string($response);
}

function efpic_gallery_client_email(array $meta): string
{
    $access = $meta['client_access'] ?? [];

    return is_array($access) ? trim((string) ($access['email'] ?? '')) : '';
}

function efpic_gallery_client_phone(array $meta): string
{
    $access = $meta['client_access'] ?? [];

    return is_array($access) ? trim((string) ($access['phone'] ?? '')) : '';
}

function efpic_gallery_whatsapp_country_code(array $config): string
{
    $app = efpic_load_app_settings($config);
    $wa = $app['gallery_whatsapp'] ?? [];
    if (is_array($wa)) {
        $code = trim((string) ($wa['default_country_code'] ?? ''));
        if ($code !== '') {
            return $code;
        }
    }
    $gn = efpic_gallery_notify_cfg($config);

    return (string) ($gn['default_country_code'] ?? '371');
}

function efpic_gallery_whatsapp_link(
    array $config,
    array $meta,
    string $slug,
    string $group = 'gallery_ready',
): ?string {
    $phone = efpic_gallery_client_phone($meta);
    if ($phone === '') {
        return null;
    }
    $country = efpic_gallery_whatsapp_country_code($config);
    $digits = efpic_normalize_phone_digits($phone, $country);
    if ($digits === '') {
        return null;
    }

    $vars = efpic_gallery_notify_vars($config, $meta, $slug);
    $content = efpic_message_template_content($config, $meta, $slug, $group, 'whatsapp');
    $text = efpic_gallery_notify_replace($content['body'], $vars);
    if (trim($text) === '') {
        $text = 'Sveiki! Jūsu foto galerija «' . $vars['name'] . '» ir gatava.' . "\n" . ($vars['gallery_block'] ?? $vars['url']);
    }

    return 'https://wa.me/' . rawurlencode($digits) . '?text=' . rawurlencode($text);
}

function efpic_gallery_notify_template(array $config, string $key, ?array $meta = null, ?string $slug = null): array
{
    if ($meta !== null && $slug !== null) {
        return efpic_message_template_content($config, $meta, $slug, $key, 'email');
    }

    $tpl = efpic_message_templates_for($config, $key, 'email')[0] ?? null;
    if ($tpl !== null) {
        return [
            'subject' => (string) ($tpl['subject'] ?? ''),
            'body' => (string) ($tpl['body'] ?? ''),
        ];
    }

    $defaults = efpic_gallery_email_template_defaults();
    $default = $defaults[$key] ?? ['subject' => '', 'body' => ''];

    return [
        'subject' => (string) $default['subject'],
        'body' => (string) $default['body'],
    ];
}

function efpic_gallery_notify_replace(string $text, array $vars): string
{
    foreach ($vars as $key => $value) {
        $text = str_replace('{' . $key . '}', (string) $value, $text);
    }

    return $text;
}

function efpic_gallery_send_client_email(
    array $config,
    array $meta,
    string $slug,
    string $group,
    array $notifyOverrides = [],
): bool {
    if (!efpic_gallery_email_ready($config)) {
        throw new RuntimeException('E-pasts nav konfigurēts. Admin → Iestatījumi → E-pasts klientam.');
    }
    $to = efpic_gallery_client_email($meta);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Klienta e-pasts nav norādīts vai nav derīgs.');
    }

    $tpl = efpic_gallery_notify_template($config, $group, $meta, $slug);
    $vars = efpic_gallery_notify_vars($config, $meta, $slug, $notifyOverrides);
    $subject = efpic_gallery_notify_replace($tpl['subject'], $vars);
    $body = efpic_gallery_notify_replace($tpl['body'], $vars);

    try {
        efpic_gallery_deliver_email($config, $to, $subject, $body);
    } catch (Throwable $e) {
        throw new RuntimeException('E-pasts: ' . $e->getMessage(), 0, $e);
    }

    return true;
}

/** @return array{url: string, gallery_password: string, gallery_password_line: string, gallery_block: string} */
function efpic_gallery_notify_gallery_vars(array $config, array $meta, ?string $passwordOverride = null): array
{
    $gt = (string) ($meta['gallery_token'] ?? '');
    $url = $gt !== '' ? efpic_gallery_view_url($config, $gt) : '';
    $galleryPassword = $passwordOverride ?? '';
    $galleryPasswordLine = $galleryPassword !== '' ? 'Parole: ' . $galleryPassword : '';

    $galleryBlock = '';
    if ($url !== '') {
        $galleryBlock = 'Publiskā galerija:' . "\n" . $url;
        if ($galleryPasswordLine !== '') {
            $galleryBlock .= "\n" . $galleryPasswordLine;
        }
    }

    return [
        'url' => $url,
        'gallery_password' => $galleryPassword,
        'gallery_password_line' => $galleryPasswordLine,
        'gallery_block' => $galleryBlock,
    ];
}

/** @return array{portal_url: string, portal_password: string, portal_password_line: string, portal_block: string} */
function efpic_gallery_notify_portal_vars(array $config, array $meta, ?string $passwordOverride = null): array
{
    $portalToken = (string) ($meta['client_access']['portal_token'] ?? '');
    $portalUrl = $portalToken !== '' ? efpic_portal_url($config, $portalToken) : '';
    $portalPassword = $passwordOverride ?? '';
    $portalPasswordLine = $portalPassword !== '' ? 'Parole: ' . $portalPassword : '';

    $portalBlock = '';
    if ($portalUrl !== '') {
        $portalBlock = 'Klienta panelis: ' . $portalUrl;
        if ($portalPasswordLine !== '') {
            $portalBlock .= "\n" . $portalPasswordLine;
        }
    }

    return [
        'portal_url' => $portalUrl,
        'portal_password' => $portalPassword,
        'portal_password_line' => $portalPasswordLine,
        'portal_block' => $portalBlock,
    ];
}

/** @return array<string, string> */
function efpic_gallery_notify_vars(array $config, array $meta, string $slug, array $overrides = []): array
{
    $galleryPassword = array_key_exists('gallery_password', $overrides)
        ? (string) $overrides['gallery_password']
        : null;
    $portalPassword = array_key_exists('portal_password', $overrides)
        ? (string) $overrides['portal_password']
        : null;

    return array_merge([
        'name' => (string) ($meta['name'] ?? $slug),
        'expires' => efpic_gallery_expires_display($meta),
        'slug' => $slug,
    ], efpic_gallery_notify_gallery_vars($config, $meta, $galleryPassword), efpic_gallery_notify_portal_vars($config, $meta, $portalPassword));
}

function efpic_gallery_notification_sent(array $meta, string $key): bool
{
    $settings = efpic_gallery_settings($meta);
    $sent = $settings['notifications_sent'] ?? [];
    if (!is_array($sent)) {
        return false;
    }

    return !empty($sent[$key]);
}

function efpic_gallery_mark_notification_sent(array &$meta, string $key): void
{
    if (!isset($meta['settings']) || !is_array($meta['settings'])) {
        $meta['settings'] = [];
    }
    if (!isset($meta['settings']['notifications_sent']) || !is_array($meta['settings']['notifications_sent'])) {
        $meta['settings']['notifications_sent'] = [];
    }
    $meta['settings']['notifications_sent'][$key] = gmdate('c');
}

function efpic_gallery_days_until_expiry(array $meta): ?int
{
    $expires = efpic_gallery_settings($meta)['expires_at'] ?? null;
    if ($expires === null || $expires === '') {
        return null;
    }
    $ts = strtotime((string) $expires);
    if ($ts === false) {
        return null;
    }
    $diff = $ts - time();

    return (int) ceil($diff / 86400);
}

function efpic_gallery_on_activity(
    array $config,
    string $slug,
    array $meta,
    string $type,
    string $message,
    string $actor,
    array $extra,
): void {
    if (!efpic_telegram_enabled($config)) {
        return;
    }

    $gn = efpic_gallery_notify_cfg($config);
    $name = (string) ($meta['name'] ?? $slug);
    $telegramEvents = $gn['telegram_events'] ?? [
        'gallery_view',
        'client_portal_view',
        'image_hidden',
        'image_shown',
        'section_hidden',
        'section_shown',
        'download_image',
        'download_zip',
        'download_collection',
        'share_created',
        'expiry_reminder',
    ];
    if (!is_array($telegramEvents)) {
        $telegramEvents = [];
    }
    if (in_array('download_image', $telegramEvents, true)) {
        foreach (['download_zip', 'download_collection'] as $zipEvent) {
            if (!in_array($zipEvent, $telegramEvents, true)) {
                $telegramEvents[] = $zipEvent;
            }
        }
    }
    if (in_array('gallery_view', $telegramEvents, true)
        && !in_array('client_portal_view', $telegramEvents, true)) {
        $telegramEvents[] = 'client_portal_view';
    }

    if (!in_array($type, $telegramEvents, true)) {
        return;
    }

    if ($type === 'gallery_view' && empty($extra['first_view'])) {
        return;
    }

    $icon = match ($type) {
        'gallery_view' => '👁',
        'client_portal_view' => '📲',
        'image_hidden' => '🙈',
        'image_shown' => '👀',
        'section_hidden' => '📁🙈',
        'section_shown' => '📁',
        'download_image' => '⬇️',
        'download_zip' => '📦',
        'download_collection' => '📋',
        'share_created' => '🔗',
        'expiry_reminder' => '⏳',
        default => 'ℹ️',
    };

    $text = $icon . ' <b>' . htmlspecialchars($name, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</b>' . "\n"
        . htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $imageLabels = efpic_gallery_resolve_activity_image_labels($meta, $extra);
    if ($imageLabels !== []) {
        $show = array_slice($imageLabels, 0, 10);
        $line = implode(', ', $show);
        if (count($imageLabels) > 10) {
            $line .= ' (+' . (count($imageLabels) - 10) . ' bildes)';
        }
        if (!str_contains($message, $line) && !str_contains($message, $show[0] ?? '')) {
            $text .= "\n📷 " . htmlspecialchars($line, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }

    efpic_telegram_notify($config, $text);
}

function efpic_gallery_process_expiry_reminders(array $config): array
{
    $canEmail = efpic_gallery_email_ready($config);
    $canTelegram = efpic_gallery_notify_enabled($config) && efpic_telegram_enabled($config);
    if (!$canEmail && !$canTelegram) {
        return ['processed' => 0, 'sent' => 0];
    }

    $sent = 0;
    $processed = 0;
    $thresholds = [
        30 => 'expiry_reminder_30',
        7 => 'expiry_reminder_7',
    ];

    foreach (efpic_list_gallery_slugs($config) as $slug) {
        $meta = efpic_load_gallery_meta($config, $slug);
        if ($meta === null || !efpic_is_delivery_gallery($meta) || !efpic_gallery_is_active($meta)) {
            continue;
        }
        if (efpic_gallery_expired($meta)) {
            continue;
        }

        $days = efpic_gallery_days_until_expiry($meta);
        if ($days === null) {
            continue;
        }

        ++$processed;

        foreach ($thresholds as $dayLimit => $templateKey) {
            if ($days > $dayLimit) {
                continue;
            }
            if (efpic_gallery_notification_sent($meta, $templateKey)) {
                continue;
            }

            $msg = 'Atgādinājums: galerija beigsies pēc ~' . $days . ' dienām (līdz '
                . efpic_gallery_expires_display($meta) . ')';

            $emailReady = efpic_gallery_client_email($meta) !== '' && efpic_gallery_email_ready($config);
            $emailSent = false;
            if ($emailReady) {
                try {
                    efpic_gallery_send_client_email($config, $meta, $slug, $templateKey);
                    $emailSent = true;
                } catch (Throwable) {
                    // Atkārtos nākamajā apmeklējumā.
                }
            }

            if (!$emailReady || $emailSent) {
                efpic_gallery_mark_notification_sent($meta, $templateKey);
                efpic_gallery_log_activity(
                    $config,
                    $slug,
                    $meta,
                    'expiry_reminder',
                    $msg,
                    'system',
                    ['days_left' => $days, 'template' => $templateKey],
                );
                ++$sent;
            } elseif ($canTelegram) {
                efpic_gallery_on_activity(
                    $config,
                    $slug,
                    $meta,
                    'expiry_reminder',
                    $msg . ' (e-pasts neizdevās — tiks mēģināts vēlreiz)',
                    'system',
                    ['days_left' => $days, 'template' => $templateKey],
                );
            }
        }
    }

    return ['processed' => $processed, 'sent' => $sent];
}

function efpic_handle_gallery_notifications_run(array $config): void
{
    efpic_require_token($config);
    $result = efpic_gallery_process_expiry_reminders($config);
    efpic_json_response(200, ['ok' => true] + $result);
}

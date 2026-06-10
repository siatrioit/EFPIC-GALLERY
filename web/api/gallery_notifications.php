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

    $url = 'https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage';
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

function efpic_gallery_whatsapp_template(array $config, string $key): string
{
    $defaults = efpic_gallery_whatsapp_template_defaults();
    $default = (string) (($defaults[$key]['body'] ?? ''));

    $app = efpic_load_app_settings($config);
    $appTemplates = $app['gallery_whatsapp_templates'] ?? [];
    if (is_array($appTemplates)) {
        $appTpl = $appTemplates[$key] ?? [];
        if (is_array($appTpl) && trim((string) ($appTpl['body'] ?? '')) !== '') {
            return (string) $appTpl['body'];
        }
    }

    $gn = efpic_gallery_notify_cfg($config);
    $templates = $gn['whatsapp_templates'] ?? [];
    if (is_array($templates)) {
        $tpl = $templates[$key] ?? [];
        if (is_array($tpl) && trim((string) ($tpl['body'] ?? '')) !== '') {
            return (string) $tpl['body'];
        }
    }

    return $default;
}

function efpic_gallery_whatsapp_link(
    array $config,
    array $meta,
    string $slug,
    string $templateKey = 'gallery_ready',
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
    $bodyTpl = efpic_gallery_whatsapp_template($config, $templateKey);
    $text = efpic_gallery_notify_replace($bodyTpl, $vars);
    if (trim($text) === '') {
        $text = 'Sveiki! Jūsu foto galerija «' . $vars['name'] . '» ir gatava.' . "\n" . $vars['url'];
    }

    return 'https://wa.me/' . rawurlencode($digits) . '?text=' . rawurlencode($text);
}

function efpic_gallery_notify_template(array $config, string $key): array
{
    $defaults = efpic_gallery_email_template_defaults();
    $default = $defaults[$key] ?? ['subject' => '', 'body' => ''];

    $app = efpic_load_app_settings($config);
    $appTemplates = $app['gallery_email_templates'] ?? [];
    if (is_array($appTemplates)) {
        $appTpl = $appTemplates[$key] ?? [];
        if (is_array($appTpl) && trim((string) ($appTpl['subject'] ?? '')) !== '') {
            return [
                'subject' => (string) ($appTpl['subject'] ?? $default['subject']),
                'body' => (string) ($appTpl['body'] ?? $default['body']),
            ];
        }
    }

    $gn = efpic_gallery_notify_cfg($config);
    $templates = $gn['templates'] ?? [];
    if (!is_array($templates)) {
        $templates = [];
    }
    $tpl = $templates[$key] ?? [];
    if (!is_array($tpl)) {
        $tpl = [];
    }

    return [
        'subject' => (string) ($tpl['subject'] ?? $default['subject']),
        'body' => (string) ($tpl['body'] ?? $default['body']),
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
    string $templateKey,
): bool {
    if (!efpic_gallery_email_ready($config)) {
        throw new RuntimeException('E-pasts nav konfigurēts. Admin → Iestatījumi → E-pasts klientam.');
    }
    $to = efpic_gallery_client_email($meta);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Klienta e-pasts nav norādīts vai nav derīgs.');
    }

    $tpl = efpic_gallery_notify_template($config, $templateKey);
    $vars = efpic_gallery_notify_vars($config, $meta, $slug);
    $subject = efpic_gallery_notify_replace($tpl['subject'], $vars);
    $body = efpic_gallery_notify_replace($tpl['body'], $vars);
    $url = $vars['url'] ?? '';

    $emailCfg = efpic_gallery_email_cfg($config);
    if (!empty($emailCfg['use_php_mail'])) {
        $from = (string) ($emailCfg['from'] ?? '');
        $fromName = (string) ($emailCfg['from_name'] ?? 'EdgarsFoto');
        $headers = [
            'From: ' . $fromName . ' <' . $from . '>',
            'Reply-To: ' . $from,
            'Content-Type: text/plain; charset=UTF-8',
            'MIME-Version: 1.0',
        ];
        $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
        if (!$ok) {
            throw new RuntimeException('E-pasts: mail() neizdevās');
        }

        return true;
    }

    efpic_guest_send_smtp($emailCfg, $to, $subject, $body);

    return true;
}

/** @return array<string, string> */
function efpic_gallery_notify_vars(array $config, array $meta, string $slug): array
{
    $gt = (string) ($meta['gallery_token'] ?? '');

    return [
        'name' => (string) ($meta['name'] ?? $slug),
        'url' => $gt !== '' ? efpic_gallery_view_url($config, $gt) : '',
        'expires' => efpic_gallery_expires_display($meta),
        'slug' => $slug,
    ];
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
        'image_hidden',
        'image_shown',
        'section_hidden',
        'section_shown',
        'download_image',
        'share_created',
        'expiry_reminder',
    ];
    if (!is_array($telegramEvents)) {
        $telegramEvents = [];
    }

    if (!in_array($type, $telegramEvents, true)) {
        return;
    }

    if ($type === 'gallery_view' && empty($extra['first_view'])) {
        return;
    }

    $icon = match ($type) {
        'gallery_view' => '👁',
        'image_hidden' => '🙈',
        'image_shown' => '👀',
        'section_hidden' => '📁🙈',
        'section_shown' => '📁',
        'download_image' => '⬇️',
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

            try {
                if (efpic_gallery_client_email($meta) !== '' && efpic_gallery_email_ready($config)) {
                    efpic_gallery_send_client_email($config, $meta, $slug, $templateKey);
                }
            } catch (Throwable) {
                // continue — still log and notify photographer
            }

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

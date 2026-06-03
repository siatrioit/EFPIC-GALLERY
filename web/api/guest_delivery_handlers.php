<?php

declare(strict_types=1);

/** Automātiska SMS / e-pasts / WhatsApp (LumaShare-style) — caur serveri. */

function efpic_guest_delivery_cfg(array $config): array
{
    $gd = $config['guest_delivery'] ?? [];

    return is_array($gd) ? $gd : [];
}

function efpic_guest_delivery_message(array $gd, string $eventName, string $link): string
{
    $tpl = (string) ($gd['message_template'] ?? 'Šeit ir tava bilde{event}: {link}');
    $eventPart = $eventName !== '' ? ' (' . $eventName . ')' : '';

    return str_replace(
        ['{event}', '{link}'],
        [$eventPart, $link],
        $tpl,
    );
}

function efpic_guest_delivery_channel_enabled(array $gd, string $channel): bool
{
    if (empty($gd['enabled'])) {
        return false;
    }
    return match ($channel) {
        'sms' => !empty($gd['sms']['account_sid'])
            && !empty($gd['sms']['auth_token'])
            && !empty($gd['sms']['from']),
        'email' => !empty($gd['email']['from'])
            && (!empty($gd['email']['smtp_host']) || !empty($gd['email']['use_php_mail'])),
        'whatsapp' => !empty($gd['whatsapp']['phone_number_id'])
            && !empty($gd['whatsapp']['access_token']),
        default => false,
    };
}

function efpic_handle_guest_delivery_status(array $config): void
{
    efpic_require_token($config);
    $gd = efpic_guest_delivery_cfg($config);

    efpic_json_response(200, [
        'ok' => true,
        'channels' => [
            'sms' => efpic_guest_delivery_channel_enabled($gd, 'sms'),
            'email' => efpic_guest_delivery_channel_enabled($gd, 'email'),
            'whatsapp' => efpic_guest_delivery_channel_enabled($gd, 'whatsapp'),
        ],
        'default_country_code' => (string) ($gd['default_country_code'] ?? '371'),
    ]);
}

function efpic_handle_guest_delivery_send(array $config): void
{
    efpic_require_token($config);

    $raw = file_get_contents('php://input');
    $body = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($body)) {
        efpic_json_response(400, ['ok' => false, 'error' => 'invalid_json']);
    }

    $channel = strtolower(trim((string) ($body['channel'] ?? '')));
    $to = trim((string) ($body['to'] ?? ''));
    $imageUrl = trim((string) ($body['image_url'] ?? ''));
    $eventName = trim((string) ($body['event_name'] ?? ''));

    if (!in_array($channel, ['sms', 'email', 'whatsapp'], true)) {
        efpic_json_response(400, ['ok' => false, 'error' => 'invalid_channel']);
    }
    if ($to === '' || $imageUrl === '' || !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
        efpic_json_response(400, ['ok' => false, 'error' => 'invalid_params']);
    }

    $gd = efpic_guest_delivery_cfg($config);
    if (!efpic_guest_delivery_channel_enabled($gd, $channel)) {
        efpic_json_response(503, [
            'ok' => false,
            'error' => 'channel_not_configured',
            'message' => 'Kanāls nav konfigurēts serverī (config.php guest_delivery)',
        ]);
    }

    $message = efpic_guest_delivery_message($gd, $eventName, $imageUrl);

    try {
        match ($channel) {
            'sms' => efpic_guest_send_sms($gd, $to, $message),
            'email' => efpic_guest_send_email($gd, $to, $eventName, $message, $imageUrl),
            'whatsapp' => efpic_guest_send_whatsapp($gd, $to, $message),
        };
    } catch (Throwable $e) {
        efpic_json_response(502, [
            'ok' => false,
            'error' => 'send_failed',
            'message' => $e->getMessage(),
        ]);
    }

    efpic_json_response(200, ['ok' => true, 'channel' => $channel]);
}

function efpic_normalize_phone_digits(string $input, string $defaultCountry = '371'): string
{
    $d = preg_replace('/\D+/', '', $input) ?? '';
    if ($d === '') {
        return '';
    }
    if (str_starts_with($d, '00')) {
        $d = substr($d, 2);
    }
    $len = strlen($d);
    if ($len === 8 && $defaultCountry !== '') {
        $d = $defaultCountry . $d;
    }

    return $d;
}

function efpic_guest_send_sms(array $gd, string $to, string $message): void
{
    $sms = $gd['sms'] ?? [];
    $country = (string) ($gd['default_country_code'] ?? '371');
    $digits = efpic_normalize_phone_digits($to, $country);
    if ($digits === '') {
        throw new InvalidArgumentException('Nederīgs tālruņa numurs');
    }
    $e164 = '+' . $digits;

    $sid = (string) ($sms['account_sid'] ?? '');
    $token = (string) ($sms['auth_token'] ?? '');
    $from = (string) ($sms['from'] ?? '');

    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json';
    $post = http_build_query([
        'To' => $e164,
        'From' => $from,
        'Body' => $message,
    ]);

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl init failed');
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $sid . ':' . $token,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        $err = is_string($response) ? $response : 'Twilio error';
        throw new RuntimeException('SMS: HTTP ' . $code . ' — ' . $err);
    }
}

function efpic_guest_send_email(
    array $gd,
    string $to,
    string $eventName,
    string $message,
    string $imageUrl,
): void {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Nederīgs e-pasts');
    }

    $email = $gd['email'] ?? [];
    $from = (string) ($email['from'] ?? '');
    $fromName = (string) ($email['from_name'] ?? 'EFPIC');
    $subjectTpl = (string) ($email['subject'] ?? 'Tava bilde{event}');
    $eventPart = $eventName !== '' ? ' — ' . $eventName : '';
    $subject = str_replace('{event}', $eventPart, $subjectTpl);

    $body = $message . "\n\n" . $imageUrl;

    if (!empty($email['use_php_mail'])) {
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

        return;
    }

    efpic_guest_send_smtp($email, $to, $subject, $body);
}

function efpic_guest_send_smtp(array $email, string $to, string $subject, string $body): void
{
    $host = (string) ($email['smtp_host'] ?? '');
    $port = (int) ($email['smtp_port'] ?? 587);
    $user = (string) ($email['smtp_user'] ?? '');
    $pass = (string) ($email['smtp_pass'] ?? '');
    $from = (string) ($email['from'] ?? $user);
    $fromName = (string) ($email['from_name'] ?? 'EFPIC');
    $secure = strtolower((string) ($email['smtp_secure'] ?? 'tls'));

    if ($host === '') {
        throw new RuntimeException('SMTP nav konfigurēts');
    }

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 20);
    if ($fp === false) {
        throw new RuntimeException('SMTP savienojums: ' . $errstr);
    }

    $read = static function () use ($fp): string {
        $data = '';
        while ($line = fgets($fp, 515)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        return $data;
    };

    $write = static function (string $cmd) use ($fp): void {
        fwrite($fp, $cmd . "\r\n");
    };

    $read();
    $write('EHLO efpic.local');
    $read();

    if ($secure === 'tls') {
        $write('STARTTLS');
        $read();
        stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $write('EHLO efpic.local');
        $read();
    }

    if ($user !== '') {
        $write('AUTH LOGIN');
        $read();
        $write(base64_encode($user));
        $read();
        $write(base64_encode($pass));
        $read();
    }

    $write('MAIL FROM:<' . $from . '>');
    $read();
    $write('RCPT TO:<' . $to . '>');
    $read();
    $write('DATA');
    $read();

    $msg = "From: {$fromName} <{$from}>\r\n";
    $msg .= "To: <{$to}>\r\n";
    $msg .= 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $msg .= "\r\n";
    $msg .= $body;
    $msg .= "\r\n.\r\n";
    $write($msg);
    $read();
    $write('QUIT');
    fclose($fp);
}

function efpic_guest_send_whatsapp(array $gd, string $to, string $message): void
{
    $wa = $gd['whatsapp'] ?? [];
    $country = (string) ($gd['default_country_code'] ?? '371');
    $digits = efpic_normalize_phone_digits($to, $country);
    if ($digits === '') {
        throw new InvalidArgumentException('Nederīgs tālruņa numurs');
    }

    $phoneId = (string) ($wa['phone_number_id'] ?? '');
    $token = (string) ($wa['access_token'] ?? '');
    $version = (string) ($wa['graph_version'] ?? 'v21.0');

    $url = 'https://graph.facebook.com/' . $version . '/' . rawurlencode($phoneId) . '/messages';
    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'to' => $digits,
        'type' => 'text',
        'text' => ['preview_url' => true, 'body' => $message],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl init failed');
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        $err = is_string($response) ? $response : 'WhatsApp API error';
        throw new RuntimeException('WhatsApp: HTTP ' . $code . ' — ' . $err);
    }
}

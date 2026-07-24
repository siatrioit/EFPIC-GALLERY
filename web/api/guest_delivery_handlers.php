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
    efpic_guest_send_email_message($email, $to, $subject, $body, null);
}

function efpic_guest_send_email_message(array $email, string $to, string $subject, string $body, ?string $htmlBody = null, array $inlineAttachments = []): void
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

    $encodeHeader = static function (string $value): string {
        if ($value === '' || preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    };

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 20);
    if ($fp === false) {
        throw new RuntimeException('SMTP savienojums: ' . $errstr);
    }
    stream_set_timeout($fp, 120);

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

    $writeRaw = static function (string $payload) use ($fp): void {
        $len = strlen($payload);
        $written = 0;
        while ($written < $len) {
            $n = fwrite($fp, substr($payload, $written));
            if ($n === false || $n === 0) {
                $meta = stream_get_meta_data($fp);
                $timedOut = !empty($meta['timed_out']);
                throw new RuntimeException($timedOut
                    ? 'SMTP rakstīšanas timeout'
                    : 'SMTP rakstīšana pārtrūka');
            }
            $written += $n;
        }
    };

    $write = static function (string $cmd) use ($writeRaw): void {
        $writeRaw($cmd . "\r\n");
    };

    $expectOk = static function (string $resp, string $step): void {
        if (preg_match('/^[23]\d\d/', $resp) !== 1) {
            $short = trim(preg_replace('/\s+/', ' ', $resp) ?? $resp);
            if (strlen($short) > 180) {
                $short = substr($short, 0, 177) . '…';
            }
            throw new RuntimeException('SMTP ' . $step . ': ' . ($short !== '' ? $short : 'nezināma kļūda'));
        }
    };

    try {
        $expectOk($read(), 'savienojums');
        $heloHost = 'efpic.gallery';
        if (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '') {
            $heloHost = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']) ?: $heloHost;
        }
        $write('EHLO ' . $heloHost);
        $expectOk($read(), 'EHLO');

        if ($secure === 'tls') {
            $write('STARTTLS');
            $expectOk($read(), 'STARTTLS');
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP STARTTLS kriptēšana neizdevās');
            }
            $write('EHLO ' . $heloHost);
            $expectOk($read(), 'EHLO pēc TLS');
        }

        if ($user !== '') {
            $write('AUTH LOGIN');
            $expectOk($read(), 'AUTH');
            $write(base64_encode($user));
            $expectOk($read(), 'AUTH lietotājs');
            $write(base64_encode($pass));
            $expectOk($read(), 'AUTH parole');
        }

        $write('MAIL FROM:<' . $from . '>');
        $expectOk($read(), 'MAIL FROM');
        $write('RCPT TO:<' . $to . '>');
        $expectOk($read(), 'RCPT TO');
        $write('DATA');
        $expectOk($read(), 'DATA');

        $fromHeader = $encodeHeader($fromName) . ' <' . $from . '>';
        $msg = 'From: ' . $fromHeader . "\r\n";
        $msg .= 'To: <' . $to . ">\r\n";
        $msg .= 'Date: ' . gmdate('D, d M Y H:i:s') . " +0000\r\n";
        $msg .= 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $heloHost . ">\r\n";
        $msg .= 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        if ($htmlBody !== null && $htmlBody !== '') {
            $pack = efpic_email_multipart_body($body, $htmlBody, $inlineAttachments);
            $msg .= 'Content-Type: ' . $pack['contentType'] . "\r\n\r\n";
            $msg .= $pack['body'];
        } else {
            $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $msg .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $msg .= $body;
        }
        // SMTP prasa CRLF un punktiņu escape rindām, kas sākas ar "."
        $msg = str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $msg);
        $msg = preg_replace('/^\./m', '..', $msg) ?? $msg;
        if (!str_ends_with($msg, "\r\n")) {
            $msg .= "\r\n";
        }
        $writeRaw($msg . ".\r\n");
        $expectOk($read(), 'sūtīšana');
        $write('QUIT');
    } finally {
        fclose($fp);
    }
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

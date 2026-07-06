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
    $legacyImage = efpic_site_signature_image_url($config);
    $raw = trim((string) ($settings['gallery_email_signature'] ?? ''));
    if ($raw !== '') {
        if (preg_match('/<[^>]+>/', $raw)) {
            $html = efpic_sanitize_email_signature_html($raw);
            $html = efpic_email_absolutize_image_urls($config, $html);
            $html = efpic_email_signature_prune_broken_images($config, $html);
            if ($legacyImage !== '' && !preg_match('/<img\b/i', $html)) {
                $img = htmlspecialchars($legacyImage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                return '<p><img src="' . $img . '" alt="" style="max-width:120px;height:auto;"></p>' . $html;
            }

            return $html;
        }

        return '<div>' . nl2br(htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</div>';
    }

    if ($legacyImage !== '') {
        $img = htmlspecialchars($legacyImage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<p><img src="' . $img . '" alt="" style="max-width:320px;height:auto;"></p>';
    }

    return '';
}

function efpic_email_signature_prune_broken_images(array $config, string $html): string
{
    if ($html === '' || stripos($html, '<img') === false) {
        return $html;
    }

    return preg_replace_callback(
        '/<img\b([^>]*)\bsrc=(["\'])([^"\']*)\2([^>]*)>/i',
        static function (array $m) use ($config): string {
            $src = trim(html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($src === '') {
                return '';
            }
            if (efpic_email_resolve_local_image_path($config, $src) !== null) {
                return $m[0];
            }
            if (preg_match('#^https?://#i', $src) && efpic_email_cache_remote_image($src) !== null) {
                return $m[0];
            }

            return '';
        },
        $html,
    ) ?? $html;
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
 * Sagatavo e-pasta HTML ar absolūtām attēlu saitēm (bez CID MIME daļām).
 * Gmail un citi klienti citādi rāda iegultos attēlus kā pielikumus.
 *
 * @return array{html: string, inline: list<array{cid: string, path: string, mime: string}>}
 */
function efpic_email_embed_inline_images(array $config, string $html): array
{
    if ($html === '' || stripos($html, '<img') === false) {
        return ['html' => $html, 'inline' => []];
    }

    return [
        'html' => efpic_email_absolutize_image_urls($config, $html),
        'inline' => [],
    ];
}

/**
 * @param list<array{cid: string, path: string, mime: string}> $inlineAttachments
 * @return array{contentType: string, body: string}
 */
function efpic_email_multipart_body(string $plainBody, string $htmlBody, array $inlineAttachments = []): array
{
    if ($inlineAttachments === []) {
        $boundary = 'efpic_' . bin2hex(random_bytes(8));

        return [
            'contentType' => 'multipart/alternative; boundary="' . $boundary . '"',
            'body' => "--{$boundary}\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
                . $plainBody . "\r\n"
                . "--{$boundary}\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
                . $htmlBody . "\r\n"
                . "--{$boundary}--",
        ];
    }

    $alt = 'efpic_alt_' . bin2hex(random_bytes(8));
    $rel = 'efpic_rel_' . bin2hex(random_bytes(8));
    $body = "--{$alt}\r\n"
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
        $body .= "--{$rel}\r\n"
            . 'Content-Type: ' . $att['mime'] . "\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . 'Content-ID: <' . $att['cid'] . '>' . "\r\n"
            . "Content-Disposition: inline\r\n\r\n"
            . chunk_split(base64_encode($data));
    }

    $body .= "--{$rel}--\r\n"
        . "--{$alt}--";

    return [
        'contentType' => 'multipart/alternative; boundary="' . $alt . '"',
        'body' => $body,
    ];
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

function efpic_gallery_email_button_style(): string
{
    return 'display:inline-block;min-width:152px;width:152px;max-width:152px;text-align:center;box-sizing:border-box;'
        . 'font-size:14px;font-weight:600;padding:12px 10px;border-radius:8px;line-height:1.2;text-decoration:none;';
}

function efpic_gallery_email_copy_link_url(array $config, string $targetUrl): string
{
    $payload = rtrim(strtr(base64_encode($targetUrl), '+/', '-_'), '=');

    return efpic_base_url($config) . '/e/copy?u=' . rawurlencode($payload);
}

function efpic_gallery_email_copy_link_decode(string $encoded): ?string
{
    $encoded = trim($encoded);
    if ($encoded === '') {
        return null;
    }
    $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
    if (!is_string($decoded) || $decoded === '' || !preg_match('#^https?://#i', $decoded)) {
        return null;
    }

    return $decoded;
}

function efpic_gallery_email_copy_link_allowed(array $config, string $url): bool
{
    $base = rtrim(efpic_base_url($config), '/');
    if ($base === '' || !str_starts_with($url, $base . '/')) {
        return false;
    }

    return preg_match('#^' . preg_quote($base, '#') . '/(?:v/g|c/p)/[a-f0-9]{48}(?:[/?#]|$)#i', $url) === 1;
}

function efpic_handle_email_copy_link_page(array $config): void
{
    $url = efpic_gallery_email_copy_link_decode((string) ($_GET['u'] ?? ''));
    if ($url === null || !efpic_gallery_email_copy_link_allowed($config, $url)) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="lv"><head><meta charset="utf-8"><title>Saite nav atrasta</title></head>'
            . '<body style="font-family:sans-serif;padding:32px;color:#444;"><p>Saite nav derīga.</p></body></html>';
        exit;
    }

    $urlEsc = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="lv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Kopēt saiti</title></head><body style="margin:0;padding:32px 16px;background:#f0eeeb;font-family:Helvetica,Arial,sans-serif;color:#1a1a1a;">'
        . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:14px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,0.06);">'
        . '<h1 style="margin:0 0 12px;font-size:22px;font-weight:600;">Kopēt saiti</h1>'
        . '<p style="margin:0 0 16px;font-size:15px;line-height:1.5;color:#444;">Atlasi saiti un nokopē, vai spied pogu zemāk.</p>'
        . '<input id="efpic-copy-url" readonly value="' . $urlEsc . '" style="width:100%;box-sizing:border-box;padding:12px 14px;border:1px solid #e8e4df;border-radius:8px;font-size:14px;margin:0 0 16px;">'
        . '<div style="display:flex;flex-wrap:wrap;gap:10px;">'
        . '<button type="button" id="efpic-copy-btn" style="min-width:152px;padding:12px 16px;border:0;border-radius:8px;background:#1a1a1a;color:#fff;font-size:14px;font-weight:600;cursor:pointer;">Kopēt saiti</button>'
        . '<a href="' . $urlEsc . '" style="min-width:152px;padding:12px 16px;border-radius:8px;background:#fff;color:#1a1a1a;border:1px solid #d8d3cd;font-size:14px;font-weight:600;text-decoration:none;text-align:center;box-sizing:border-box;">Atvērt</a>'
        . '</div><p id="efpic-copy-status" style="margin:14px 0 0;font-size:13px;color:#2f6b3b;min-height:18px;"></p></div>'
        . '<script>(function(){var input=document.getElementById("efpic-copy-url");var btn=document.getElementById("efpic-copy-btn");var status=document.getElementById("efpic-copy-status");function setStatus(msg){if(status)status.textContent=msg||"";}function copy(){var text=input?input.value:"";if(!text)return Promise.reject();if(navigator.clipboard&&navigator.clipboard.writeText){return navigator.clipboard.writeText(text);}input.focus();input.select();input.setSelectionRange(0,text.length);try{document.execCommand("copy");return Promise.resolve();}catch(e){return Promise.reject(e);}}if(btn){btn.addEventListener("click",function(){copy().then(function(){setStatus("Saite nokopēta.");}).catch(function(){setStatus("Neizdevās automātiski — atlasi tekstu un nokopē manuāli.");});}if(input){input.addEventListener("focus",function(){input.select();});}})();</script>'
        . '</body></html>';
    exit;
}

function efpic_gallery_email_render_link_card(
    array $config,
    string $title,
    string $url,
    string $passwordLine = '',
    string $buttonLabel = 'Atvērt',
): string {
    $titleEsc = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $urlEsc = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $btnEsc = htmlspecialchars($buttonLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $metaLine = $passwordLine !== ''
        ? htmlspecialchars($passwordLine, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        : '';
    $btnStyle = efpic_gallery_email_button_style();
    $copyUrlEsc = htmlspecialchars(efpic_gallery_email_copy_link_url($config, $url), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 12px;background:#faf9f7;border:1px solid #e8e4df;border-radius:10px;">'
        . '<tr><td style="padding:16px 18px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="vertical-align:middle;padding:0 16px 0 0;">'
        . '<div style="font-size:15px;font-weight:600;color:#1a1a1a;margin:0 0 4px;line-height:1.35;">' . $titleEsc . '</div>';
    if ($metaLine !== '') {
        $html .= '<div style="font-size:13px;color:#6b6560;margin:0;line-height:1.4;">' . $metaLine . '</div>';
    }
    $html .= '</td>'
        . '<td style="vertical-align:middle;width:152px;white-space:nowrap;text-align:right;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" align="right"><tr>'
        . '<td style="padding:0 0 8px;text-align:center;">'
        . '<a href="' . $urlEsc . '" style="' . $btnStyle . 'background:#1a1a1a;color:#ffffff;">' . $btnEsc . '</a>'
        . '</td></tr><tr><td style="text-align:center;">'
        . '<a href="' . $copyUrlEsc . '" style="' . $btnStyle . 'background:#ffffff;color:#1a1a1a;border:1px solid #d8d3cd;">Kopēt saiti</a>'
        . '</td></tr></table>'
        . '</td></tr></table>'
        . '</td></tr></table>';

    return $html;
}

function efpic_gallery_email_link_card_button_label(string $url, string $title): string
{
    if (str_contains($url, '/c/p/') || stripos($title, 'panel') !== false) {
        return 'Atvērt paneli';
    }
    if (str_contains($url, '/v/g/')) {
        return 'Atvērt galeriju';
    }

    return 'Atvērt saiti';
}

function efpic_gallery_email_plain_to_inner_html(array $config, string $plain): string
{
    $plain = trim(str_replace(["\r\n", "\r"], "\n", $plain));
    if ($plain === '') {
        return '';
    }

    $html = '';
    $blocks = preg_split('/\n\s*\n/', $plain) ?: [];
    foreach ($blocks as $block) {
        $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $block)), static fn ($line) => $line !== ''));
        if ($lines === []) {
            continue;
        }

        $urlIndex = null;
        foreach ($lines as $index => $line) {
            if (preg_match('#^https?://#i', $line) === 1) {
                $urlIndex = $index;
                break;
            }
        }

        if ($urlIndex !== null) {
            $url = $lines[$urlIndex];
            $title = $urlIndex > 0 ? implode(' ', array_slice($lines, 0, $urlIndex)) : 'Saite';
            $passwordLine = '';
            if (isset($lines[$urlIndex + 1]) && str_starts_with($lines[$urlIndex + 1], 'Parole:')) {
                $passwordLine = $lines[$urlIndex + 1];
            }
            $html .= efpic_gallery_email_render_link_card(
                $config,
                $title,
                $url,
                $passwordLine,
                efpic_gallery_email_link_card_button_label($url, $title),
            );
            continue;
        }

        $html .= '<p style="margin:0 0 16px;font-size:15px;line-height:1.55;color:#444;">'
            . nl2br(htmlspecialchars(implode("\n", $lines), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false)
            . '</p>';
    }

    return $html;
}

/**
 * @return array{html: string, inline: list<array{cid: string, path: string, mime: string}>}
 */
function efpic_gallery_email_transactional_pack(
    array $config,
    array $meta,
    string $innerHtml,
    string $documentTitle = '',
): array {
    $settings = efpic_load_app_settings($config);
    $byline = trim((string) ($settings['gallery_byline'] ?? 'Gallery by EdgarsFoto'));
    $galleryName = htmlspecialchars((string) ($meta['name'] ?? 'Galerija'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $bylineEsc = htmlspecialchars($byline, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $titleEsc = htmlspecialchars(
        $documentTitle !== '' ? $documentTitle : (string) ($meta['name'] ?? 'Galerija'),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8',
    );

    $signatureEmbedded = efpic_email_embed_inline_images($config, efpic_gallery_email_signature_html($config));
    $signatureBlock = '';
    if ($signatureEmbedded['html'] !== '') {
        $signatureBlock = '<tr><td style="padding:20px 28px 24px;border-top:1px solid #e8e4df;font-family:Helvetica,Arial,sans-serif;">'
            . '<div style="font-size:14px;line-height:1.55;color:#333;">' . $signatureEmbedded['html'] . '</div></td></tr>';
    }

    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $titleEsc . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f0eeeb;font-family:Georgia,\'Times New Roman\',serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0eeeb;padding:32px 16px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.06);">'
        . '<tr><td style="background:#1a1a1a;padding:28px 28px 24px;text-align:center;">'
        . '<div style="font-size:11px;letter-spacing:0.12em;text-transform:uppercase;color:#b8b0a8;margin:0 0 8px;">' . $bylineEsc . '</div>'
        . '<div style="font-size:22px;font-weight:400;color:#ffffff;margin:0;line-height:1.3;">' . $galleryName . '</div>'
        . '</td></tr>'
        . '<tr><td style="padding:28px 28px 24px;font-family:Helvetica,Arial,sans-serif;">'
        . $innerHtml
        . '</td></tr>'
        . $signatureBlock
        . '</table></td></tr></table></body></html>';

    return ['html' => $html, 'inline' => $signatureEmbedded['inline']];
}

function efpic_gallery_email_build_from_plain(array $config, array $meta, string $plainBody, string $documentTitle = ''): array
{
    $innerHtml = efpic_gallery_email_plain_to_inner_html($config, $plainBody);
    $pack = efpic_gallery_email_transactional_pack($config, $meta, $innerHtml, $documentTitle);

    return [
        'plain' => efpic_gallery_email_with_signature($config, $plainBody),
        'html' => $pack['html'],
        'inline' => $pack['inline'],
    ];
}

function efpic_gallery_email_build_from_html(array $config, array $meta, string $contentHtml, string $documentTitle = ''): array
{
    $contentHtml = efpic_email_absolutize_image_urls($config, efpic_sanitize_email_signature_html($contentHtml));
    $plainMain = efpic_html_to_plain_text($contentHtml);
    $pack = efpic_gallery_email_transactional_pack($config, $meta, $contentHtml, $documentTitle);

    return [
        'plain' => efpic_gallery_email_with_signature($config, $plainMain),
        'html' => $pack['html'],
        'inline' => $pack['inline'],
    ];
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
            $pack = efpic_email_multipart_body($plainBody, $htmlBody);
            $headers[] = 'Content-Type: ' . $pack['contentType'];
            $message = $pack['body'];
        } else {
            $pack = efpic_email_multipart_body($plainBody, $htmlBody, $inlineAttachments);
            $headers[] = 'Content-Type: ' . $pack['contentType'];
            $message = $pack['body'];
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

function efpic_gallery_deliver_email(array $config, string $to, string $subject, string $body, array $meta = []): void
{
    if ($meta === []) {
        $emailCfg = efpic_gallery_email_cfg($config);
        $plainBody = efpic_gallery_email_with_signature($config, $body);
        efpic_guest_send_email_message($emailCfg, $to, $subject, $plainBody, null);

        return;
    }

    $built = efpic_gallery_email_build_from_plain($config, $meta, $body, $subject);
    efpic_gallery_deliver_rich_email($config, $to, $subject, $built['plain'], $built['html'], $built['inline']);
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

function efpic_gallery_deliver_composed_email(
    array $config,
    string $to,
    string $subject,
    string $bodyHtml,
    array $meta,
): void {
    $built = efpic_gallery_email_build_from_html($config, $meta, $bodyHtml, $subject);
    efpic_gallery_deliver_rich_email($config, $to, $subject, $built['plain'], $built['html'], $built['inline']);
}

function efpic_gallery_send_client_email(
    array $config,
    array $meta,
    string $slug,
    string $group,
    array $notifyOverrides = [],
    string $customSubject = '',
    string $customBodyHtml = '',
): bool {
    if (!efpic_gallery_email_ready($config)) {
        throw new RuntimeException('E-pasts nav konfigurēts. Admin → Iestatījumi → E-pasts klientam.');
    }
    $to = efpic_gallery_client_email($meta);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Klienta e-pasts nav norādīts vai nav derīgs.');
    }

    if (trim($customBodyHtml) !== '') {
        $subject = trim($customSubject);
        if ($subject === '') {
            $tpl = efpic_gallery_notify_template($config, $group, $meta, $slug);
            $vars = efpic_gallery_notify_vars($config, $meta, $slug, $notifyOverrides);
            $subject = efpic_gallery_notify_replace($tpl['subject'], $vars);
        }
        try {
            efpic_gallery_deliver_composed_email($config, $to, $subject, $customBodyHtml, $meta);
        } catch (Throwable $e) {
            throw new RuntimeException('E-pasts: ' . $e->getMessage(), 0, $e);
        }

        return true;
    }

    $tpl = efpic_gallery_notify_template($config, $group, $meta, $slug);
    $vars = efpic_gallery_notify_vars($config, $meta, $slug, $notifyOverrides);
    $subject = efpic_gallery_notify_replace($tpl['subject'], $vars);
    $body = efpic_gallery_notify_replace($tpl['body'], $vars);

    try {
        efpic_gallery_deliver_email($config, $to, $subject, $body, $meta);
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
    $galleryPassword = $passwordOverride !== null
        ? trim($passwordOverride)
        : efpic_gallery_password_plain($meta);
    $galleryPasswordLine = $galleryPassword !== '' && efpic_gallery_has_password($meta)
        ? 'Parole: ' . $galleryPassword
        : '';
    $title = trim((string) ($meta['name'] ?? ''));

    $galleryBlock = '';
    if ($url !== '') {
        $lines = [$title !== '' ? $title : 'Publiskā galerija', $url];
        if ($galleryPasswordLine !== '') {
            $lines[] = $galleryPasswordLine;
        }
        $galleryBlock = implode("\n", $lines);
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
    $portalPassword = $passwordOverride !== null
        ? trim($passwordOverride)
        : efpic_client_portal_password_plain($meta);
    $portalPasswordLine = $portalPassword !== '' && efpic_client_portal_has_password($meta)
        ? 'Parole: ' . $portalPassword
        : '';

    $portalBlock = '';
    if ($portalUrl !== '' && efpic_client_portal_enabled($meta)) {
        $lines = ['Klienta panelis', $portalUrl];
        if ($portalPasswordLine !== '') {
            $lines[] = $portalPasswordLine;
        }
        $portalBlock = implode("\n", $lines);
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

function efpic_gallery_telegram_title(array $meta, string $slug): string
{
    $name = (string) ($meta['name'] ?? $slug);
    $dateRaw = substr((string) ($meta['event_date'] ?? ''), 0, 10);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw) === 1) {
        return str_replace('-', '.', $dateRaw) . ' ' . $name;
    }

    return $name;
}

function efpic_gallery_telegram_format_visitor_zip(string $message, string $actor, array $extra): ?string
{
    $collections = is_array($extra['collections'] ?? null) ? $extra['collections'] : [];
    $visitorName = trim((string) ($extra['visitor_name'] ?? ''));
    $visitorEmail = trim((string) ($extra['visitor_email'] ?? ''));
    if ($visitorEmail === '' && str_starts_with($actor, 'visitor:')) {
        $visitorEmail = substr($actor, 8);
    }
    if ($collections === [] && $visitorName === '' && $visitorEmail === '') {
        return null;
    }

    $lines = [htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8')];
    if ($visitorName !== '') {
        $lines[] = '👤 ' . htmlspecialchars($visitorName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if ($visitorEmail !== '') {
        $lines[] = '✉️ ' . htmlspecialchars($visitorEmail, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    foreach ($collections as $collection) {
        if (!is_array($collection)) {
            continue;
        }
        $name = (string) ($collection['name'] ?? 'Izlase');
        $count = (int) ($collection['count'] ?? 0);
        $lines[] = '• «' . htmlspecialchars($name, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '» (' . $count . ' bildes)';
    }

    return implode("\n", $lines);
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
    $galleryTitle = efpic_gallery_telegram_title($meta, $slug);
    $telegramEvents = efpic_gallery_telegram_events_list($gn);

    if (!in_array($type, $telegramEvents, true)) {
        return;
    }

    if (!empty($extra['skip_telegram'])) {
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
        'visitor_collection_download' => '📋',
        'visitor_share_download' => '📋',
        'share_created' => '🔗',
        'expiry_reminder' => '⏳',
        default => 'ℹ️',
    };

    $visitorZipTypes = ['visitor_collection_download', 'visitor_share_download', 'download_collection'];
    $visitorZipText = in_array($type, $visitorZipTypes, true)
        ? efpic_gallery_telegram_format_visitor_zip($message, $actor, $extra)
        : null;

    if ($visitorZipText !== null) {
        $text = $icon . ' <b>' . htmlspecialchars($galleryTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</b>' . "\n"
            . $visitorZipText;
        efpic_telegram_notify($config, $text);

        return;
    }

    $text = $icon . ' <b>' . htmlspecialchars($galleryTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</b>' . "\n"
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

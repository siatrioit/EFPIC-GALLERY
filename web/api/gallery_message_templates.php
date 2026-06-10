<?php

declare(strict_types=1);

/** @return array<string, string> */
function efpic_message_template_groups(): array
{
    return [
        'gallery_ready' => 'Jauna galerija',
        'expiry_reminder_30' => 'Atgādinājums 30 dienas',
        'expiry_reminder_7' => 'Atgādinājums 7 dienas',
    ];
}

function efpic_message_template_group_label(string $group): string
{
    return efpic_message_template_groups()[$group] ?? $group;
}

function efpic_message_template_new_id(): string
{
    return 'tpl_' . bin2hex(random_bytes(6));
}

/** @return list<array<string, mixed>> */
function efpic_message_templates_seed(): array
{
    $emailDefaults = efpic_gallery_email_template_defaults();
    $waDefaults = efpic_gallery_whatsapp_template_defaults();
    $out = [];
    foreach (efpic_message_template_groups() as $group => $label) {
        $out[] = [
            'id' => efpic_message_template_new_id(),
            'name' => $label . ' — e-pasts',
            'group' => $group,
            'channel' => 'email',
            'subject' => (string) ($emailDefaults[$group]['subject'] ?? ''),
            'body' => (string) ($emailDefaults[$group]['body'] ?? ''),
        ];
        $out[] = [
            'id' => efpic_message_template_new_id(),
            'name' => $label . ' — WhatsApp',
            'group' => $group,
            'channel' => 'whatsapp',
            'subject' => '',
            'body' => (string) ($waDefaults[$group]['body'] ?? ''),
        ];
    }

    return $out;
}

/** @param array<string, mixed> $settings */
function efpic_message_templates_migrate_legacy(array $settings): array
{
    $existing = $settings['message_templates'] ?? [];
    if (is_array($existing) && $existing !== []) {
        return $existing;
    }

    $emailLegacy = $settings['gallery_email_templates'] ?? [];
    $waLegacy = $settings['gallery_whatsapp_templates'] ?? [];
    if (!is_array($emailLegacy) && !is_array($waLegacy)) {
        return efpic_message_templates_seed();
    }

    $out = [];
    foreach (efpic_message_template_groups() as $group => $label) {
        $em = is_array($emailLegacy[$group] ?? null) ? $emailLegacy[$group] : [];
        if (trim((string) ($em['subject'] ?? '')) !== '' || trim((string) ($em['body'] ?? '')) !== '') {
            $out[] = [
                'id' => efpic_message_template_new_id(),
                'name' => $label . ' — e-pasts',
                'group' => $group,
                'channel' => 'email',
                'subject' => (string) ($em['subject'] ?? ''),
                'body' => (string) ($em['body'] ?? ''),
            ];
        }
        $wa = is_array($waLegacy[$group] ?? null) ? $waLegacy[$group] : [];
        if (trim((string) ($wa['body'] ?? '')) !== '') {
            $out[] = [
                'id' => efpic_message_template_new_id(),
                'name' => $label . ' — WhatsApp',
                'group' => $group,
                'channel' => 'whatsapp',
                'subject' => '',
                'body' => (string) ($wa['body'] ?? ''),
            ];
        }
    }

    return $out !== [] ? $out : efpic_message_templates_seed();
}

/** @return list<array<string, mixed>> */
function efpic_message_templates_all(array $config): array
{
    $settings = efpic_load_app_settings($config);
    $templates = efpic_message_templates_migrate_legacy($settings);
    if (!is_array($templates)) {
        return efpic_message_templates_seed();
    }

    return array_values(array_filter($templates, static fn ($t) => is_array($t) && ($t['id'] ?? '') !== ''));
}

function efpic_message_template_by_id(array $config, string $id): ?array
{
    $id = trim($id);
    if ($id === '') {
        return null;
    }
    foreach (efpic_message_templates_all($config) as $tpl) {
        if ((string) ($tpl['id'] ?? '') === $id) {
            return $tpl;
        }
    }

    return null;
}

/** @return list<array<string, mixed>> */
function efpic_message_templates_for(array $config, string $group, string $channel): array
{
    $out = [];
    foreach (efpic_message_templates_all($config) as $tpl) {
        if ((string) ($tpl['group'] ?? '') === $group && (string) ($tpl['channel'] ?? '') === $channel) {
            $out[] = $tpl;
        }
    }

    return $out;
}

/** @return array<string, array{email: string, whatsapp: string}> */
function efpic_gallery_client_message_selections(array $meta): array
{
    $settings = efpic_gallery_settings($meta);
    $raw = $settings['client_messages'] ?? [];
    if (!is_array($raw)) {
        $raw = [];
    }
    $out = [];
    foreach (array_keys(efpic_message_template_groups()) as $group) {
        $row = is_array($raw[$group] ?? null) ? $raw[$group] : [];
        $out[$group] = [
            'email' => trim((string) ($row['email'] ?? '')),
            'whatsapp' => trim((string) ($row['whatsapp'] ?? '')),
        ];
    }

    return $out;
}

function efpic_message_template_resolve(
    array $config,
    array $meta,
    string $group,
    string $channel,
): ?array {
    $selections = efpic_gallery_client_message_selections($meta);
    $selectedId = $selections[$group][$channel] ?? '';
    if ($selectedId !== '') {
        $tpl = efpic_message_template_by_id($config, $selectedId);
        if ($tpl !== null) {
            return $tpl;
        }
    }

    $candidates = efpic_message_templates_for($config, $group, $channel);

    return $candidates[0] ?? null;
}

/** @return array{subject: string, body: string} */
function efpic_message_template_content(array $config, array $meta, string $slug, string $group, string $channel): array
{
    $tpl = efpic_message_template_resolve($config, $meta, $group, $channel);
    if ($tpl === null) {
        $emailDefaults = efpic_gallery_email_template_defaults();
        $waDefaults = efpic_gallery_whatsapp_template_defaults();
        if ($channel === 'email') {
            $d = $emailDefaults[$group] ?? ['subject' => '', 'body' => ''];

            return ['subject' => (string) $d['subject'], 'body' => (string) $d['body']];
        }
        $d = $waDefaults[$group] ?? ['body' => ''];

        return ['subject' => '', 'body' => (string) $d['body']];
    }

    return [
        'subject' => (string) ($tpl['subject'] ?? ''),
        'body' => (string) ($tpl['body'] ?? ''),
    ];
}

/** @param array<string, mixed> $meta */
function efpic_apply_gallery_client_messages_from_post(array &$meta): void
{
    if (!array_key_exists('client_msg_gallery_ready_email', $_POST)) {
        return;
    }
    if (!isset($meta['settings']) || !is_array($meta['settings'])) {
        $meta['settings'] = efpic_gallery_defaults('delivery')['settings'];
    }
    $messages = [];
    foreach (array_keys(efpic_message_template_groups()) as $group) {
        $messages[$group] = [
            'email' => trim((string) ($_POST['client_msg_' . $group . '_email'] ?? '')),
            'whatsapp' => trim((string) ($_POST['client_msg_' . $group . '_whatsapp'] ?? '')),
        ];
    }
    $meta['settings']['client_messages'] = $messages;
}

/** @return list<array<string, mixed>> */
function efpic_admin_parse_message_templates_from_post(): array
{
    $ids = $_POST['msg_tpl_id'] ?? [];
    $names = $_POST['msg_tpl_name'] ?? [];
    $groups = $_POST['msg_tpl_group'] ?? [];
    $channels = $_POST['msg_tpl_channel'] ?? [];
    $subjects = $_POST['msg_tpl_subject'] ?? [];
    $bodies = $_POST['msg_tpl_body'] ?? [];
    $delete = $_POST['msg_tpl_delete'] ?? [];
    if (!is_array($ids)) {
        return efpic_message_templates_seed();
    }

    $deleteSet = [];
    if (is_array($delete)) {
        foreach ($delete as $delId) {
            $delId = trim((string) $delId);
            if ($delId !== '') {
                $deleteSet[$delId] = true;
            }
        }
    }

    $out = [];
    $groupKeys = array_keys(efpic_message_template_groups());
    foreach ($ids as $i => $id) {
        $id = trim((string) $id);
        if ($id === '' || isset($deleteSet[$id])) {
            continue;
        }
        $name = trim((string) ($names[$i] ?? ''));
        $group = trim((string) ($groups[$i] ?? ''));
        $channel = trim((string) ($channels[$i] ?? ''));
        $body = trim((string) ($bodies[$i] ?? ''));
        $subject = trim((string) ($subjects[$i] ?? ''));
        if ($name === '' && $body === '') {
            continue;
        }
        if (!in_array($group, $groupKeys, true)) {
            $group = 'gallery_ready';
        }
        if (!in_array($channel, ['email', 'whatsapp'], true)) {
            $channel = 'email';
        }
        if ($name === '') {
            $name = efpic_message_template_group_label($group) . ' — ' . $channel;
        }
        $out[] = [
            'id' => $id,
            'name' => $name,
            'group' => $group,
            'channel' => $channel,
            'subject' => $subject,
            'body' => $body,
        ];
    }

    if (!empty($_POST['msg_tpl_new_name']) || !empty($_POST['msg_tpl_new_body'])) {
        $newGroup = trim((string) ($_POST['msg_tpl_new_group'] ?? 'gallery_ready'));
        $newChannel = trim((string) ($_POST['msg_tpl_new_channel'] ?? 'email'));
        if (!in_array($newGroup, $groupKeys, true)) {
            $newGroup = 'gallery_ready';
        }
        if (!in_array($newChannel, ['email', 'whatsapp'], true)) {
            $newChannel = 'email';
        }
        $newName = trim((string) ($_POST['msg_tpl_new_name'] ?? ''));
        $newBody = trim((string) ($_POST['msg_tpl_new_body'] ?? ''));
        if ($newName !== '' || $newBody !== '') {
            if ($newName === '') {
                $newName = efpic_message_template_group_label($newGroup) . ' — jauna';
            }
            $out[] = [
                'id' => efpic_message_template_new_id(),
                'name' => $newName,
                'group' => $newGroup,
                'channel' => $newChannel,
                'subject' => trim((string) ($_POST['msg_tpl_new_subject'] ?? '')),
                'body' => $newBody,
            ];
        }
    }

    return $out !== [] ? $out : efpic_message_templates_seed();
}

/** @return array<string, string> */
function efpic_message_template_variables(): array
{
    return [
        'name' => 'Galerijas nosaukums (piem. «Riharda un Annikas kāzas»)',
        'expires' => 'Derīguma termiņš lasāmā formā (piem. «8. jūn. 2027»)',
        'slug' => 'Galerijas iekšējais identifikators (mapes nosaukums)',
        'url' => 'Tikai publiskās galerijas saite',
        'gallery_password' => 'Tikai publiskās galerijas parole (tukšs, ja nav)',
        'gallery_password_line' => 'Rinda «Parole: …» publiskajai galerijai (tukša, ja nav paroles)',
        'gallery_block' => 'Gatavs bloks: virsraksts + publiskā saite + parole (ja ir)',
        'portal_url' => 'Tikai klienta paneļa saite',
        'portal_password' => 'Tikai klienta paneļa parole (tukšs, ja nav)',
        'portal_password_line' => 'Rinda «Parole: …» klienta panelim (tukša, ja nav paroles)',
        'portal_block' => 'Gatavs bloks: klienta panelis + saite + parole (ja ir)',
    ];
}

function efpic_admin_render_message_template_variables_help(): string
{
    $html = '<div class="admin-template-vars-help">';
    $html .= '<p class="muted">Veido sagataves un piešķiri tās grupai. Tekstā vari lietot šādus mainīgos — tie tiek aizstāti ar konkrētās galerijas datiem:</p>';
    $html .= '<ul class="admin-template-vars-list">';
    foreach (efpic_message_template_variables() as $var => $desc) {
        $html .= '<li><code>{' . efpic_admin_esc($var) . '}</code> — ' . efpic_admin_esc($desc) . '</li>';
    }
    $html .= '</ul>';
    $html .= '<p class="muted">Ērtāk lietot gatavos blokus <code>{gallery_block}</code> un <code>{portal_block}</code> — '
        . 'tie automātiski iekļauj saiti un paroli, ja tāda ir.</p>';
    $html .= '</div>';

    return $html;
}

function efpic_admin_render_message_templates_fieldset(array $config): string
{
    $templates = efpic_message_templates_all($config);
    $groups = efpic_message_template_groups();

    $html = '<fieldset class="admin-fieldset-full"><legend>Ziņu sagataves</legend>';
    $html .= efpic_admin_render_message_template_variables_help();

    $html .= '<div class="admin-table-wrap"><table class="admin-table admin-message-templates-table">';
    $html .= '<thead><tr><th>Nosaukums</th><th>Grupa</th><th>Kanāls</th><th>Temats</th><th>Teksts</th><th></th></tr></thead><tbody>';
    foreach ($templates as $tpl) {
        $id = (string) ($tpl['id'] ?? '');
        $channel = (string) ($tpl['channel'] ?? 'email');
        $html .= '<tr>';
        $html .= '<td><input name="msg_tpl_name[]" value="' . efpic_admin_esc((string) ($tpl['name'] ?? '')) . '">'
            . '<input type="hidden" name="msg_tpl_id[]" value="' . efpic_admin_esc($id) . '"></td>';
        $html .= '<td><select name="msg_tpl_group[]">';
        foreach ($groups as $gKey => $gLabel) {
            $sel = ($tpl['group'] ?? '') === $gKey ? ' selected' : '';
            $html .= '<option value="' . efpic_admin_esc($gKey) . '"' . $sel . '>' . efpic_admin_esc($gLabel) . '</option>';
        }
        $html .= '</select></td>';
        $html .= '<td><select name="msg_tpl_channel[]">';
        foreach (['email' => 'E-pasts', 'whatsapp' => 'WhatsApp'] as $cKey => $cLabel) {
            $sel = $channel === $cKey ? ' selected' : '';
            $html .= '<option value="' . efpic_admin_esc($cKey) . '"' . $sel . '>' . efpic_admin_esc($cLabel) . '</option>';
        }
        $html .= '</select></td>';
        $html .= '<td><input name="msg_tpl_subject[]" value="' . efpic_admin_esc((string) ($tpl['subject'] ?? '')) . '"'
            . ($channel === 'whatsapp' ? ' placeholder="—"' : '') . '></td>';
        $html .= '<td><textarea name="msg_tpl_body[]" rows="4">' . efpic_admin_esc((string) ($tpl['body'] ?? '')) . '</textarea></td>';
        $html .= '<td><label class="admin-check"><input type="checkbox" name="msg_tpl_delete[]" value="'
            . efpic_admin_esc($id) . '"> Dzēst</label></td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';

    $html .= '<fieldset class="admin-fieldset-full admin-message-tpl-new"><legend>Pievienot jaunu sagatavi</legend>';
    $html .= '<div class="admin-form-layout admin-form-layout--basic">';
    $html .= '<label>Nosaukums<input name="msg_tpl_new_name" placeholder="Piem. Kāzas — formāls"></label>';
    $html .= '<label>Grupa<select name="msg_tpl_new_group">';
    foreach ($groups as $gKey => $gLabel) {
        $html .= '<option value="' . efpic_admin_esc($gKey) . '">' . efpic_admin_esc($gLabel) . '</option>';
    }
    $html .= '</select></label>';
    $html .= '<label>Kanāls<select name="msg_tpl_new_channel"><option value="email">E-pasts</option><option value="whatsapp">WhatsApp</option></select></label>';
    $html .= '<label>Temats (e-pasts)<input name="msg_tpl_new_subject"></label>';
    $html .= '<label class="admin-fieldset-full">Teksts<textarea name="msg_tpl_new_body" rows="5"></textarea></label>';
    $html .= '</div></fieldset></fieldset>';

    return $html;
}

function efpic_admin_render_gallery_client_messages(array $config, array $meta, string $slug): string
{
    $groups = efpic_message_template_groups();
    $selections = efpic_gallery_client_message_selections($meta);
    $hasEmail = efpic_gallery_email_ready($config);
    $hasPhone = efpic_gallery_client_phone($meta) !== '';

    $html = '<fieldset class="admin-fieldset-full admin-client-messages"><legend>Ziņojumi klientam</legend>';
    $html .= '<p class="muted">Izvēlies sagatavi katrai grupai. Sagataves veido <a href="settings.php">Iestatījumi → Ziņu sagataves</a>. '
        . 'Pēc sagatavju izvēles maiņas saglabā galeriju — WhatsApp saite atjaunojas pēc saglabāšanas.</p>';

    foreach ($groups as $group => $label) {
        $html .= '<div class="admin-client-msg-group">';
        $html .= '<h3 class="admin-share-block-title">' . efpic_admin_esc($label) . '</h3>';
        $html .= '<div class="admin-form-layout admin-form-layout--basic">';

        $emailOptions = efpic_message_templates_for($config, $group, 'email');
        $html .= '<label>E-pasta sagatave<select name="client_msg_' . efpic_admin_esc($group) . '_email">';
        $html .= '<option value="">— noklusējums —</option>';
        foreach ($emailOptions as $tpl) {
            $tid = (string) ($tpl['id'] ?? '');
            $sel = ($selections[$group]['email'] ?? '') === $tid ? ' selected' : '';
            $html .= '<option value="' . efpic_admin_esc($tid) . '"' . $sel . '>' . efpic_admin_esc((string) ($tpl['name'] ?? $tid)) . '</option>';
        }
        $html .= '</select></label>';

        $waOptions = efpic_message_templates_for($config, $group, 'whatsapp');
        $html .= '<label>WhatsApp sagatave<select name="client_msg_' . efpic_admin_esc($group) . '_whatsapp">';
        $html .= '<option value="">— noklusējums —</option>';
        foreach ($waOptions as $tpl) {
            $tid = (string) ($tpl['id'] ?? '');
            $sel = ($selections[$group]['whatsapp'] ?? '') === $tid ? ' selected' : '';
            $html .= '<option value="' . efpic_admin_esc($tid) . '"' . $sel . '>' . efpic_admin_esc((string) ($tpl['name'] ?? $tid)) . '</option>';
        }
        $html .= '</select></label>';
        $html .= '</div>';

        $html .= '<div class="admin-client-msg-actions">';
        if ($hasEmail && efpic_gallery_client_email($meta) !== '') {
            $html .= '<button type="submit" class="btn" name="send_client_email" value="' . efpic_admin_esc($group) . '">Sūtīt e-pastu</button>';
        }
        if ($hasPhone) {
            $waLink = efpic_gallery_whatsapp_link($config, $meta, $slug, $group);
            if ($waLink !== null) {
                $html .= '<a class="btn" href="' . efpic_admin_esc($waLink) . '" target="_blank" rel="noopener">Sūtīt WhatsApp</a>';
            }
        }
        $html .= '</div></div>';
    }

    if (!$hasEmail) {
        $html .= '<p class="muted">E-pasta sūtīšanai ieslēdz SMTP <a href="settings.php">Iestatījumos</a>.</p>';
    }
    if (!$hasPhone) {
        $html .= '<p class="muted">WhatsApp sūtīšanai ievadi klienta tālruni augstāk.</p>';
    }

    $html .= '</fieldset>';

    return $html;
}

<?php

declare(strict_types=1);

const EFPIC_ACTIVITY_LOG_MAX = 500;

/** @return list<array<string, mixed>> */
function efpic_gallery_activity_log(array $meta): array
{
    $log = $meta['activity_log'] ?? [];
    if (!is_array($log)) {
        return [];
    }

    return $log;
}

function efpic_gallery_log_activity(
    array $config,
    string $slug,
    array &$meta,
    string $type,
    string $message,
    string $actor = 'system',
    array $extra = [],
): void {
    if (!isset($meta['activity_log']) || !is_array($meta['activity_log'])) {
        $meta['activity_log'] = [];
    }

    $entry = array_merge([
        'at' => gmdate('c'),
        'type' => $type,
        'message' => $message,
        'actor' => $actor,
    ], $extra);

    $meta['activity_log'][] = $entry;
    if (count($meta['activity_log']) > EFPIC_ACTIVITY_LOG_MAX) {
        $meta['activity_log'] = array_slice($meta['activity_log'], -EFPIC_ACTIVITY_LOG_MAX);
    }

    efpic_save_gallery_meta($config, $slug, $meta);

    if (function_exists('efpic_gallery_on_activity')) {
        efpic_gallery_on_activity($config, $slug, $meta, $type, $message, $actor, $extra);
    }
}

/** @return list<string> */
function efpic_gallery_telegram_events_list(array $gn): array
{
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
        'visitor_collection_download',
        'visitor_share_download',
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
    foreach (['download_image', 'download_zip', 'download_collection'] as $trigger) {
        if (!in_array($trigger, $telegramEvents, true)) {
            continue;
        }
        foreach (['visitor_collection_download', 'visitor_share_download'] as $zipEvent) {
            if (!in_array($zipEvent, $telegramEvents, true)) {
                $telegramEvents[] = $zipEvent;
            }
        }
        break;
    }
    if (in_array('gallery_view', $telegramEvents, true)
        && !in_array('client_portal_view', $telegramEvents, true)) {
        $telegramEvents[] = 'client_portal_view';
    }

    return $telegramEvents;
}

function efpic_gallery_activity_type_label(string $type): string
{
    return match ($type) {
        'gallery_created' => 'Izveide',
        'gallery_view' => 'Atvēršana',
        'client_portal_view' => 'Klienta panelis',
        'image_hidden' => 'Bilde paslēpta',
        'image_shown' => 'Bilde rādīta',
        'section_hidden' => 'Sadaļa paslēpta',
        'section_shown' => 'Sadaļa rādīta',
        'download_image' => 'Lejupielāde',
        'download_zip' => 'ZIP lejupielāde',
        'download_collection' => 'Izlases lejupielāde',
        'visitor_collection_identify' => 'Apmeklētāja izlase',
        'visitor_collection_create' => 'Jauna izlase',
        'visitor_collection_rename' => 'Izlases pārsaukšana',
        'visitor_collection_add' => 'Izlasei pievienota bilde',
        'visitor_collection_remove' => 'No izlases noņemta bilde',
        'visitor_collection_download' => 'Izlases ZIP pieprasījums',
        'visitor_collection_email_sent' => 'Izlases ZIP e-pasts nosūtīts',
        'visitor_share_download' => 'Kopīgojamās izlases ZIP',
        'share_created' => 'Kopīgošana',
        'expiry_changed' => 'Termiņš',
        'sync' => 'Sinhronizācija',
        'gallery_ready_email' => 'E-pasts klientam',
        'expiry_reminder' => 'Termiņa atgādinājums',
        'admin_save' => 'Admin saglabāšana',
        default => $type,
    };
}

function efpic_gallery_format_activity_time(string $iso): string
{
    $ts = strtotime($iso);
    if ($ts === false) {
        return $iso;
    }

    return date('Y-m-d H:i', $ts);
}

function efpic_admin_render_activity_log(array $meta): string
{
    $log = efpic_gallery_activity_log($meta);
    if ($log === []) {
        return '<p class="muted">Vēl nav reģistrētu aktivitāšu.</p>';
    }

    $rows = '';
    foreach (array_reverse($log) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $at = efpic_gallery_format_activity_time((string) ($entry['at'] ?? ''));
        $type = efpic_gallery_activity_type_label((string) ($entry['type'] ?? ''));
        $message = (string) ($entry['message'] ?? '');
        $actor = (string) ($entry['actor'] ?? '');
        $actorLabel = match ($actor) {
            'guest' => 'Viesis',
            'client' => 'Klients',
            'admin' => 'Admin',
            'system' => 'Sistēma',
            default => $actor !== '' ? $actor : '—',
        };
        $rows .= '<tr><td class="muted">' . efpic_admin_esc($at) . '</td>';
        $rows .= '<td>' . efpic_admin_esc($type) . '</td>';
        $rows .= '<td>' . efpic_admin_esc($message) . '</td>';
        $rows .= '<td class="muted">' . efpic_admin_esc($actorLabel) . '</td></tr>';
    }

    return '<div class="admin-table-wrap"><table class="admin-table admin-activity-log">'
        . '<thead><tr><th>Laiks</th><th>Notikums</th><th>Apraksts</th><th>Avots</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table></div>';
}

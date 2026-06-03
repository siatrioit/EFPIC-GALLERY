<?php
/**
 * Copy to config.php (not in git).
 */
return [
    'app_version' => '1.0.0',
    'base_url' => 'https://klientiem.edgarsfoto.lv',
    'api_token' => 'change-me-long-random-string',
    'dashboard_password' => 'change-me-strong-password',
    'storage_path' => __DIR__ . '/../storage/galleries',
    'booth_events_path' => __DIR__ . '/../storage/booth_events',
    'templates_path' => __DIR__ . '/../storage/gallery_templates',
    'max_upload_bytes' => 25 * 1024 * 1024,
    'allowed_extensions' => ['jpg', 'jpeg'],

    /**
     * Failiem.lv / Files.fm — failu glabātuve klientu piegādes galerijām.
     * Publiskām LINK mapēm bieži pietiek bez atslēgas (api.files.fm list).
     */
    'failiem' => [
        'enabled' => true,
        'api_base' => 'https://api.files.fm',
        'cdn_base' => 'https://failiem.lv',
        'api_key' => '',
        'user' => '',
        'pass' => '',
        'pair_suffix_strip' => ['_WEB', '_PRINT', '_web', '_print', '-web', '-small'],
    ],

    'guest_delivery' => [
        'enabled' => false,
        'default_country_code' => '371',
        'message_template' => 'Šeit ir tava bilde{event}: {link}',
        'sms' => ['account_sid' => '', 'auth_token' => '', 'from' => ''],
        'email' => [
            'from' => 'noreply@edgarsfoto.lv',
            'from_name' => 'Edgars Foto',
            'subject' => 'Tava bilde{event}',
            'use_php_mail' => true,
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_secure' => 'tls',
            'smtp_user' => '',
            'smtp_pass' => '',
        ],
        'whatsapp' => [
            'phone_number_id' => '',
            'access_token' => '',
            'graph_version' => 'v21.0',
        ],
    ],
];

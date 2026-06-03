<?php

declare(strict_types=1);

/**
 * CLI tests: php scripts/test_sync.php
 * Izveido testa galeriju un sinhronizē no Failiem (publiskām mapēm).
 */

$root = dirname(__DIR__);
require_once $root . '/web/api/handlers.php';

$config = efpic_load_config();

$slug = 'test-pasakums';
$dir = efpic_gallery_dir($config, $slug);
if (!is_dir($dir)) {
    $created = efpic_create_delivery_gallery($config, [
        'name' => 'Testa pasākums',
        'slug' => $slug,
        'folder_full_url' => 'https://failiem.lv/u/3989fkmbt7',
        'folder_web_url' => 'https://api.files.fm/api/get_file_list_for_upload.php?hash=nbhn7ymedk',
    ]);
    echo "Created: {$created['slug']}\n";
} else {
    echo "Using existing: {$slug}\n";
}

$meta = efpic_load_gallery_meta($config, $slug);
if ($meta !== null) {
    $meta['failiem']['folder_full_hash'] = 'q3v7u5vysz';
    $meta['failiem']['folder_web_hash'] = 'nbhn7ymedk';
    efpic_save_gallery_meta($config, $slug, $meta);
}

$result = efpic_sync_delivery_gallery($config, $slug);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

$meta = efpic_load_gallery_meta($config, $slug);
echo 'Images: ' . count($meta['images'] ?? []) . "\n";
echo 'Gallery URL: ' . efpic_gallery_view_url($config, (string) $meta['gallery_token']) . "\n";

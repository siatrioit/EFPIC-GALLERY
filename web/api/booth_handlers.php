<?php

declare(strict_types=1);

/** Foto kaste — minimāli API (saderība ar EFPIC mobilā lietotne). */

function efpic_handle_list_booth_events(array $config): void
{
    efpic_require_token($config);
    efpic_json_response(200, ['ok' => true, 'events' => []]);
}

function efpic_handle_get_booth_event_sync(array $config, string $code): void
{
    efpic_require_token($config);
    efpic_json_response(404, ['ok' => false, 'error' => 'not_found', 'code' => $code]);
}

function efpic_handle_get_booth_frame(array $config, string $id): void
{
    efpic_require_token($config);
    http_response_code(404);
    exit;
}

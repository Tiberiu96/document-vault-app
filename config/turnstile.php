<?php

return [
    'enabled' => (bool) env('TURNSTILE_ENABLED', false),
    'site_key' => env('TURNSTILE_SITE_KEY', ''),
    'secret_key' => env('TURNSTILE_SECRET_KEY', ''),
    // Gol: hostname-ul din APP_URL. Nu se foloseste antetul Host primit.
    'hostnames' => array_values(array_filter(array_map('trim', explode(',', (string) env('TURNSTILE_HOSTNAMES', ''))))),
];

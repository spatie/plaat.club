<?php

use App\Http\Middleware\DemoLoginLinks;
use App\Http\Middleware\PrivateResponse;

$appUrl = env('APP_URL', 'http://localhost');

return [
    'allowed_environments' => ['local'],

    // Allow this orb's exact portal host, not every host on a shared domain.
    'allowed_hosts' => array_values(array_unique(array_filter([
        'localhost', '127.0.0.1', is_string($appUrl) ? parse_url($appUrl, PHP_URL_HOST) : null,
    ]))),

    'automatically_create_missing_users' => false,

    'demo_users' => [
        'alex@example.test' => 'Alex',
        'robin@example.test' => 'Robin',
        'sam@example.test' => 'Sam',
        'casey@example.test' => 'Casey',
        'jamie@example.test' => 'Jamie',
        'freek@example.test' => 'Freek',
    ],

    'middleware' => ['web', 'auth.session', PrivateResponse::class, DemoLoginLinks::class, 'throttle:30,1'],
];

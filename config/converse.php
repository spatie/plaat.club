<?php

use App\Http\Middleware\EnsureAiSearchEnabled;
use App\Http\Middleware\PrivateResponse;

return [
    'connections' => [
        'web' => [
            'driver' => 'web',
            'middleware' => ['web', 'auth', 'auth.session', 'verified', PrivateResponse::class, EnsureAiSearchEnabled::class, 'throttle:collectionQuestions'],
            'guests' => false,
            'queue' => true,
            'run_timeout' => 330,
            'idle_timeout' => 330,
            'transport' => 'poll',
            'poll_interval' => 0.5,
        ],
    ],
    'attachments' => ['max_files' => 0],
];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Chat Platform Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure credentials for every chat platform connection your
    | bots use. Each entry names a connection along with its own credentials,
    | and a single platform may appear multiple times with its own tokens.
    |
    | Web connections serve a chat in your own pages instead. Each one gets a
    | route at "/converse/{bot}/{connection}", wrapped in the middleware you
    | list, and reads the signed in user from its "guard", or from your
    | default guard when "guard" is null. Set "guests" to let visitors chat.
    |
    | While a run is live, its stream pings every "stream_heartbeat" seconds,
    | so a closed tab frees its worker quickly. Set it to zero to stop it.
    |
    | A stream holds a worker for as long as its run is live. Set "transport"
    | to "poll" and the browser asks for new events every "poll_interval"
    | seconds instead, so that no request waits for the run to finish.
    |
    */

    'connections' => [
        'slack' => [
            'driver' => 'slack',
            'bot_token' => env('SLACK_BOT_TOKEN'),
            'webhook_secret' => env('SLACK_WEBHOOK_SECRET'),
        ],

        'telegram' => [
            'driver' => 'telegram',
            'bot_token' => env('TELEGRAM_BOT_TOKEN'),
            'bot_username' => env('TELEGRAM_BOT_USERNAME'),
            'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        ],

        'github' => [
            'driver' => 'github',
            'app_id' => env('GITHUB_APP_ID'),
            'private_key' => env('GITHUB_PRIVATE_KEY'),
            'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
            'base_url' => env('GITHUB_API_URL', 'https://api.github.com'),
        ],

        'web' => [
            'driver' => 'web',
            'middleware' => ['web'],
            'guard' => env('CONVERSE_WEB_GUARD'),
            'guests' => env('CONVERSE_WEB_GUESTS', false),
            'queue' => env('CONVERSE_WEB_QUEUE', true),
            'run_timeout' => env('CONVERSE_WEB_RUN_TIMEOUT', 60),
            'idle_timeout' => env('CONVERSE_WEB_IDLE_TIMEOUT', 30),
            'transport' => env('CONVERSE_WEB_TRANSPORT', 'stream'),
            'stream_interval' => env('CONVERSE_WEB_STREAM_INTERVAL', 0.1),
            'stream_heartbeat' => env('CONVERSE_WEB_STREAM_HEARTBEAT', 2),
            'poll_interval' => env('CONVERSE_WEB_POLL_INTERVAL', 2),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Store
    |--------------------------------------------------------------------------
    |
    | Converse stores ephemeral coordination, including locks and deduplication
    | markers and per-thread scheduling flags, in the configured cache store.
    | When null, the default application cache store will be used instead.
    |
    */

    'cache' => [
        'store' => env('CONVERSE_CACHE_STORE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Storage
    |--------------------------------------------------------------------------
    |
    | Converse stores durable threads and their full message transcripts using
    | the database connection configured below. When this option is null, the
    | application's default database connection will be used automatically.
    |
    */

    'storage' => [
        'connection' => env('CONVERSE_DB_CONNECTION'),
        'retention' => env('CONVERSE_STORAGE_RETENTION', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Connection
    |--------------------------------------------------------------------------
    |
    | Webhook requests are acknowledged immediately and the real work then runs
    | on your queue. Here you may choose the queue connection and the queue
    | name used for that work. When null, your own defaults will be used.
    |
    */

    'queue' => [
        'connection' => env('CONVERSE_QUEUE_CONNECTION'),
        'name' => env('CONVERSE_QUEUE_NAME'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound Attachments
    |--------------------------------------------------------------------------
    |
    | Files that arrive on a message are downloaded once and staged on the disk
    | configured below before any handler runs. It follows your application's
    | own filesystem disk unless you point it somewhere else.
    |
    | Keep that disk private: these are files your users uploaded. Media types
    | outside the allow-list below are discarded rather than staged.
    |
    */

    'attachments' => [
        'disk' => env('CONVERSE_ATTACHMENTS_DISK', env('FILESYSTEM_DISK')),
        'path' => env('CONVERSE_ATTACHMENTS_PATH', 'converse/attachments'),
        'max_bytes' => env('CONVERSE_ATTACHMENTS_MAX_BYTES', 10485760),
        'max_files' => env('CONVERSE_ATTACHMENTS_MAX_FILES', 5),
        'timeout' => env('CONVERSE_ATTACHMENTS_TIMEOUT', 30),

        'mime_types' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/pdf',
            'text/plain',
            'text/csv',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Thread Processing
    |--------------------------------------------------------------------------
    |
    | Each thread's work is serialized using a cache lock. The newest message
    | drives the turn and the ones it passed over ride along with it. Values
    | below are given in seconds.
    |
    */

    'processing' => [
        'debounce' => env('CONVERSE_PROCESSING_DEBOUNCE', 0),
        'timeout' => env('CONVERSE_PROCESSING_TIMEOUT', 60),
        'lock_ttl' => env('CONVERSE_PROCESSING_LOCK_TTL', 90),
        'lock_wait' => env('CONVERSE_PROCESSING_LOCK_WAIT', 60),
        'dedupe_ttl' => env('CONVERSE_PROCESSING_DEDUPE_TTL', 86400),
        'approval_ttl' => env('CONVERSE_PROCESSING_APPROVAL_TTL', 3600),
        'max_attempts' => env('CONVERSE_PROCESSING_MAX_ATTEMPTS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Action Callbacks
    |--------------------------------------------------------------------------
    |
    | Card buttons with a callback URL deliver each click there as a signed POST
    | request from your queue. The signing secret is required and should not
    | be your app key. The queue falls back to the connection defined above.
    |
    */

    'callbacks' => [
        'signing_secret' => env('CONVERSE_CALLBACKS_SIGNING_SECRET', ''),
        'ttl' => env('CONVERSE_CALLBACKS_TTL', 2592000),
        'timeout' => env('CONVERSE_CALLBACKS_TIMEOUT', 10),
        'tries' => env('CONVERSE_CALLBACKS_TRIES', 3),
        'backoff' => [10, 60, 300],

        'queue' => [
            'connection' => env('CONVERSE_CALLBACKS_QUEUE_CONNECTION'),
            'name' => env('CONVERSE_CALLBACKS_QUEUE_NAME'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bot Schedules
    |--------------------------------------------------------------------------
    |
    | Schedules registered on your bots are run through Laravel's task scheduler.
    | Here you may disable them all, set the timezone they are evaluated in,
    | and choose how long, in seconds, a resolved destination stays cached.
    |
    */

    'schedules' => [
        'enabled' => env('CONVERSE_SCHEDULES_ENABLED', true),
        'timezone' => env('CONVERSE_SCHEDULES_TIMEZONE'),
        'resolution_ttl' => env('CONVERSE_SCHEDULES_RESOLUTION_TTL', 86400),
    ],

];

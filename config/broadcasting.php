<?php

/*
 * Realtime (api/realtime.md): Laravel Reverb, Pusher protocol. Every channel is private; events are hints, REST is the source of truth.
 * Local dev / Local node: BROADCAST_CONNECTION=reverb, `php artisan reverb:start` (or `composer local-node`), port 8081.
 * Tests and CI: BROADCAST_CONNECTION=null.
 */
return [

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST', '127.0.0.1'),
                'port' => env('REVERB_PORT', 8081),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            'client_options' => [],
        ],

        'log' => ['driver' => 'log'],

        'null' => ['driver' => 'null'],

    ],

];

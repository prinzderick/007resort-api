<?php

/*
 * CORS for the browser/web clients (KDS, admin) hitting /api/v1 from another origin (contract api/README.md headers).
 * CORS_ALLOWED_ORIGINS: comma-separated origins; `*` (default) is fine on the LAN Local node, restrict it on the Cloud node.
 */
return [

    'paths' => ['api/*', 'broadcasting/auth'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*'))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept', 'Authorization', 'Content-Type', 'X-Device-Token', 'If-Match', 'If-None-Match', 'Idempotency-Key',
        'X-Correlation-Id', 'X-Step-Up-Token', 'X-Client-Timestamp', 'X-Requested-With',
    ],

    'exposed_headers' => ['ETag', 'Idempotent-Replayed', 'X-Correlation-Id', 'Retry-After', 'Sunset', 'Deprecation'],

    'max_age' => 600,

    'supports_credentials' => false,

];

<?php

/*
 * Two-node model (ADR-0013). The same codebase runs as the on-premises Local node or the Cloud node.
 */
$node = strtolower((string) env('APP_NODE', 'local'));

return [
    // 'local' | 'cloud'. Validated at boot (see App\Providers\AppServiceProvider).
    'node' => $node,
    'is_local' => $node === 'local',
    'is_cloud' => $node === 'cloud',

    // The site this node serves (UUID of site.id). Local nodes must set it; Cloud serves many sites.
    'site_id' => env('SITE_ID') ?: null,
    'organization_id' => env('ORGANIZATION_ID') ?: null,

    'service' => '007resort-api',
    'version' => env('APP_VERSION', '0.1.0'),
    'api_version' => 'v1',
];

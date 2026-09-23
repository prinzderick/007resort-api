<?php

namespace App\Domain\Hospitality\Http\Controllers;

use App\Domain\Catalog\Services\TaxSettings;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;

/** `GET /system/info` (public): version gate, deployment mode and the realtime endpoint clients connect to. */
class SystemInfoController
{
    public function __invoke(TaxSettings $tax): JsonResponse
    {
        $org = Tenant::organizationId();

        return response()->json([
            'service' => config('node.service'),
            'apiVersion' => config('node.version'),
            'deploymentMode' => config('node.node'),
            'nodeId' => Tenant::siteId(),
            'serverTime' => now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'vatEnabled' => $org ? $tax->get($org)['vatEnabled'] : false,
            'minClientVersion' => ['mobile' => '0.1.0', 'kds' => '0.1.0', 'pos' => '0.1.0'],
            'realtime' => [
                'scheme' => config('reverb.apps.apps.0.options.scheme') === 'https' ? 'wss' : 'ws',
                'host' => env('REVERB_PUBLIC_HOST', request()->getHost()),
                'port' => (int) config('reverb.apps.apps.0.options.port', 8081),
                'appKey' => (string) config('reverb.apps.apps.0.key'),
            ],
        ], 200, ['Cache-Control' => 'no-store']);
    }
}

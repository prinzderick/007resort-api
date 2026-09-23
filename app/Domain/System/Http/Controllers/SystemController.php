<?php

namespace App\Domain\System\Http\Controllers;

use App\Domain\Organization\Services\TaxSettingService;
use App\Domain\System\Services\HealthChecker;
use App\Support\Ids;
use App\Support\Node;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class SystemController
{
    public function __construct(private readonly HealthChecker $health) {}

    /** GET /system/info — public; clients call it on start-up (min client versions, deployment mode, realtime endpoint). */
    public function info(): JsonResponse
    {
        $siteId = null;
        $tz = 'Africa/Lagos';
        $vat = false;
        try {
            $siteId = Tenant::siteId();
            $tz = $siteId ? (DB::table('site')->where('id', Ids::toBinary($siteId))->value('time_zone') ?? $tz) : $tz;
            $org = Tenant::organizationId();
            $vat = $org ? app(TaxSettingService::class)->get($org)['vatEnabled'] : false;
        } catch (Throwable) {
            // DB not ready: still answer (info is for bootstrap); /health reports the outage
        }
        $reverb = config('broadcasting.connections.reverb');

        return response()->json([
            'service' => config('node.service'),
            'version' => config('node.version'),
            'apiVersion' => config('node.api_version'),
            'deploymentMode' => Node::name(),
            'node' => Node::name(),
            'nodeId' => config('node.node_id') ?: $siteId,
            'siteId' => $siteId,
            'serverTime' => now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'timezone' => $tz,
            'currency' => 'NGN',
            'minClientVersion' => config('node.min_client_version'),
            'vatEnabled' => $vat,
            'realtime' => [
                'scheme' => $reverb['options']['scheme'] ?? 'http',
                'host' => $reverb['options']['host'] ?? '127.0.0.1',
                'port' => (int) ($reverb['options']['port'] ?? 8081),
                'appKey' => $reverb['key'] ?? '',
            ],
        ]);
    }

    /** GET /system/health, /health/ready, /up — 503 when a hard dependency (DB/Redis) is down. */
    public function ready(): JsonResponse
    {
        $h = $this->health->check();

        return response()->json(['status' => $h['status'], 'checks' => $h['checks'], 'serverTime' => $h['serverTime']], $h['status'] === 'down' ? 503 : 200);
    }

    /** GET /health/live — process is up (no dependency checks). */
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok', 'serverTime' => now('UTC')->format('Y-m-d\TH:i:s.v\Z')]);
    }
}

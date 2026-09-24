<?php

namespace Tests\Feature\System;

use App\Domain\System\Services\HealthChecker;
use App\Support\Demo\DemoIds;
use Tests\Support\DemoApi;
use Tests\TestCase;

class SystemTest extends TestCase
{
    use DemoApi;

    public function test_probes_check_database_and_redis(): void
    {
        foreach (['/up', '/health/ready', '/api/v1/system/health'] as $url) {
            $this->getJson($url)->assertOk()->assertJson(['status' => 'ok', 'checks' => ['database' => 'ok', 'redis' => 'ok', 'queue' => 'ok']])->assertJsonStructure(['serverTime']);
        }
        $this->getJson('/health/live')->assertOk()->assertJsonPath('status', 'ok');
    }

    public function test_readiness_is_503_when_a_hard_dependency_is_down(): void
    {
        $this->app->instance(HealthChecker::class, new class extends HealthChecker
        {
            public function check(): array
            {
                return ['status' => 'down', 'checks' => ['database' => 'ok', 'redis' => 'down', 'queue' => 'down', 'cloudLink' => 'ok'], 'outboxDepth' => 0, 'serverTime' => now('UTC')->toISOString()];
            }
        });
        $this->getJson('/up')->assertStatus(503)->assertJsonPath('checks.redis', 'down');
        $this->getJson('/health/live')->assertOk(); // liveness never depends on dependencies
    }

    public function test_system_info(): void
    {
        $this->seedDemo();
        config(['node.site_id' => DemoIds::site(), 'broadcasting.connections.reverb.key' => 'pub-key']);
        $info = $this->getJson('/api/v1/system/info')->assertOk()->json();
        $this->assertSame('007resort-api', $info['service']);
        $this->assertSame('v1', $info['apiVersion']);
        $this->assertSame('local', $info['deploymentMode']);
        $this->assertSame('local', $info['node']);
        $this->assertSame(DemoIds::site(), $info['siteId']);
        $this->assertSame('Africa/Lagos', $info['timezone']);
        $this->assertSame('NGN', $info['currency']);
        $this->assertFalse($info['vatEnabled']);
        $this->assertSame('pub-key', $info['realtime']['appKey']);
        $this->assertSame(8081, $info['realtime']['port']);
        $this->assertSame('10.0.2.2', $this->getJson('http://10.0.2.2:8080/api/v1/system/info')->json('realtime.host'), 'loopback REVERB_HOST echoes the host the client used (emulator)');
        $this->assertArrayHasKey('mobile', $info['minClientVersion']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT[\d:.]+Z$/', $info['serverTime']);
        $this->assertArrayNotHasKey('secret', $info['realtime']);
    }

    public function test_cloud_node_mode(): void
    {
        config(['node.node' => 'cloud']);
        $this->getJson('/api/v1/system/info')->assertJsonPath('deploymentMode', 'cloud');
    }
}

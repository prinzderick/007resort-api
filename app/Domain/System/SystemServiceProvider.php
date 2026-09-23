<?php

namespace App\Domain\System;

use App\Domain\System\Services\HealthChecker;
use App\Support\Realtime\RealtimeEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Throwable;

/** System: node info + health probes (/up, /health/live, /health/ready, /api/v1/system/*) and the `site.health` realtime keep-alive. */
class SystemServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HealthChecker::class);
    }

    public function boot(): void
    {
        // Contract: `site.health` on private-site.status every 30 s (also a client liveness signal).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->call(function (): void {
                try {
                    $h = app(HealthChecker::class)->check();
                    RealtimeEvent::publish('site.status', 'site.health', [
                        'status' => strtoupper($h['status'] === 'down' ? 'OFFLINE' : ($h['status'] === 'ok' ? 'ONLINE' : 'DEGRADED')),
                        'checks' => $h['checks'], 'outboxDepth' => $h['outboxDepth'], 'serverTime' => $h['serverTime'],
                    ]);
                } catch (Throwable $e) {
                    report($e);
                }
            })->name('site-health-keepalive')->everyThirtySeconds()->withoutOverlapping(1);
        });
    }
}

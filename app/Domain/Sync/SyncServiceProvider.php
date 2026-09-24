<?php

namespace App\Domain\Sync;

use App\Domain\Sync\Appliers\VersionedDomainApplier;
use App\Domain\Sync\Appliers\VersionedTarget;
use App\Domain\Sync\Appliers\VersionedTargets;
use App\Domain\Sync\Console\SyncReplayFailedCommand;
use App\Domain\Sync\Console\SyncRetryCommand;
use App\Domain\Sync\Console\SyncRunOnceCommand;
use App\Domain\Sync\Console\SyncStatusCommand;
use App\Domain\Sync\Console\SyncTokenCommand;
use App\Domain\Sync\Console\TwoNodeCommand;
use App\Domain\Sync\Http\Middleware\AuthenticateNode;
use App\Domain\Sync\Jobs\MarkStaleSitesJob;
use App\Domain\Sync\Jobs\PublishOutboxJob;
use App\Domain\Sync\Jobs\PullFromPeerJob;
use App\Domain\Sync\Jobs\ReprocessInboxJob;
use App\Domain\Sync\Jobs\SendHeartbeatJob;
use App\Domain\Sync\Services\HttpPeerClient;
use App\Domain\Sync\Services\PeerClient;
use App\Domain\Sync\Services\SiteAvailability;
use App\Domain\Sync\Services\SyncApplierRegistry;
use App\Domain\Sync\Support\ApplyResult;
use App\Domain\Sync\Support\Throttle;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * Sync module (ADR-0013): outbox publisher, inbox receiver + applier registry, Cloud->Local pull, heartbeat /
 * site availability, conflict register. See docs/sync-engine.md.
 */
class SyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SyncApplierRegistry::class);
        $this->app->singleton(VersionedTargets::class);
        $this->app->bind(PeerClient::class, HttpPeerClient::class);
        $this->app->singleton(SiteAvailability::class);
    }

    public function boot(): void
    {
        $this->app->make(Router::class)->aliasMiddleware('node.auth', AuthenticateNode::class);

        $this->registerBuiltInAppliers();

        if ($this->app->runningInConsole()) {
            $this->commands([SyncStatusCommand::class, SyncRetryCommand::class, SyncReplayFailedCommand::class, SyncTokenCommand::class, SyncRunOnceCommand::class, TwoNodeCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, fn (Schedule $s) => $this->schedule($s));
    }

    /** Configuration + staff roster sync: two-way editable, version-checked (never last-writer-wins). */
    private function registerBuiltInAppliers(): void
    {
        $this->callAfterResolving(VersionedTargets::class, function (VersionedTargets $t) {
            $t->register('facility', new VersionedTarget('facility_unit', 'CONFIGURATION', [
                'name' => ['name', 'string'], 'isActive' => ['is_active', 'bool'],
            ]));
            $t->register('staff', new VersionedTarget('staff', 'PERMISSION', [
                'firstName' => ['first_name', 'string'], 'lastName' => ['last_name', 'string'],
                'email' => ['email', 'string'], 'phone' => ['phone', 'string'], 'isActive' => ['is_active', 'bool'],
            ]));
        });
        $this->callAfterResolving(SyncApplierRegistry::class, function (SyncApplierRegistry $r) {
            $targets = $this->app->make(VersionedTargets::class);
            $r->register('ConfigurationUpdated', new VersionedDomainApplier($targets, 'CONFIGURATION'));
            $r->register('StaffRosterUpdated', new VersionedDomainApplier($targets, 'PERMISSION'));
            // Diagnostic only, not a business event (event-catalogue): acknowledge and move on.
            $r->register('HeartbeatAck', fn () => ApplyResult::applied());
        });
    }

    /**
     * Sub-minute tasks are gated by a Redis SET NX throttle so every interval is configurable; if Redis is down the
     * tick is skipped (events stay in MySQL) and the next tick retries.
     */
    private function schedule(Schedule $schedule): void
    {
        $every = fn (string $name, int $seconds) => fn () => Throttle::due($name, $seconds);

        if (config('sync.push_enabled')) {
            $schedule->job(new PublishOutboxJob)->name('sync:publish')->everySecond()->when($every('publish', (int) config('sync.publish_interval')));
        }
        if (config('sync.pull_enabled')) {
            $schedule->job(new PullFromPeerJob)->name('sync:pull')->everySecond()->when($every('pull', (int) config('sync.pull_interval')));
        }
        if (config('sync.heartbeat_enabled')) {
            $schedule->job(new SendHeartbeatJob)->name('sync:heartbeat')->everySecond()->when($every('heartbeat', (int) config('sync.heartbeat_interval')));
        }
        if (config('sync.accept_heartbeat')) {
            $schedule->job(new MarkStaleSitesJob)->name('sync:mark-stale')->everySecond()->when($every('mark-stale', 10));
        }
        $schedule->job(new ReprocessInboxJob)->name('sync:reprocess-inbox')->everySecond()->when($every('reprocess-inbox', 15));
    }
}

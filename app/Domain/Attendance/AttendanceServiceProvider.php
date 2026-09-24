<?php

namespace App\Domain\Attendance;

use App\Domain\Attendance\Console\SimulatePunchesCommand;
use App\Domain\Attendance\Http\Middleware\AuthenticateTerminal;
use App\Domain\Attendance\Http\Middleware\IclockNetwork;
use App\Domain\Attendance\Services\AttendanceDayBuilder;
use App\Domain\Attendance\Services\CorrectionService;
use App\Domain\Attendance\Services\PunchIngestService;
use App\Domain\Attendance\Services\TerminalService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Attendance module (auto-registered, docs/MODULES.md). Besides the auto-loaded /api/v1 routes.php it mounts the ZKTeco
 * ADMS root routes (routes-iclock.php), which cannot live under /api/v1 because terminals use fixed paths.
 */
class AttendanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AttendanceDayBuilder::class);
        $this->app->singleton(PunchIngestService::class);
        $this->app->singleton(TerminalService::class);
        $this->app->singleton(CorrectionService::class);
    }

    public function boot(): void
    {
        $router = $this->app['router'];
        $router->aliasMiddleware('attendance.terminal', AuthenticateTerminal::class);
        $router->aliasMiddleware('attendance.iclock', IclockNetwork::class);

        RateLimiter::for('attendance-terminal', fn (Request $r) => Limit::perMinute(120)->by('atd:'.sha1((string) $r->header('X-Device-Token', $r->ip()))));
        RateLimiter::for('attendance-iclock', fn (Request $r) => Limit::perMinute(240)->by('iclock:'.($r->query('SN') ?: $r->ip())));

        if (! $this->app->routesAreCached()) {
            Route::group([], __DIR__.'/routes-iclock.php');
        }
        if ($this->app->runningInConsole()) {
            $this->commands([SimulatePunchesCommand::class]);
        }
    }
}

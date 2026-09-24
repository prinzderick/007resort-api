<?php

namespace App\Domain\Cms;

use App\Domain\Cms\Console\CmsSeedCommand;
use App\Domain\Cms\Http\Controllers\PublicController;
use App\Domain\Cms\Services\MediaService;
use App\Domain\Cms\Support\MediaResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/** Website CMS (docs/CMS_API.md): public site content + admin management, media, subscribers, contact inbox. */
class CmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MediaService::class);
        $this->app->scoped(MediaResolver::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'cms');

        $client = fn (Request $r) => PublicController::clientIp($r);
        $emailClient = fn (Request $r) => sha1(strtolower((string) $r->input('email')).'|'.$client($r));
        RateLimiter::for('cms-subscribe', fn (Request $r) => [Limit::perHour(5)->by('cms-sub:'.$emailClient($r)), Limit::perHour(20)->by('cms-sub-ip:'.$client($r))]);
        RateLimiter::for('cms-contact', fn (Request $r) => [Limit::perHour(5)->by('cms-msg:'.$emailClient($r)), Limit::perHour(20)->by('cms-msg-ip:'.$client($r))]);
        RateLimiter::for('cms-token', fn (Request $r) => Limit::perMinute(30)->by('cms-tok:'.$client($r)));

        if ($this->app->runningInConsole()) {
            // `artisan serve` strips unknown env vars from the PHP server it spawns; let PHP_INI_SCAN_DIR (upload limits, see RunNode) through.
            if (! in_array('PHP_INI_SCAN_DIR', ServeCommand::$passthroughVariables, true)) {
                ServeCommand::$passthroughVariables[] = 'PHP_INI_SCAN_DIR';
            }
            $this->commands([CmsSeedCommand::class]);
        }
    }
}

<?php

namespace App\Domain\Hospitality;

use App\Domain\Hospitality\Broadcasting\Channels;
use App\Domain\Hospitality\Services\KdsService;
use App\Domain\Hospitality\Services\StationRouter;
use App\Domain\Hospitality\Services\TicketPresenter;
use Illuminate\Support\ServiceProvider;

/** Hospitality module: KDS stations, prep tickets, prep-ticket state machine. */
class HospitalityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Channels::register();
    }

    public function register(): void
    {
        $this->app->singleton(StationRouter::class);
        $this->app->singleton(TicketPresenter::class);
        $this->app->singleton(KdsService::class);
    }
}

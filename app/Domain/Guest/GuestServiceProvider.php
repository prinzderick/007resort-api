<?php

namespace App\Domain\Guest;

use App\Domain\Guest\Console\EraseCommand;
use App\Domain\Guest\Console\SendMessagesCommand;
use App\Domain\Guest\Contracts\SmsSender;
use App\Domain\Guest\Listeners\QueueGuestConfirmation;
use App\Domain\Guest\Services\GuestAccess;
use App\Domain\Guest\Services\GuestMessenger;
use App\Domain\Guest\Services\LogSmsSender;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class GuestServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GuestAccess::class);
        $this->app->bind(SmsSender::class, LogSmsSender::class);
        $this->app->singleton(GuestMessenger::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SendMessagesCommand::class, EraseCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('r007:guest-messages:send')->everyMinute()->withoutOverlapping(5)->onOneServer();
        });
        Event::listen('App\\Domain\\Payments\\Events\\PaymentCaptured', QueueGuestConfirmation::class);
    }
}

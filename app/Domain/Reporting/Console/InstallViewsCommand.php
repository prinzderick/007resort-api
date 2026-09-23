<?php

namespace App\Domain\Reporting\Console;

use App\Domain\Reporting\Support\ReportingViews;
use Illuminate\Console\Command;

class InstallViewsCommand extends Command
{
    protected $signature = 'r007:reporting:views';

    protected $description = '(Re)create the read-only reporting SQL views for every source table that exists on this deployment.';

    public function handle(): int
    {
        ReportingViews::flushCaches();
        foreach (ReportingViews::install() as $view => $state) {
            $this->line(str_pad($view, 32).$state);
        }

        return self::SUCCESS;
    }
}

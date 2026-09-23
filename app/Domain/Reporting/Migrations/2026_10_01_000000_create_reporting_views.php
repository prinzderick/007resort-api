<?php

use App\Domain\Reporting\Support\ReportingViews;
use Illuminate\Database\Migrations\Migration;

/**
 * Sorted late on purpose so Orders/Payments tables (2026_09_23/24) exist first. Views whose sources are missing are skipped;
 * run `php artisan r007:reporting:views` after later module migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        ReportingViews::flushCaches();
        ReportingViews::install();
    }

    public function down(): void
    {
        ReportingViews::dropAll();
    }
};

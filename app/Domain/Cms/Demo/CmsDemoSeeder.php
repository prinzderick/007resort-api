<?php

namespace App\Domain\Cms\Demo;

use App\Support\Demo\DemoContext;
use App\Support\Demo\DemoSeeder;

/** `r007:demo-seed` hook: the website content of the demo property (same code as `r007:cms-seed`). */
class CmsDemoSeeder implements DemoSeeder
{
    public function priority(): int
    {
        return 250;
    }

    public function run(DemoContext $context): void
    {
        // The PHPUnit suite calls r007:demo-seed in hundreds of tests; importing photos + ~150 audited writes each time would dominate its runtime.
        // CMS tests seed explicitly (`r007:cms-seed`) with a tiny fixture folder.
        if (app()->environment('testing') && ! env('CMS_SEED_IN_TESTS')) {
            return;
        }
        app(CmsContentSeeder::class)->run(fn (string $line) => $context->info('  '.$line));
    }
}

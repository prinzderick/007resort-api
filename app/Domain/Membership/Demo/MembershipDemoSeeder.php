<?php

namespace App\Domain\Membership\Demo;

use App\Support\Demo\DemoContext;
use App\Support\Demo\DemoSeeder;
use Database\Seeders\MembershipDemoSeeder as PlanSeeder;

/** Integrated demo: membership plans (Gold / Silver / Pool Pass) over the Organization demo's facilities. Runs after Catalog/Hospitality/Booking. */
class MembershipDemoSeeder implements DemoSeeder
{
    public function priority(): int
    {
        return 130;
    }

    public function run(DemoContext $context): void
    {
        $seeder = new PlanSeeder;
        $seeder->setContainer(app())->setCommand($context->command);
        app()->call([$seeder, 'run']);
    }
}

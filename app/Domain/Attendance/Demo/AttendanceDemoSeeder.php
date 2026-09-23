<?php

namespace App\Domain\Attendance\Demo;

use App\Support\Demo\DemoContext;
use App\Support\Demo\DemoSeeder;
use Database\Seeders\AttendanceDemoSeeder as TerminalSeeder;

/** Integrated demo: biometric terminal ZK-DEMO-001 with the first staff linked. Runs after Identity (needs staff). */
class AttendanceDemoSeeder implements DemoSeeder
{
    public function priority(): int
    {
        return 140;
    }

    public function run(DemoContext $context): void
    {
        $seeder = new TerminalSeeder;
        $seeder->setContainer(app())->setCommand($context->command);
        app()->call([$seeder, 'run']);
    }
}

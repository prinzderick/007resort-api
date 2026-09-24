<?php

namespace App\Domain\Config\Demo;

use App\Support\Demo\DemoContext;
use App\Support\Demo\DemoIds;
use App\Support\Demo\DemoSeeder;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * Demo configuration for the admin portal: business contact details and receipt settings, so GET /admin/setup-status and the
 * settings screens have something to show. (Log in as `owner1`, PIN 1234, to explore docs/CONFIG_ADMIN_API.md.)
 */
class ConfigDemoSeeder implements DemoSeeder
{
    public function priority(): int
    {
        return 200;
    }

    public function run(DemoContext $context): void
    {
        DB::table('site')->where('id', Ids::toBinary(DemoIds::site()))->whereNull('phone')->update(['phone' => '+234 800 007 0007', 'email' => 'hello@007resort.demo.test']);
        DB::table('receipt_setting')->updateOrInsert(['organization_id' => Ids::toBinary(DemoIds::org())], [
            'business_name' => '007 Resort & Spa', 'address' => 'Otueke, Bayelsa State, Nigeria', 'phone' => '+234 800 007 0007', 'header_note' => null,
            'footer' => 'Thank you for choosing 007 Resort & Spa', 'logo_url' => null, 'show_tin' => 1, 'paper_columns' => 48,
        ]);
        $context->info('  business contact details + receipt settings');
    }
}

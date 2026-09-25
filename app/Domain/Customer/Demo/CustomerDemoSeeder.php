<?php

namespace App\Domain\Customer\Demo;

use App\Domain\Customer\Services\ServiceTokenService;
use App\Support\Demo\DemoContext;
use App\Support\Demo\DemoSeeder;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * DEV-ONLY: a deterministic website service token `r7s_dev_booking_web` (scopes public.read,public.checkout) so the booking website can be pointed at a
 * demo node with `R007_API_SERVICE_TOKEN=r7s_dev_booking_web`. Real environments create theirs with `php artisan r007:service-token create`.
 */
class CustomerDemoSeeder implements DemoSeeder
{
    public const DEV_TOKEN = 'r7s_dev_booking_web';

    public const DEV_SCOPE = 'public.read,public.checkout';

    public function priority(): int
    {
        return 150;
    }

    public function run(DemoContext $context): void
    {
        if (app()->isProduction()) {
            return;
        }
        if (DB::table('service_token')->where('token_hash', hash('sha256', self::DEV_TOKEN))->exists()) {
            DB::table('service_token')->where('token_hash', hash('sha256', self::DEV_TOKEN))->update(['scope' => self::DEV_SCOPE]);

            return;
        }
        $org = Tenant::organizationId();
        app(ServiceTokenService::class)->create('booking-web (dev)', $org, null, self::DEV_TOKEN, self::DEV_SCOPE);
        $context->command?->info('service token (dev only): '.self::DEV_TOKEN);
    }
}

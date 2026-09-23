<?php

namespace App\Console\Commands;

use App\Domain\Devices\Services\RegistrationCodeService;
use App\Support\Ids;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Bootstrap a first device before any admin exists: php artisan r007:device-code [--facility=<facility code>] */
class IssueDeviceCode extends Command
{
    protected $signature = 'r007:device-code {--facility= : home facility code, e.g. RESTAURANT} {--hours=24}';

    protected $description = 'Issue a one-time device registration code (shown once; only its hash is stored)';

    public function handle(RegistrationCodeService $codes): int
    {
        $org = Tenant::organizationId();
        $site = Tenant::siteId();
        if (! $org || ! $site) {
            $this->error('No site configured yet (seed the demo or create one, and set SITE_ID).');

            return self::FAILURE;
        }
        $facility = null;
        if ($code = $this->option('facility')) {
            $row = DB::table('facility_unit')->where('site_id', Ids::toBinary($site))->where('code', $code)->first(['id']);
            if (! $row) {
                $this->error("Unknown facility code {$code}.");

                return self::FAILURE;
            }
            $facility = Ids::fromBinary($row->id);
        }
        $out = $codes->issue($org, $site, $facility, null, (int) $this->option('hours'));
        $this->info("Registration code: {$out['code']}  (expires {$out['expiresAt']})");

        return self::SUCCESS;
    }
}

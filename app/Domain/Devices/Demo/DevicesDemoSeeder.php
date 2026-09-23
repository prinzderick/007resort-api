<?php

namespace App\Domain\Devices\Demo;

use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Services\DeviceTokenService;
use App\Support\Demo\DemoContext;
use App\Support\Demo\DemoIds;
use App\Support\Demo\DemoSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Registered demo devices with DETERMINISTIC DEV-ONLY tokens (`r7d_dev_<code>`), so clients can be pointed at the demo
 * node without the registration-code dance. Mirrors spec §3-5: 10 POS-class terminals, 4 KDS, 18 tablets (12 shared waiter,
 * 4 supervisor, Sports Entrance, Sports Store) and the Main Gate attendance terminal.
 */
class DevicesDemoSeeder implements DemoSeeder
{
    public function priority(): int
    {
        return 30;
    }

    /** code => [name, kind, home facility code] */
    private function devices(): array
    {
        $d = [
            'POS_RECEPTION_1' => ['Reception POS 1', 'POS_TERMINAL', 'RECEPTION'],
            'POS_RECEPTION_2' => ['Reception POS 2', 'POS_TERMINAL', 'RECEPTION'],
            'POS_RESTAURANT' => ['Restaurant POS', 'POS_TERMINAL', 'RESTAURANT'],
            'POS_INDOOR_CLUB' => ['Indoor Club POS', 'POS_TERMINAL', 'INDOOR_CLUB'],
            'POS_BEAUTY_SPA' => ['Beauty Spa POS', 'POS_TERMINAL', 'BEAUTY_SPA'],
            'POS_CAFE' => ['Cafe POS', 'POS_TERMINAL', 'CAFE'],
            'POS_SUPERMARKET_1' => ['Supermarket POS 1', 'POS_TERMINAL', 'SUPERMARKET'],
            'POS_SUPERMARKET_2' => ['Supermarket POS 2', 'POS_TERMINAL', 'SUPERMARKET'],
            'POS_BUSH_BAR' => ['Bush Bar POS', 'POS_TERMINAL', 'BUSH_BAR'],
            'POS_SALON' => ['Salon POS', 'POS_TERMINAL', 'SALON_FEMALE'],
            'KDS_MAIN_KITCHEN' => ['KDS Main Kitchen', 'KDS_SCREEN', 'MAIN_KITCHEN'],
            'KDS_RESTAURANT_COUNTER' => ['KDS Restaurant Counter', 'KDS_SCREEN', 'RESTAURANT'],
            'KDS_POOL_BAR' => ['KDS Pool Bar', 'KDS_SCREEN', 'POOL_BAR'],
            'KDS_BUSH_BAR' => ['KDS Bush Bar', 'KDS_SCREEN', 'BUSH_BAR'],
            'TABLET_SPORTS_ENTRANCE' => ['Sports Entrance Tablet', 'MOBILE_TABLET', 'SPORTS_ARENA'],
            'TABLET_SPORTS_STORE' => ['Sports Store Tablet', 'MOBILE_TABLET', 'SPORTS_STORE'],
            'ATTENDANCE_MAIN_GATE' => ['Main Gate Biometric Terminal (ZKTeco)', 'ATTENDANCE_TERMINAL', 'MAIN_GATE'],
        ];
        foreach (range(1, 12) as $i) {
            $d[sprintf('TABLET_WAITER_%02d', $i)] = [sprintf('Waiter Tablet %02d', $i), 'MOBILE_TABLET', 'RECEPTION'];
        }
        foreach (['RESTAURANT', 'INDOOR_CLUB', 'POOL_BAR', 'BUSH_BAR'] as $i => $fac) {
            $d[sprintf('TABLET_SUPERVISOR_%d', $i + 1)] = [sprintf('Supervisor Tablet %d (%s)', $i + 1, $fac), 'MOBILE_TABLET', $fac];
        }

        return $d;
    }

    public function run(DemoContext $ctx): void
    {
        $tokens = app(DeviceTokenService::class);
        $rows = [];
        foreach ($this->devices() as $code => [$name, $kind, $facility]) {
            $device = Device::query()->updateOrCreate(['id' => DemoIds::device($code)], [
                'organization_id' => DemoIds::org(), 'site_id' => DemoIds::site(), 'facility_unit_id' => DemoIds::facility($facility),
                'device_type' => Device::KIND_TO_TYPE[$kind], 'name' => $name, 'hardware_id' => 'demo-'.strtolower($code),
                'platform' => match ($kind) {
                    'POS_TERMINAL' => 'windows', 'MOBILE_TABLET' => 'android', 'KDS_SCREEN' => 'browser', default => 'embedded'
                },
                'app_version' => '0.1.0', 'is_active' => 1, 'is_revoked' => 0, 'revoked_at' => null,
            ]);
            $token = 'r7d_dev_'.strtolower($code);
            if (! DB::table('device_registration')->where('token_hash', hash('sha256', $token))->exists()) {
                $tokens->store($device, $token, null);
            }
            $rows[] = [$device->id, $name, $kind, $facility, $token];
        }
        $ctx->table('DEV-ONLY devices (send the token as X-Device-Token)', ['id', 'name', 'kind', 'home facility', 'device token'], $rows);
        $ctx->info('  '.count($rows).' registered devices');
    }
}

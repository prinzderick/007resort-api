<?php

namespace Tests\Feature;

use App\Providers\ModuleServiceProvider;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ModuleDiscoveryTest extends TestCase
{
    private string $probe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->probe = app_path('Domain/ZzProbe');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->probe);
        parent::tearDown();
    }

    public function test_all_stub_modules_exist_and_are_registered(): void
    {
        $expected = ['Attendance', 'Audit', 'Booking', 'Catalog', 'Devices', 'Hospitality', 'Identity', 'Inventory', 'Membership', 'Orders', 'Organization', 'Payments', 'Reporting', 'Sync', 'Ticketing'];
        $this->assertEmpty(array_diff($expected, ModuleServiceProvider::modules()));
        foreach ($expected as $m) {
            $this->assertNotNull(app()->getProvider("App\\Domain\\{$m}\\{$m}ServiceProvider"), $m);
        }
    }

    public function test_a_new_module_is_picked_up_without_editing_any_shared_file(): void
    {
        File::ensureDirectoryExists("{$this->probe}/Migrations");
        File::put("{$this->probe}/ZzProbeServiceProvider.php", <<<'PHP'
<?php
namespace App\Domain\ZzProbe;
use Illuminate\Support\ServiceProvider;
class ZzProbeServiceProvider extends ServiceProvider
{
    public function register(): void { $this->app->instance('zzprobe.registered', true); }
}
PHP);
        File::put("{$this->probe}/routes.php", "<?php\nuse Illuminate\\Support\\Facades\\Route;\nRoute::get('zz-probe/ping', fn () => ['pong' => config('zzprobe.answer')]);\n");
        File::put("{$this->probe}/config.php", "<?php\nreturn ['answer' => 42];\n");
        File::put("{$this->probe}/Migrations/2026_09_22_999999_probe.php", "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nreturn new class extends Migration { public function up(): void {} public function down(): void {} };\n");

        $this->refreshApplication();

        $this->assertTrue(app('zzprobe.registered'));
        $this->getJson('/api/v1/zz-probe/ping')->assertOk()->assertJson(['pong' => 42]);
        $this->assertContains("{$this->probe}/Migrations", app('migrator')->paths());
    }
}

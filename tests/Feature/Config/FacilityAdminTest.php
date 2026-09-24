<?php

namespace Tests\Feature\Config;

use App\Domain\Config\Support\FacilityTemplates;
use App\Domain\Config\Support\RuleDefinitions;
use App\Support\Demo\DemoIds;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class FacilityAdminTest extends ConfigTestCase
{
    public static function templateKeys(): array
    {
        return array_map(fn ($k) => [$k], array_keys(FacilityTemplates::all()));
    }

    #[DataProvider('templateKeys')]
    public function test_create_facility_from_every_template(string $key): void
    {
        $tpl = FacilityTemplates::get($key);
        $r = $this->owner()->post('/organization/facilities', ['code' => 'NEW_'.$key, 'name' => 'New '.$key, 'templateKey' => $key]);
        $r->assertStatus(201)->assertHeader('ETag', '"1"');
        $f = $r->json();
        $this->assertSame($key, $f['templateKey']);
        $this->assertSame($tpl['defaultKind'], $f['kind']);
        $expected = $tpl['capabilities'];
        sort($expected);
        $this->assertEqualsCanonicalizing($expected, $f['capabilities']);
        $this->assertSame('ACTIVE', $f['status']);

        // effective runtime view agrees, and the rules from the template are live
        $eff = $this->owner()->get('/facilities/'.$f['id'].'/capabilities')->assertOk()->json();
        $this->assertEqualsCanonicalizing($expected, $eff['capabilities']);
        foreach ($tpl['operatingRules'] as $k => $v) {
            $name = RuleDefinitions::camel($k);
            $this->assertEquals($v, $eff['operatingRules'][$name] ?? null, "rule {$k}");
        }
        // starter kit
        $points = $this->owner()->get('/organization/facilities/'.$f['id'].'/operating-points')->assertOk()->json('items');
        $this->assertCount(count($tpl['starterOperatingPoints']) + ($tpl['starterKdsStation'] ? 1 : 0), $points);
        if ($tpl['starterKdsStation']) {
            $this->assertNotNull(DB::table('kds_station')->where('facility_unit_id', Ids::toBinary($f['id']))->first());
        }
        // audit + outbox in the same transaction
        $a = $this->audit('config.facility.create', $f['id']);
        $this->assertCount(1, $a);
        $this->assertSame('NEW_'.$key, $a[0]->new['code']);
        $ev = $this->outbox($f['id'], 'facilityFull');
        $this->assertCount(1, $ev);
        $this->assertSame(1, $ev[0]['version']);
    }

    public function test_create_validation_and_duplicates(): void
    {
        $o = $this->owner();
        $o->post('/organization/facilities', ['code' => 'bad code', 'name' => 'X'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $o->post('/organization/facilities', ['code' => 'RESTAURANT', 'name' => 'Dup'])->assertStatus(409)->assertJsonPath('code', 'facility_code_taken');
        $o->post('/organization/facilities', ['code' => 'NOTPL', 'name' => 'X', 'templateKey' => 'NOPE'])->assertStatus(422)->assertJsonPath('errors.templateKey.0', fn ($m) => str_contains($m, 'Unknown template'));
        $o->post('/organization/facilities', ['code' => 'ORPHAN', 'name' => 'X', 'parentId' => Ids::uuid7()])->assertStatus(404);
        $o->post('/organization/facilities', ['code' => 'BADTZ', 'name' => 'X', 'timezone' => 'Mars/Base'])->assertStatus(422)->assertJsonValidationErrors(['timezone']);
        $o->post('/organization/facilities', ['code' => 'BADHRS', 'name' => 'X', 'openingHours' => ['weekly' => ['mon' => [['open' => '10:00', 'close' => '09:00']]]]])->assertStatus(422);
        $o->post('/organization/facilities', ['code' => 'BADHRS2', 'name' => 'X', 'openingHours' => ['weekly' => ['funday' => []]]])->assertStatus(422);
    }

    public function test_full_details_and_lowercase_code_normalised(): void
    {
        $hours = ['weekly' => ['mon' => [['open' => '08:00', 'close' => '12:00'], ['open' => '13:00', 'close' => '22:00']], 'sun' => []],
            'exceptions' => [['date' => '2026-12-25', 'closed' => true, 'note' => 'Christmas'], ['date' => '2026-12-31', 'closed' => false, 'windows' => [['open' => '10:00', 'close' => '24:00']]]]];
        $r = $this->owner()->post('/organization/facilities', [
            'code' => 'lounge_x', 'name' => 'Lounge X', 'kind' => 'bar', 'description' => 'Rooftop', 'timezone' => 'Africa/Lagos', 'sortOrder' => 7, 'active' => true,
            'contact' => ['phone' => '+2348000000000', 'email' => 'lounge@example.com'], 'openingHours' => $hours,
            'parentId' => DemoIds::facility('RESTAURANT'),
        ])->assertStatus(201);
        $this->assertSame('LOUNGE_X', $r->json('code'));
        $this->assertSame('BAR', $r->json('kind'));
        $this->assertEquals($this->ksortR($hours), $this->ksortR($r->json('openingHours')));
        $this->assertSame(DemoIds::facility('RESTAURANT'), $r->json('parentId'));
        $tree = $this->owner()->get('/organization/facilities')->json('items');
        $rest = collect($tree)->firstWhere('code', 'RESTAURANT');
        $this->assertSame('LOUNGE_X', $rest['children'][0]['code']);
    }

    public function test_patch_requires_if_match_and_detects_concurrent_edits(): void
    {
        $id = $this->owner()->post('/organization/facilities', ['code' => 'PATCHME', 'name' => 'Patch me'])->json('id');
        $o = $this->owner();
        $o->patch("/organization/facilities/{$id}", ['name' => 'No header'])->assertStatus(428)->assertJsonPath('code', 'concurrency_conflict');

        $ok = $o->patch("/organization/facilities/{$id}", ['name' => 'Renamed', 'description' => 'd'], ['If-Match' => '"1"'])->assertOk()->assertHeader('ETag', '"2"');
        $this->assertSame('Renamed', $ok->json('name'));
        // stale writer (still holding version 1) loses with 412 and changes nothing
        $o->patch("/organization/facilities/{$id}", ['name' => 'Stale'], ['If-Match' => '"1"'])->assertStatus(412)->assertJsonPath('code', 'concurrency_conflict');
        $this->assertSame('Renamed', DB::table('facility_unit')->where('id', Ids::toBinary($id))->value('name'));
        $o->patch("/organization/facilities/{$id}", ['code' => 'OTHER'], ['If-Match' => '"2"'])->assertStatus(422);
        // no-op edit does not bump the version
        $o->patch("/organization/facilities/{$id}", ['name' => 'Renamed'], ['If-Match' => '"2"'])->assertOk()->assertHeader('ETag', '"2"');

        $a = $this->audit('config.facility.update', $id);
        $this->assertCount(1, $a);
        $this->assertSame(['name' => 'Renamed', 'description' => 'd'], $a[0]->new);
        $this->assertSame(['name' => 'Patch me', 'description' => null], $a[0]->old);
        $ev = $this->outbox($id, 'facilityDetails');
        $this->assertSame([2], array_column($ev, 'version'));
        $this->assertSame(['name' => 'Renamed', 'description' => 'd'], $ev[0]['payload']['changes']);
    }

    public function test_deactivate_refused_with_open_orders_then_allowed_and_reactivate(): void
    {
        $o = $this->owner();
        $rest = DemoIds::facility('CAFE');
        $version = DB::table('facility_unit')->where('id', Ids::toBinary($rest))->value('row_version');
        $this->openOrderAt($rest);

        $r = $o->post("/organization/facilities/{$rest}/deactivate", ['reason' => 'closing'], ['If-Match' => '"'.$version.'"'], idem: false);
        $r->assertStatus(409)->assertJsonPath('code', 'facility_in_use');
        $this->assertSame('open_orders', $r->json('blockers.0.type'));
        $this->assertStringContainsString('open order', $r->json('detail'));
        $this->assertSame(1, DB::table('facility_unit')->where('id', Ids::toBinary($rest))->value('is_active'));

        DB::table('order')->where('facility_unit_id', Ids::toBinary($rest))->update(['status' => 'VOIDED']);
        $d = $o->post("/organization/facilities/{$rest}/deactivate", ['reason' => 'closing'], ['If-Match' => '"'.$version.'"'], idem: false)->assertOk();
        $this->assertSame('INACTIVE', $d->json('status'));
        $this->assertSame('closing', $d->json('deactivationReason'));
        $this->assertNotNull(DB::table('facility_unit')->where('id', Ids::toBinary($rest))->first()); // never deleted
        $this->assertCount(1, $this->audit('config.facility.deactivate', $rest));

        $again = $o->get("/organization/facilities/{$rest}")->assertOk();
        $rv = $again->json('rowVersion');
        $o->post("/organization/facilities/{$rest}/reactivate", [], ['If-Match' => '"'.$rv.'"'], idem: false)->assertOk()->assertJsonPath('status', 'ACTIVE');
    }

    public function test_deactivate_with_children_needs_cascade(): void
    {
        $o = $this->owner();
        $arena = DemoIds::facility('SPORTS_ARENA');
        $v = (int) DB::table('facility_unit')->where('id', Ids::toBinary($arena))->value('row_version');
        $o->post("/organization/facilities/{$arena}/deactivate", [], ['If-Match' => '"'.$v.'"'], idem: false)->assertStatus(409)->assertJsonPath('blockers.0.type', 'active_child');
        $o->post("/organization/facilities/{$arena}/deactivate", ['cascade' => true], ['If-Match' => '"'.$v.'"'], idem: false)->assertOk();
        $this->assertSame(0, DB::table('facility_unit')->where('id', Ids::toBinary(DemoIds::facility('FOOTBALL')))->value('is_active'));
    }

    public function test_move_is_cycle_safe(): void
    {
        $o = $this->owner();
        $arena = DemoIds::facility('SPORTS_ARENA');
        $ball = DemoIds::facility('BASKETBALL');
        $ver = fn ($id) => '"'.DB::table('facility_unit')->where('id', Ids::toBinary($id))->value('row_version').'"';
        $o->post("/organization/facilities/{$arena}/move", ['parentId' => $ball], ['If-Match' => $ver($arena)], idem: false)->assertStatus(422)->assertJsonPath('code', 'facility_cycle');
        $o->post("/organization/facilities/{$arena}/move", ['parentId' => $arena], ['If-Match' => $ver($arena)], idem: false)->assertStatus(422)->assertJsonPath('code', 'facility_cycle');
        $o->post("/organization/facilities/{$ball}/move", ['parentId' => DemoIds::facility('SUPERMARKET')], ['If-Match' => $ver($ball)], idem: false)->assertOk()->assertJsonPath('parentId', DemoIds::facility('SUPERMARKET'));
        $o->post("/organization/facilities/{$ball}/move", ['parentId' => null], ['If-Match' => $ver($ball)], idem: false)->assertOk()->assertJsonPath('parentId', null);
        $this->assertCount(2, $this->audit('config.facility.move', $ball));
    }

    public function test_delete_only_when_no_history(): void
    {
        $o = $this->owner();
        $id = $o->post('/organization/facilities', ['code' => 'OOPS', 'name' => 'Created by mistake'])->json('id');
        $o->delete("/organization/facilities/{$id}", idem: false)->assertNoContent();
        $this->assertNotNull(DB::table('facility_unit')->where('id', Ids::toBinary($id))->whereNotNull('deleted_at')->first(), 'soft-deleted, not removed');
        $o->post('/organization/facilities', ['code' => 'OOPS', 'name' => 'Again'])->assertStatus(409); // code stays reserved

        $o->delete('/organization/facilities/'.DemoIds::facility('RESTAURANT'), idem: false)->assertStatus(409)->assertJsonPath('code', 'facility_has_history');
    }

    public function test_permission_denied_for_manager_named_role_without_facility_manage(): void
    {
        $m = $this->managerLacking(['facility.manage']);
        $m->post('/organization/facilities', ['code' => 'NOPE1', 'name' => 'X'])->assertStatus(403)->assertJsonPath('code', 'permission_denied')->assertJsonPath('permission', 'facility.manage');
        $m->patch('/organization/facilities/'.DemoIds::facility('CAFE'), ['name' => 'X'], ['If-Match' => '"1"'])->assertStatus(403);
        $m->get('/organization/facility-templates')->assertOk(); // config.view still granted
        $this->assertSame(0, DB::table('facility_unit')->where('code', 'NOPE1')->count());
    }

    public function test_owner_holds_every_config_permission(): void
    {
        $owner = DB::table('role')->where('code', 'OWNER')->value('id');
        foreach (['facility.manage', 'config.view', 'config.manage.capabilities', 'config.manage.rules', 'device.manage', 'ticket_type.manage', 'settings.manage', 'role.manage'] as $p) {
            $this->assertTrue(DB::table('role_permission as rp')->join('permission as x', 'x.id', '=', 'rp.permission_id')->where('rp.role_id', $owner)->where('x.code', $p)->exists(), $p);
        }
    }

    public function test_idempotent_create_replays(): void
    {
        $key = ['Idempotency-Key' => 'idem-'.bin2hex(random_bytes(6))];
        $o = $this->owner();
        $a = $o->post('/organization/facilities', ['code' => 'IDEM1', 'name' => 'Idem'], $key, idem: false)->assertStatus(201);
        $b = $o->post('/organization/facilities', ['code' => 'IDEM1', 'name' => 'Idem'], $key, idem: false)->assertStatus(201)->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($a->json('id'), $b->json('id'));
        $this->assertSame(1, DB::table('facility_unit')->where('code', 'IDEM1')->count());
    }

    private function openOrderAt(string $facilityId): void
    {
        $cols = DB::table('order')->first();
        // build a minimal open order by copying a demo order shape when one exists, otherwise via the API-independent fixture below
        $tenant = ['org' => DemoIds::org(), 'site' => DemoIds::site()];
        $staff = DB::table('staff')->first(['id']);
        DB::table('order')->insert([
            'id' => Ids::toBinary(Ids::uuid7()), 'organization_id' => Ids::toBinary($tenant['org']), 'site_id' => Ids::toBinary($tenant['site']),
            'facility_unit_id' => Ids::toBinary($facilityId), 'order_number' => 'CFG-'.random_int(100000, 999999), 'status' => 'SENT', 'created_by' => $staff->id,
        ]);
    }
}

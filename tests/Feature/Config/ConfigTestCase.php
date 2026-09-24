<?php

namespace Tests\Feature\Config;

use App\Support\Demo\DemoIds;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\Support\DemoApi;
use Tests\Support\TestData;
use Tests\Support\TestResponseBuilder;
use Tests\TestCase;

/** Shared helpers: demo property + an OWNER (`owner1`), and helpers to read audit / outbox rows. */
abstract class ConfigTestCase extends TestCase
{
    use DemoApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    private ?TestResponseBuilder $ownerApi = null;

    protected function owner(): TestResponseBuilder
    {
        return $this->ownerApi ??= $this->api('owner1');
    }

    /** @return list<object> audit rows (decoded old/new) for an action, newest last */
    protected function audit(string $action, ?string $entityId = null): array
    {
        $q = DB::table('audit_log')->where('action', $action)->orderBy('seq');
        if ($entityId !== null) {
            $q->where('entity_id', Ids::toBinary($entityId));
        }

        return $q->get()->map(function ($r) {
            $r->old = $r->old_value === null ? null : json_decode($r->old_value, true);
            $r->new = $r->new_value === null ? null : json_decode($r->new_value, true);

            return $r;
        })->all();
    }

    /** @return list<array<string, mixed>> ConfigurationUpdated outbox events for an entity (payload decoded) */
    protected function outbox(string $entityId, ?string $domain = null): array
    {
        return DB::table('outbox_event')->where('event_type', 'ConfigurationUpdated')->where('entity_id', Ids::toBinary($entityId))->orderBy('created_at')->orderBy('entity_version')->get()
            ->map(fn ($r) => ['version' => (int) $r->entity_version, 'payload' => json_decode($r->payload, true)])
            ->filter(fn ($e) => $domain === null || $e['payload']['domain'] === $domain)->values()->all();
    }

    /** MySQL JSON columns reorder object keys; compare structures order-insensitively. */
    protected function ksortR(mixed $v): mixed
    {
        if (is_array($v)) {
            $v = array_map(fn ($x) => $this->ksortR($x), $v);
            if (! array_is_list($v)) {
                ksort($v);
            }
        }

        return $v;
    }

    /** A role literally named "Manager" that lacks the given permissions (permission-based auth must not care about the name). */
    protected function managerLacking(array $except): TestResponseBuilder
    {
        $perms = DB::table('role_permission as rp')->join('permission as p', 'p.id', '=', 'rp.permission_id')->join('role as r', 'r.id', '=', 'rp.role_id')
            ->where('r.code', 'MANAGER')->pluck('p.code')->diff($except)->values()->all();
        $role = TestData::customRole('CFG_MGR_'.bin2hex(random_bytes(3)), 'Manager', $perms);
        $tenant = ['org' => DemoIds::org(), 'site' => DemoIds::site()];
        $staff = TestData::staff($tenant, 'lackmgr'.bin2hex(random_bytes(2)), TestData::PASSWORD, '1234');
        TestData::assignRole($staff, $role, 'SITE');

        return new TestResponseBuilder($this, $this->loginFor($staff), null);
    }

    /** @return array<string, mixed> AuthResult for an ad-hoc staff member (PIN 1234). */
    protected function loginFor($staff): array
    {
        $username = DB::table('user_account')->where('staff_id', Ids::toBinary($staff->id))->value('username');
        $r = $this->postJson('/api/v1/auth/staff/login', ['credentialType' => 'PIN', 'identifier' => $username, 'secret' => '1234']);
        $r->assertOk();

        return $r->json();
    }
}

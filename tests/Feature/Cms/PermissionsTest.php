<?php

namespace Tests\Feature\Cms;

use App\Support\Demo\DemoIds;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestData;
use Tests\Support\TestResponseBuilder;

class PermissionsTest extends CmsTestCase
{
    /** @return list<array{0: string, 1: string, 2: string}> method, path, permission */
    private function surface(): array
    {
        return [
            ['get', '/admin/cms/meta', 'cms.view'], ['get', '/admin/cms/summary', 'cms.view'], ['get', '/admin/cms/settings', 'cms.view'], ['get', '/admin/cms/pages', 'cms.view'],
            ['get', '/admin/cms/media', 'cms.view'], ['get', '/admin/cms/posts', 'cms.view'], ['get', '/admin/cms/events', 'cms.view'], ['get', '/admin/cms/home-sections', 'cms.view'],
            ['get', '/admin/cms/gallery/albums', 'cms.view'], ['get', '/admin/cms/post-categories', 'cms.view'],
            ['post', '/admin/cms/pages', 'cms.manage'], ['put', '/admin/cms/settings/brand', 'cms.manage'], ['post', '/admin/cms/home-sections', 'cms.manage'],
            ['post', '/admin/cms/pages/00000000-0000-7000-8000-000000000001/publish', 'cms.publish'], ['post', '/admin/cms/home-sections/00000000-0000-7000-8000-000000000001/enable', 'cms.publish'],
            ['patch', '/admin/cms/media/00000000-0000-7000-8000-000000000001', 'cms.media.manage'], ['delete', '/admin/cms/media/00000000-0000-7000-8000-000000000001', 'cms.media.manage'],
            ['get', '/admin/cms/subscribers', 'cms.subscribers.view'], ['get', '/admin/cms/subscribers/export', 'cms.subscribers.export'],
            ['get', '/admin/cms/messages', 'cms.messages.manage'], ['delete', '/admin/cms/subscribers/00000000-0000-7000-8000-000000000001', 'cms.manage'],
        ];
    }

    private function call2($api, string $method, string $path): int
    {
        return $api->{$method}(str_replace('/api/v1', '', $path), $method === 'get' ? [] : [])->getStatusCode();
    }

    public function test_seeded_role_bundles(): void
    {
        $bundle = fn (string $role) => DB::table('role_permission as rp')->join('permission as p', 'p.id', '=', 'rp.permission_id')->join('role as r', 'r.id', '=', 'rp.role_id')
            ->where('r.code', $role)->where('p.code', 'like', 'cms.%')->orderBy('p.code')->pluck('p.code')->all();
        $all = ['cms.manage', 'cms.media.manage', 'cms.messages.manage', 'cms.publish', 'cms.subscribers.export', 'cms.subscribers.view', 'cms.view'];
        $this->assertSame($all, $bundle('OWNER'));
        $this->assertSame($all, $bundle('MANAGER'));
        $this->assertSame($all, $bundle('MARKETING'));
        $this->assertSame(['cms.manage', 'cms.media.manage', 'cms.view'], $bundle('IT_ADMIN'));
        $this->assertSame([], $bundle('CASHIER'));
        $this->assertSame([], $bundle('WAIT_STAFF'));
    }

    public function test_unauthenticated_and_foreign_credentials_are_refused(): void
    {
        $this->getJson('/api/v1/admin/cms/meta')->assertStatus(401);
        $this->getJson('/api/v1/public/cms/site')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        // the website service token can read public content but never reaches admin
        $this->withHeaders($this->site())->getJson('/api/v1/admin/cms/meta')->assertStatus(401);
        $this->getJson('/api/v1/public/cms/site', ['Authorization' => 'Bearer r7s_not_a_token'])->assertStatus(401);
    }

    public function test_permission_matrix(): void
    {
        $expect = [
            'OWNER' => ['cms.view', 'cms.manage', 'cms.publish', 'cms.media.manage', 'cms.subscribers.view', 'cms.subscribers.export', 'cms.messages.manage'],
            'MANAGER' => ['cms.view', 'cms.manage', 'cms.publish', 'cms.media.manage', 'cms.subscribers.view', 'cms.subscribers.export', 'cms.messages.manage'],
            'MARKETING' => ['cms.view', 'cms.manage', 'cms.publish', 'cms.media.manage', 'cms.subscribers.view', 'cms.subscribers.export', 'cms.messages.manage'],
            'IT_ADMIN' => ['cms.view', 'cms.manage', 'cms.media.manage'],
            'CASHIER' => [],
            'ACCOUNTANT' => [],
        ];
        foreach ($expect as $role => $held) {
            $api = $this->roleApi($role);
            foreach ($this->surface() as [$method, $path, $perm]) {
                $status = $api->{$method}($path, [])->getStatusCode();
                if (in_array($perm, $held, true)) {
                    $this->assertNotSame(403, $status, "{$role} should reach {$method} {$path} ({$perm}), got {$status}");
                    $this->assertNotSame(401, $status, "{$role} {$method} {$path}");
                } else {
                    $this->assertSame(403, $status, "{$role} must NOT reach {$method} {$path} ({$perm}), got {$status}");
                }
            }
        }
    }

    public function test_denied_response_names_the_permission(): void
    {
        $r = $this->roleApi('CASHIER')->get('/admin/cms/pages')->assertStatus(403);
        $this->assertSame('permission_denied', $r->json('code'));
        $this->assertSame('cms.view', $r->json('permission'));
    }

    public function test_custom_role_named_marketing_without_permissions_is_denied(): void
    {
        // authorization is permission based: the role's NAME grants nothing
        $role = TestData::customRole('FAKE_MKT', 'Marketing', ['cms.view']);
        $tenant = ['org' => DemoIds::org(), 'site' => DemoIds::site()];
        $staff = TestData::staff($tenant, 'fakemkt', TestData::PASSWORD, '1234');
        TestData::assignRole($staff, $role, 'SITE');
        $auth = $this->postJson('/api/v1/auth/staff/login', ['credentialType' => 'PIN', 'identifier' => 'fakemkt', 'secret' => '1234'])->json();
        $api = new TestResponseBuilder($this, $auth, null);
        $api->get('/admin/cms/pages')->assertOk();
        $api->post('/admin/cms/pages', ['title' => 'x', 'bodyMarkdown' => 'y'])->assertStatus(403);
    }
}

<?php

namespace App\Domain\Customer\Services;

use App\Domain\Customer\Models\ServiceToken;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The website's read-only `public.read` credential (env `R007_API_SERVICE_TOKEN` on the website side).
 * Only SHA-256(token) is stored; the plaintext is shown ONCE at creation/rotation. Rotation issues a new token and lets the old
 * one live for a grace window (default 24h) so the website can be redeployed without an outage; `revoke` is immediate.
 */
class ServiceTokenService
{
    public const PREFIX = 'r7s_';

    /** @return array{id: string, token: string, name: string, scope: string} */
    public function create(string $name, ?string $organizationId = null, ?string $rotatedFrom = null, ?string $plain = null, ?string $scope = null): array
    {
        $org = $organizationId ?? Tenant::organizationId() ?? throw ApiProblem::badRequest('tenant_unresolved', 'No organization in context.');
        $token = $plain ?? self::PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $id = Ids::uuid7();
        $scope = self::normalizeScope($scope);

        return DB::transaction(function () use ($id, $org, $name, $token, $rotatedFrom, $scope) {
            DB::table('service_token')->insert([
                'id' => Ids::toBinary($id), 'organization_id' => Ids::toBinary($org), 'name' => mb_substr($name, 0, 80),
                'token_prefix' => substr($token, 0, 8), 'token_hash' => hash('sha256', $token), 'scope' => $scope,
                'rotated_from_id' => $rotatedFrom ? Ids::toBinary($rotatedFrom) : null,
            ]);
            Audit::record('service_token.create', 'ServiceToken', $id, null, ['name' => $name, 'scope' => $scope, 'rotatedFrom' => $rotatedFrom], organizationId: $org, siteId: Tenant::siteId());

            return ['id' => $id, 'token' => $token, 'name' => $name, 'scope' => $scope];
        });
    }

    /** @return array{id: string, token: string, name: string, scope: string, oldTokenExpiresAt: ?string} */
    public function rotate(string $id, bool $immediate = false): array
    {
        $old = ServiceToken::query()->find($id);
        if ($old === null || $old->revoked_at !== null) {
            throw ApiProblem::notFound('not_found', 'Service token not found.');
        }
        $new = $this->create($old->name, $old->organization_id, $old->id, null, $old->scope); // rotation keeps the scope
        $expires = $immediate ? CarbonImmutable::now('UTC') : CarbonImmutable::now('UTC')->addHours((int) config('customer.service_token_grace_hours'));
        DB::table('service_token')->where('id', Ids::toBinary($id))->update(['expires_at' => $expires->format('Y-m-d H:i:s.u'), 'is_active' => $immediate ? 0 : 1]);
        Audit::record('service_token.rotate', 'ServiceToken', $id, null, ['newId' => $new['id'], 'oldExpiresAt' => $expires->format('Y-m-d\TH:i:s\Z')], organizationId: $old->organization_id, siteId: Tenant::siteId());

        return $new + ['oldTokenExpiresAt' => $expires->format('Y-m-d\TH:i:s\Z')];
    }

    /** Comma-set of known scopes, default `public.read`. */
    public static function normalizeScope(?string $scope): string
    {
        $list = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($scope ?: ServiceToken::SCOPE_PUBLIC_READ))))));
        foreach ($list as $s) {
            if (! in_array($s, ServiceToken::KNOWN_SCOPES, true)) {
                throw ApiProblem::unprocessable('validation_failed', "Unknown scope '{$s}'.", ['scope' => ['unknown scope']]);
            }
        }

        return implode(',', $list);
    }

    public function revoke(string $id): void
    {
        $n = DB::table('service_token')->where('id', Ids::toBinary($id))->whereNull('revoked_at')
            ->update(['revoked_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'is_active' => 0]);
        if ($n === 0) {
            throw ApiProblem::notFound('not_found', 'Service token not found.');
        }
        Audit::record('service_token.revoke', 'ServiceToken', $id, null, ['revoked' => true], siteId: Tenant::siteId());
    }

    public function authenticate(string $token): ?ServiceToken
    {
        $row = ServiceToken::query()->where('token_hash', hash('sha256', $token))->first();
        $now = now('UTC');
        if ($row === null || ! $row->is_active || $row->revoked_at !== null || ($row->expires_at !== null && $row->expires_at->lte($now)) || array_intersect($row->scopes(), ServiceToken::KNOWN_SCOPES) === []) {
            return null;
        }
        if ($row->last_used_at === null || $row->last_used_at->lt($now->copy()->subMinute())) {
            DB::table('service_token')->where('id', Ids::toBinary($row->id))->update(['last_used_at' => $now->format('Y-m-d H:i:s.u')]);
        }

        return $row;
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return DB::table('service_token')->orderByDesc('created_at')->get()->map(fn ($r) => [
            'id' => Ids::fromBinary($r->id), 'name' => $r->name, 'prefix' => $r->token_prefix, 'scope' => $r->scope, 'active' => (bool) $r->is_active && $r->revoked_at === null,
            'expiresAt' => $r->expires_at ? CarbonImmutable::parse($r->expires_at, 'UTC')->format('Y-m-d\TH:i:s\Z') : null,
            'lastUsedAt' => $r->last_used_at ? CarbonImmutable::parse($r->last_used_at, 'UTC')->format('Y-m-d\TH:i:s\Z') : null,
            'createdAt' => CarbonImmutable::parse($r->created_at, 'UTC')->format('Y-m-d\TH:i:s\Z'),
        ])->all();
    }
}

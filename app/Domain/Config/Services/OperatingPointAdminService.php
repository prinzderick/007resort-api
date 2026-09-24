<?php

namespace App\Domain\Config\Services;

use App\Domain\Config\Support\ConfigChange;
use App\Support\Api\Concurrency;
use App\Support\Api\Fmt;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Operating points (counters, table areas, gates, store windows, rooms and KDS stations). A STATION operating point can carry a
 * `kds_station` row with the SAME id (the convention the Hospitality module and the demo seed already use), so
 * operating_point.default_prep_station_id, kds_station, prep routing and realtime channel auth all agree.
 */
class OperatingPointAdminService
{
    public const KINDS = ['TABLE_AREA', 'COUNTER', 'GATE', 'STORE_WINDOW', 'STATION', 'ROOM'];

    public function __construct(private readonly FacilityLoader $facilities) {}

    /** @return array<string, mixed> */
    public function present(object $r): array
    {
        $st = DB::table('kds_station')->where('id', $r->id)->first();

        return [
            'id' => Ids::fromBinary($r->id), 'facilityId' => Ids::fromBinary($r->facility_unit_id), 'code' => $r->code, 'name' => $r->name, 'kind' => $r->kind,
            'defaultPrepStationId' => Fmt::u($r->default_prep_station_id), 'active' => (bool) $r->is_active, 'rowVersion' => (int) $r->row_version,
            'kdsStation' => $st === null ? null : ['id' => Ids::fromBinary($st->id), 'kind' => $st->kind, 'prepRouteId' => Fmt::u($st->prep_route_id), 'active' => (bool) $st->is_active],
        ];
    }

    /**
     * @param  array<string, mixed>  $in  code, name, kind, defaultPrepStationId?, kdsStation? {kind, prepRouteId?}
     * @return array<string, mixed>
     */
    public function create(string $facilityId, array $in): array
    {
        try {
            return DB::transaction(function () use ($facilityId, $in): array {
                $f = $this->facilities->lock($facilityId);
                if (! $f->is_active) {
                    throw ApiProblem::unprocessable('validation_failed', 'The facility is inactive.', ['facilityId' => ['Reactivate the facility first.']]);
                }

                return $this->insert($f, $in);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw ApiProblem::conflict('operating_point_code_taken', 'This facility already has an operating point with that code.');
            }
            throw $e;
        }
    }

    /** Insert inside the caller's transaction. @param array<string, mixed> $in @return array<string, mixed> */
    public function insert(object $f, array $in): array
    {
        $code = strtoupper($in['code']);
        if (DB::table('operating_point')->where('facility_unit_id', $f->id)->where('code', $code)->exists()) {
            throw ApiProblem::conflict('operating_point_code_taken', 'This facility already has an operating point with that code.');
        }
        $kds = $in['kdsStation'] ?? null;
        if ($kds !== null && $in['kind'] !== 'STATION') {
            throw ApiProblem::unprocessable('validation_failed', 'Only STATION operating points can carry a KDS station.', ['kdsStation' => ['Use kind STATION.']]);
        }
        $prep = $in['defaultPrepStationId'] ?? null;
        if ($prep !== null) {
            $this->assertStation($prep);
        }
        $id = Ids::uuid7();
        DB::table('operating_point')->insert([
            'id' => Ids::toBinary($id), 'organization_id' => $f->organization_id, 'site_id' => $f->site_id, 'facility_unit_id' => $f->id,
            'code' => $code, 'name' => $in['name'], 'kind' => $in['kind'], 'default_prep_station_id' => Fmt::b($prep), 'is_active' => 1, 'row_version' => 1,
        ]);
        if ($kds !== null) {
            $this->insertStation($f, $id, $code, $in['name'], $kds['kind'], $kds['prepRouteId'] ?? null);
        }
        $row = DB::table('operating_point')->where('id', Ids::toBinary($id))->first();
        $view = $this->present($row);
        ConfigChange::record('config.operating_point.create', 'OperatingPoint', $id, null, $view, 'operatingPoint', ['operatingPoint' => $view], 1,
            facilityId: Ids::fromBinary($f->id), organizationId: Ids::fromBinary($f->organization_id), siteId: Ids::fromBinary($f->site_id));

        return $view;
    }

    /** Starter operating points (+ KDS station) of a facility template. @return list<array<string, mixed>> */
    public function createStarterKit(object $f, array $template): array
    {
        $out = [];
        foreach ($template['starterOperatingPoints'] as $p) {
            $out[] = $this->insert($f, $p);
        }
        if (($s = $template['starterKdsStation']) !== null) {
            $out[] = $this->insert($f, ['code' => $s['code'], 'name' => $s['name'], 'kind' => 'STATION', 'kdsStation' => ['kind' => $s['kind']]]);
        }

        return $out;
    }

    /** @param array<string, mixed> $in name?, defaultPrepStationId?, kdsStation? {prepRouteId?} @return array<string, mixed> */
    public function update(string $id, array $in, ?int $ifMatch): array
    {
        return DB::transaction(function () use ($id, $in, $ifMatch): array {
            $r = $this->lock($id);
            Concurrency::assertVersion((int) $r->row_version, $ifMatch, 'operating point');
            $old = $this->present($r);
            $set = [];
            if (array_key_exists('name', $in)) {
                $set['name'] = $in['name'];
            }
            if (array_key_exists('defaultPrepStationId', $in)) {
                if ($in['defaultPrepStationId'] !== null) {
                    $this->assertStation($in['defaultPrepStationId']);
                }
                $set['default_prep_station_id'] = Fmt::b($in['defaultPrepStationId']);
            }
            if (isset($in['kdsStation'])) {
                $st = DB::table('kds_station')->where('id', $r->id)->lockForUpdate()->first();
                if ($st === null) {
                    throw ApiProblem::unprocessable('validation_failed', 'This operating point has no KDS station.', ['kdsStation' => ['Not a KDS station.']]);
                }
                if (array_key_exists('prepRouteId', $in['kdsStation'])) {
                    $this->assertRoute($in['kdsStation']['prepRouteId']);
                    DB::table('kds_station')->where('id', $r->id)->update(['prep_route_id' => Fmt::b($in['kdsStation']['prepRouteId']), 'row_version' => $st->row_version + 1]);
                }
            }
            if (isset($set['name']) && DB::table('kds_station')->where('id', $r->id)->exists()) {
                DB::table('kds_station')->where('id', $r->id)->update(['name' => $set['name']]);
            }
            $version = (int) $r->row_version + 1;
            DB::table('operating_point')->where('id', $r->id)->update($set + ['row_version' => $version]);
            $new = $this->present(DB::table('operating_point')->where('id', $r->id)->first());
            ConfigChange::record('config.operating_point.update', 'OperatingPoint', $id, $old, $new, 'operatingPoint', ['operatingPoint' => $new], $version,
                facilityId: Ids::fromBinary($r->facility_unit_id), organizationId: Ids::fromBinary($r->organization_id), siteId: Ids::fromBinary($r->site_id));

            return $new;
        });
    }

    /** @return array<string, mixed> */
    public function setActive(string $id, bool $active, ?int $ifMatch): array
    {
        return DB::transaction(function () use ($id, $active, $ifMatch): array {
            $r = $this->lock($id);
            Concurrency::assertVersion((int) $r->row_version, $ifMatch, 'operating point');
            if ((bool) $r->is_active === $active) {
                return $this->present($r);
            }
            if (! $active) {
                $blockers = [];
                if (($n = DB::table('device')->where('operating_point_id', $r->id)->where('is_revoked', 0)->count()) > 0) {
                    $blockers[] = ['type' => 'assigned_devices', 'count' => $n, 'message' => "{$n} device(s) are assigned to this operating point; reassign them first."];
                }
                if (DB::table('kds_station')->where('id', $r->id)->exists()
                    && ($n = DB::table('prep_ticket')->where('station_id', $r->id)->whereIn('status', ['NEW', 'ACCEPTED', 'IN_PROGRESS', 'READY'])->count()) > 0) {
                    $blockers[] = ['type' => 'open_prep_tickets', 'count' => $n, 'message' => "{$n} prep ticket(s) are still open on this station."];
                }
                if (($n = DB::table('dining_table')->where('operating_point_id', $r->id)->where('is_active', 1)->count()) > 0) {
                    $blockers[] = ['type' => 'active_tables', 'count' => $n, 'message' => "{$n} active table(s) belong to this area; move or deactivate them first."];
                }
                if ($blockers !== []) {
                    throw ApiProblem::conflict('operating_point_in_use', 'Cannot deactivate yet: '.implode(' ', array_column($blockers, 'message')), ['blockers' => $blockers]);
                }
            }
            $version = (int) $r->row_version + 1;
            DB::table('operating_point')->where('id', $r->id)->update(['is_active' => $active ? 1 : 0, 'row_version' => $version]);
            DB::table('kds_station')->where('id', $r->id)->update(['is_active' => $active ? 1 : 0]);
            $new = $this->present(DB::table('operating_point')->where('id', $r->id)->first());
            ConfigChange::record($active ? 'config.operating_point.reactivate' : 'config.operating_point.deactivate', 'OperatingPoint', $id, ['active' => ! $active], ['active' => $active],
                'operatingPoint', ['operatingPoint' => $new], $version, facilityId: Ids::fromBinary($r->facility_unit_id), organizationId: Ids::fromBinary($r->organization_id), siteId: Ids::fromBinary($r->site_id));

            return $new;
        });
    }

    public function lock(string $id): object
    {
        if (! Ids::isUuid($id)) {
            throw ApiProblem::notFound('not_found', 'Operating point was not found.');
        }

        return DB::table('operating_point')->where('id', Ids::toBinary($id))->where('site_id', Ids::toBinary((string) Tenant::siteId()))->lockForUpdate()->first()
            ?? throw ApiProblem::notFound('not_found', 'Operating point was not found.');
    }

    private function insertStation(object $f, string $id, string $code, string $name, string $kind, ?string $routeId): void
    {
        $route = $routeId !== null ? $this->assertRoute($routeId) : $this->ensurePrepRoute($f->organization_id, $kind === 'BAR' ? 'BAR' : 'KITCHEN');
        DB::table('kds_station')->insert([
            'id' => Ids::toBinary($id), 'organization_id' => $f->organization_id, 'site_id' => $f->site_id, 'facility_unit_id' => $f->id, 'prep_route_id' => $route,
            'code' => $code, 'name' => $name, 'kind' => $kind, 'is_active' => 1, 'row_version' => 1,
        ]);
        DB::table('prep_ticket_counter')->insertOrIgnore(['station_id' => Ids::toBinary($id)]);
    }

    private function assertStation(string $id): void
    {
        if (! Ids::isUuid($id) || ! DB::table('kds_station')->where('id', Ids::toBinary($id))->where('organization_id', Ids::toBinary((string) Tenant::organizationId()))->exists()) {
            throw ApiProblem::unprocessable('validation_failed', 'Unknown KDS station.', ['defaultPrepStationId' => ['Must be an existing KDS station.']]);
        }
    }

    private function assertRoute(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }
        if (! Ids::isUuid($id) || ! DB::table('prep_route')->where('id', Ids::toBinary($id))->where('organization_id', Ids::toBinary((string) Tenant::organizationId()))->exists()) {
            throw ApiProblem::unprocessable('validation_failed', 'Unknown prep route.', ['prepRouteId' => ['Must be an existing prep route.']]);
        }

        return Ids::toBinary($id);
    }

    /** The organization's prep route of that kind (created on first use so a fresh property works out of the box). */
    public function ensurePrepRoute(string $orgBin, string $kind): string
    {
        $row = DB::table('prep_route')->where('organization_id', $orgBin)->where('kind', $kind)->orderBy('code')->first(['id']);
        if ($row !== null) {
            return $row->id;
        }
        $id = Ids::toBinary(Ids::uuid7());
        DB::table('prep_route')->insert(['id' => $id, 'organization_id' => $orgBin, 'code' => $kind, 'name' => ucfirst(strtolower($kind)), 'kind' => $kind]);

        return $id;
    }
}

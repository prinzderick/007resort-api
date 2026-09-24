<?php

namespace App\Domain\Config\Services;

use App\Domain\Config\Support\ConfigChange;
use App\Domain\Config\Support\FacilityTemplates;
use App\Support\Api\Concurrency;
use App\Support\Api\Fmt;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Tenancy\FacilityTree;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Facility lifecycle: create (optionally from a template), edit details, deactivate/reactivate, move, delete-if-unused.
 * Facilities are NEVER hard-deleted once anything references them (ADR-0003 soft state): "delete" only succeeds for a facility with
 * no history at all; otherwise it is refused and deactivation is the answer. Aggregate version = facility_unit.row_version.
 */
class FacilityAdminService
{
    public function __construct(
        private readonly FacilityLoader $facilities,
        private readonly FacilityUsage $usage,
        private readonly CapabilityAdminService $capabilities,
        private readonly OperatingRuleService $rules,
        private readonly OperatingPointAdminService $points,
    ) {}

    /** Admin view of a facility (list/detail/create/update responses). @return array<string, mixed> */
    public function present(object $r, ?array $capabilities = null): array
    {
        $bin = $r->id;
        $capabilities ??= DB::table('facility_capability')->where('facility_unit_id', $bin)->where('is_enabled', 1)->orderBy('capability_code')->pluck('capability_code')->all();

        return [
            'id' => Ids::fromBinary($bin), 'siteId' => Ids::fromBinary($r->site_id), 'parentId' => Fmt::u($r->parent_id), 'code' => $r->code, 'name' => $r->name,
            'kind' => $r->kind ?? 'GENERAL', 'description' => $r->description, 'timezone' => $r->timezone, 'sortOrder' => (int) $r->sort_order,
            'contact' => $r->contact === null ? null : json_decode($r->contact, true), 'openingHours' => $r->opening_hours === null ? null : json_decode($r->opening_hours, true),
            'templateKey' => $r->template_key, 'active' => (bool) $r->is_active, 'status' => $r->is_active ? 'ACTIVE' : 'INACTIVE',
            'deactivatedAt' => Fmt::ts($r->deactivated_at), 'deactivationReason' => $r->deactivation_reason,
            'capabilities' => $capabilities, 'rowVersion' => (int) $r->row_version,
        ];
    }

    /**
     * @param  array<string, mixed>  $in  validated: code, name, kind?, parentId?, description?, timezone?, sortOrder?, active?, contact?, openingHours?,
     *                                    templateKey?, capabilities?, operatingRules?, applyStarter?
     * @return array<string, mixed> facility incl. operatingRules
     */
    public function create(array $in): array
    {
        $tpl = null;
        if (! empty($in['templateKey'])) {
            $tpl = FacilityTemplates::get($in['templateKey']) ?? throw ApiProblem::unprocessable('validation_failed', 'Unknown template.', ['templateKey' => ['Unknown template. See GET /organization/facility-templates.']]);
        }
        $caps = array_values(array_unique([...($tpl['capabilities'] ?? []), ...($in['capabilities'] ?? [])]));
        sort($caps);
        $depErrors = $this->capabilities->dependencyErrors([], $caps);
        if ($depErrors !== []) {
            throw ApiProblem::unprocessable('capability_dependency', 'Some capabilities need others to be enabled first.', $depErrors);
        }
        $ruleInput = array_replace($tpl['operatingRules'] ?? [], $in['operatingRules'] ?? []);
        [$rules, $ruleErrors] = $this->rules->prepare($ruleInput, $caps, null);
        if ($ruleErrors !== []) {
            throw ApiProblem::unprocessable('validation_failed', 'One or more rules are invalid.', $ruleErrors);
        }

        $siteId = Tenant::siteId() ?? throw ApiProblem::notFound('not_found', 'No site is configured on this node.');
        $orgId = Tenant::organizationId();

        try {
            return DB::transaction(function () use ($in, $tpl, $caps, $rules, $siteId, $orgId): array {
                $parentBin = null;
                if (! empty($in['parentId'])) {
                    $p = $this->facilities->lock($in['parentId']);
                    if (! $p->is_active) {
                        throw ApiProblem::unprocessable('validation_failed', 'The parent facility is inactive.', ['parentId' => ['Reactivate the parent first.']]);
                    }
                    $parentBin = $p->id;
                }
                $code = $in['code'];
                if (DB::table('facility_unit')->where('site_id', Ids::toBinary($siteId))->where('code', $code)->exists()) {
                    throw ApiProblem::conflict('facility_code_taken', "A facility with code {$code} already exists (codes are never reused, even for inactive ones).");
                }
                $id = Ids::uuid7();
                $active = $in['active'] ?? true;
                DB::table('facility_unit')->insert([
                    'id' => Ids::toBinary($id), 'organization_id' => Ids::toBinary($orgId), 'site_id' => Ids::toBinary($siteId), 'parent_id' => $parentBin,
                    'code' => $code, 'name' => $in['name'], 'kind' => $in['kind'] ?? $tpl['defaultKind'] ?? 'GENERAL', 'description' => $in['description'] ?? null,
                    'timezone' => $in['timezone'] ?? null, 'sort_order' => $in['sortOrder'] ?? 0,
                    'contact' => isset($in['contact']) ? json_encode($in['contact']) : null, 'opening_hours' => isset($in['openingHours']) ? json_encode($in['openingHours']) : null,
                    'template_key' => $tpl['key'] ?? null, 'is_active' => $active ? 1 : 0,
                    'deactivated_at' => $active ? null : Fmt::now(), 'row_version' => 1,
                ]);
                foreach ($caps as $c) {
                    DB::table('facility_capability')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'facility_unit_id' => Ids::toBinary($id), 'capability_code' => $c, 'is_enabled' => 1]);
                }
                $row = DB::table('facility_unit')->where('id', Ids::toBinary($id))->first();
                $this->rules->writeInitial($row, $rules, $caps);

                // the facility event goes out BEFORE its starter operating points so the peer can apply them in order
                $snapshot = $this->present($row) + ['operatingRules' => $rules];
                ConfigChange::record('config.facility.create', 'Facility', $id, null, $snapshot, 'facilityFull',
                    ['facility' => $this->present($row), 'operatingRules' => $rules, 'organizationId' => $orgId], 1, facilityId: $id, organizationId: $orgId, siteId: $siteId);

                $starter = [];
                if ($tpl !== null && ($in['applyStarter'] ?? true)) {
                    $starter = $this->points->createStarterKit($row, $tpl);
                }

                return $snapshot + ['starterOperatingPoints' => $starter];
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw ApiProblem::conflict('facility_code_taken', 'A facility with that code already exists.');
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $in changed fields only @return array<string, mixed> */
    public function update(string $id, array $in, ?int $ifMatch): array
    {
        return DB::transaction(function () use ($id, $in, $ifMatch): array {
            $f = $this->facilities->lock($id);
            Concurrency::assertVersion((int) $f->row_version, $ifMatch, 'facility');
            $map = ['name' => 'name', 'kind' => 'kind', 'description' => 'description', 'timezone' => 'timezone', 'sortOrder' => 'sort_order'];
            $set = [];
            $old = [];
            $new = [];
            foreach ($map as $k => $col) {
                if (array_key_exists($k, $in) && $in[$k] !== $f->{$col}) {
                    $old[$k] = $f->{$col};
                    $new[$k] = $in[$k];
                    $set[$col] = $in[$k];
                }
            }
            foreach (['contact' => 'contact', 'openingHours' => 'opening_hours'] as $k => $col) {
                if (array_key_exists($k, $in)) {
                    $before = $f->{$col} === null ? null : json_decode($f->{$col}, true);
                    if ($before !== $in[$k]) {
                        $old[$k] = $before;
                        $new[$k] = $in[$k];
                        $set[$col] = $in[$k] === null ? null : json_encode($in[$k]);
                    }
                }
            }
            if ($set === []) {
                return $this->present($f);
            }
            $version = (int) $f->row_version + 1;
            DB::table('facility_unit')->where('id', $f->id)->update($set + ['row_version' => $version]);
            ConfigChange::record('config.facility.update', 'Facility', $id, $old, $new, 'facilityDetails', $new, $version,
                facilityId: $id, organizationId: Ids::fromBinary($f->organization_id), siteId: Ids::fromBinary($f->site_id));

            return $this->present(DB::table('facility_unit')->where('id', $f->id)->first());
        });
    }

    /** @return array<string, mixed> */
    public function deactivate(string $id, ?string $reason, bool $cascade, ?int $ifMatch): array
    {
        return DB::transaction(function () use ($id, $reason, $cascade, $ifMatch): array {
            $f = $this->facilities->lock($id);
            Concurrency::assertVersion((int) $f->row_version, $ifMatch, 'facility');
            if (! $f->is_active) {
                return $this->present($f); // already inactive: idempotent
            }
            $targets = [Ids::fromBinary($f->id)];
            $blockers = [];
            $activeChildren = array_values(array_filter(FacilityTree::selfAndDescendants($id), fn ($x) => $x !== $id
                && DB::table('facility_unit')->where('id', Ids::toBinary($x))->where('is_active', 1)->whereNull('deleted_at')->exists()));
            if ($activeChildren !== [] && ! $cascade) {
                foreach ($activeChildren as $c) {
                    $blockers[] = ['facilityId' => $c, 'type' => 'active_child', 'count' => 1, 'message' => 'Child facility '.DB::table('facility_unit')->where('id', Ids::toBinary($c))->value('name').' is still active (deactivate it first, or pass cascade=true).'];
                }
            } else {
                $targets = [...$targets, ...$activeChildren];
            }
            foreach ($targets as $t) {
                foreach ($this->usage->forDeactivation($t) as $b) {
                    $blockers[] = ['facilityId' => $t] + $b;
                }
            }
            if ($blockers !== []) {
                $name = $f->name;
                throw ApiProblem::conflict('facility_in_use', "Cannot deactivate {$name} yet: ".implode(' ', array_map(fn ($b) => $b['message'], $blockers)), ['blockers' => $blockers]);
            }
            foreach ($targets as $t) {
                $row = DB::table('facility_unit')->where('id', Ids::toBinary($t))->lockForUpdate()->first();
                $version = (int) $row->row_version + 1;
                DB::table('facility_unit')->where('id', $row->id)->update(['is_active' => 0, 'deactivated_at' => Fmt::now(), 'deactivation_reason' => $reason, 'row_version' => $version]);
                ConfigChange::record('config.facility.deactivate', 'Facility', $t, ['active' => true], ['active' => false, 'reason' => $reason, 'cascadedFrom' => $t === $id ? null : $id],
                    'facilityDetails', ['isActive' => false, 'deactivationReason' => $reason], $version, facilityId: $t, organizationId: Ids::fromBinary($row->organization_id), siteId: Ids::fromBinary($row->site_id));
            }

            return $this->present(DB::table('facility_unit')->where('id', $f->id)->first());
        });
    }

    /** @return array<string, mixed> */
    public function reactivate(string $id, ?int $ifMatch): array
    {
        return DB::transaction(function () use ($id, $ifMatch): array {
            $f = $this->facilities->lock($id);
            Concurrency::assertVersion((int) $f->row_version, $ifMatch, 'facility');
            if ($f->is_active) {
                return $this->present($f);
            }
            if ($f->parent_id !== null && ! DB::table('facility_unit')->where('id', $f->parent_id)->where('is_active', 1)->exists()) {
                throw ApiProblem::conflict('parent_inactive', 'Reactivate the parent facility first.', ['parentId' => Ids::fromBinary($f->parent_id)]);
            }
            $version = (int) $f->row_version + 1;
            DB::table('facility_unit')->where('id', $f->id)->update(['is_active' => 1, 'deactivated_at' => null, 'deactivation_reason' => null, 'row_version' => $version]);
            ConfigChange::record('config.facility.reactivate', 'Facility', $id, ['active' => false], ['active' => true], 'facilityDetails', ['isActive' => true, 'deactivationReason' => null], $version,
                facilityId: $id, organizationId: Ids::fromBinary($f->organization_id), siteId: Ids::fromBinary($f->site_id));

            return $this->present(DB::table('facility_unit')->where('id', $f->id)->first());
        });
    }

    /** Move under another parent (or to the top level with null). Cycle-safe. @return array<string, mixed> */
    public function move(string $id, ?string $parentId, ?int $ifMatch): array
    {
        return DB::transaction(function () use ($id, $parentId, $ifMatch): array {
            // Tree mutex: two concurrent moves (A under B, B under A) must not both pass the cycle check.
            DB::table('site')->where('id', Ids::toBinary((string) Tenant::siteId()))->lockForUpdate()->first();
            $f = $this->facilities->lock($id);
            Concurrency::assertVersion((int) $f->row_version, $ifMatch, 'facility');
            $newParent = null;
            if ($parentId !== null) {
                $parentId = Ids::normalize($parentId);
                $newParent = $this->facilities->lock($parentId);
                if (in_array($parentId, FacilityTree::selfAndDescendants($id), true)) {
                    throw ApiProblem::unprocessable('facility_cycle', 'A facility cannot be moved under itself or one of its own sub-facilities.', ['parentId' => ['That would create a loop.']]);
                }
                if (! $newParent->is_active) {
                    throw ApiProblem::unprocessable('validation_failed', 'The new parent is inactive.', ['parentId' => ['Choose an active parent.']]);
                }
            }
            $oldParent = Fmt::u($f->parent_id);
            if ($oldParent === $parentId) {
                return $this->present($f);
            }
            $version = (int) $f->row_version + 1;
            DB::table('facility_unit')->where('id', $f->id)->update(['parent_id' => $newParent?->id, 'row_version' => $version]);
            ConfigChange::record('config.facility.move', 'Facility', $id, ['parentId' => $oldParent], ['parentId' => $parentId], 'facilityDetails', ['parentId' => $parentId], $version,
                facilityId: $id, organizationId: Ids::fromBinary($f->organization_id), siteId: Ids::fromBinary($f->site_id));

            return $this->present(DB::table('facility_unit')->where('id', $f->id)->first());
        });
    }

    /** Hard removal is only allowed for a facility nothing references (created by mistake). Otherwise: deactivate. */
    public function destroy(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $f = $this->facilities->lock($id);
            $bin = $f->id;
            $refs = [];
            $check = ['order' => 'facility_unit_id', 'tab' => 'facility_unit_id', 'booking' => 'facility_unit_id', 'payment' => 'facility_unit_id', 'cash_session' => 'facility_unit_id',
                'receipt' => 'facility_unit_id', 'stock_location' => 'facility_unit_id', 'stock_movement' => 'facility_unit_id', 'ticket_type' => 'facility_unit_id', 'bookable_resource' => 'facility_unit_id',
                'dining_table' => 'facility_unit_id', 'operating_point' => 'facility_unit_id', 'kds_station' => 'facility_unit_id', 'device' => 'facility_unit_id', 'plan_coverage' => 'facility_unit_id',
                'product_facility' => 'facility_unit_id', 'price' => 'facility_unit_id', 'tablet_checkout' => 'facility_unit_id', 'role_assignment' => 'facility_unit_id', 'facility_unit' => 'parent_id'];
            foreach ($check as $table => $col) {
                if (! Schema::hasColumn($table, $col)) {
                    continue;
                }
                if (DB::table($table)->where($col, $bin)->exists()) {
                    $refs[] = $table;
                }
            }
            if ($refs !== []) {
                throw ApiProblem::conflict('facility_has_history', 'This facility has history ('.implode(', ', $refs).') so it cannot be deleted. Deactivate it instead: it disappears from day-to-day use but its records stay intact.', ['references' => $refs]);
            }
            $snapshot = $this->present($f);
            // nothing references it: capabilities and rules go with it; the row is soft-deleted (deleted_at) so its code stays reserved and sync stays consistent
            $capIds = DB::table('facility_capability')->where('facility_unit_id', $bin)->pluck('id')->all();
            DB::table('operating_rule')->whereIn('facility_capability_id', $capIds)->delete();
            DB::table('facility_capability')->where('facility_unit_id', $bin)->delete();
            $version = (int) $f->row_version + 1;
            DB::table('facility_unit')->where('id', $bin)->update(['deleted_at' => Fmt::now(), 'is_active' => 0, 'row_version' => $version]);
            ConfigChange::record('config.facility.delete', 'Facility', $id, $snapshot, ['deleted' => true], 'facilityDetails', ['isActive' => false, 'deletedAt' => Fmt::ts(Fmt::now())], $version,
                facilityId: $id, organizationId: Ids::fromBinary($f->organization_id), siteId: Ids::fromBinary($f->site_id));
        });
    }
}

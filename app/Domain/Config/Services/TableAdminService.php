<?php

namespace App\Domain\Config\Services;

use App\Domain\Config\Support\ConfigChange;
use App\Support\Api\Concurrency;
use App\Support\Api\Fmt;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Sync\Outbox;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

/** Dining tables: create, bulk create ("T1-T20"), rename/resize/move section, deactivate, merge/unmerge. */
class TableAdminService
{
    private const OPEN_ORDER = ['DRAFT', 'SENT', 'IN_PREPARATION', 'READY', 'SERVED', 'PENDING_APPROVAL'];

    public function __construct(private readonly FacilityLoader $facilities) {}

    /** @return array<string, mixed> */
    public function present(object $r): array
    {
        $children = DB::table('dining_table')->where('merge_parent_id', $r->id)->get(['id', 'seats']);

        return [
            'id' => Ids::fromBinary($r->id), 'facilityId' => Ids::fromBinary($r->facility_unit_id), 'operatingPointId' => Fmt::u($r->operating_point_id),
            'label' => $r->label, 'seats' => (int) $r->seats, 'status' => $r->status, 'active' => (bool) $r->is_active, 'sortOrder' => (int) $r->sort_order,
            'mergedIntoId' => Fmt::u($r->merge_parent_id), 'mergedTableIds' => $children->map(fn ($c) => Ids::fromBinary($c->id))->all(),
            'effectiveSeats' => (int) $r->seats + (int) $children->sum('seats'), 'rowVersion' => (int) $r->row_version,
        ];
    }

    /** @param array<string, mixed> $in label, seats?, operatingPointId?, sortOrder? @return array<string, mixed> */
    public function create(string $facilityId, array $in): array
    {
        return DB::transaction(function () use ($facilityId, $in): array {
            $f = $this->facilityForTables($facilityId);
            $row = $this->insert($f, $in['label'], (int) ($in['seats'] ?? 2), $this->section($f, $in['operatingPointId'] ?? null), (int) ($in['sortOrder'] ?? 0));
            if ($row === null) {
                throw ApiProblem::conflict('table_label_taken', 'This facility already has a table with that label.');
            }
            $view = $this->present($row);
            ConfigChange::record('config.table.create', 'DiningTable', $view['id'], null, $view, 'diningTable', $this->syncView($view, $f), 1, facilityId: $facilityId,
                organizationId: Ids::fromBinary($f->organization_id), siteId: Ids::fromBinary($f->site_id));

            return $view;
        });
    }

    /**
     * @param  array<string, mixed>  $in  prefix+from+to(+padWidth) or labels[]; seats?, operatingPointId?
     * @return array{created: list<array<string, mixed>>, skipped: list<string>}
     */
    public function bulkCreate(string $facilityId, array $in): array
    {
        $labels = $in['labels'] ?? null;
        if ($labels === null) {
            if (! isset($in['prefix'], $in['from'], $in['to'])) {
                throw ApiProblem::unprocessable('validation_failed', 'Give either labels[] or prefix+from+to.', ['labels' => ['Provide labels or prefix, from and to.']]);
            }
            if ($in['to'] < $in['from']) {
                throw ApiProblem::unprocessable('validation_failed', '"to" must not be below "from".', ['to' => ['Must be >= from.']]);
            }
            $labels = [];
            for ($i = (int) $in['from']; $i <= (int) $in['to']; $i++) {
                $labels[] = $in['prefix'].str_pad((string) $i, (int) ($in['padWidth'] ?? 0), '0', STR_PAD_LEFT);
            }
        }
        $labels = array_values(array_unique(array_map('trim', $labels)));
        if (count($labels) > 500 || $labels === []) {
            throw ApiProblem::unprocessable('validation_failed', 'Between 1 and 500 tables per request.', ['labels' => ['Between 1 and 500 labels.']]);
        }
        foreach ($labels as $l) {
            if ($l === '' || mb_strlen($l) > 32) {
                throw ApiProblem::unprocessable('validation_failed', 'Labels must be 1-32 characters.', ['labels' => ["Invalid label '{$l}'."]]);
            }
        }

        return DB::transaction(function () use ($facilityId, $in, $labels): array {
            $f = $this->facilityForTables($facilityId);
            $section = $this->section($f, $in['operatingPointId'] ?? null);
            $created = [];
            $skipped = [];
            $order = (int) DB::table('dining_table')->where('facility_unit_id', $f->id)->max('sort_order');
            foreach ($labels as $l) {
                $row = $this->insert($f, $l, (int) ($in['seats'] ?? 2), $section, ++$order);
                if ($row === null) {
                    $skipped[] = $l;

                    continue;
                }
                $view = $this->present($row);
                Outbox::record('ConfigurationUpdated', 'DiningTable', $view['id'], ['domain' => 'diningTable', 'changes' => $this->syncView($view, $f)], 1,
                    organizationId: Ids::fromBinary($f->organization_id), siteId: Ids::fromBinary($f->site_id), facilityId: $facilityId);
                $created[] = $view;
            }
            if ($created !== []) {
                Audit::record('config.tables.bulk_create', 'Facility', $facilityId, null,
                    ['count' => count($created), 'labels' => array_column($created, 'label'), 'skipped' => $skipped, 'seats' => (int) ($in['seats'] ?? 2)], facilityUnitId: $facilityId);
            }

            return ['created' => $created, 'skipped' => $skipped];
        });
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public function update(string $id, array $in, ?int $ifMatch): array
    {
        return DB::transaction(function () use ($id, $in, $ifMatch): array {
            $t = $this->lock($id);
            Concurrency::assertVersion((int) $t->row_version, $ifMatch, 'table');
            $f = DB::table('facility_unit')->where('id', $t->facility_unit_id)->first();
            $old = $this->present($t);
            $set = [];
            if (array_key_exists('label', $in) && $in['label'] !== $t->label) {
                if (DB::table('dining_table')->where('facility_unit_id', $t->facility_unit_id)->where('label', $in['label'])->where('id', '!=', $t->id)->exists()) {
                    throw ApiProblem::conflict('table_label_taken', 'This facility already has a table with that label.');
                }
                $set['label'] = $in['label'];
            }
            foreach (['seats' => 'seats', 'sortOrder' => 'sort_order'] as $k => $col) {
                if (array_key_exists($k, $in) && (int) $in[$k] !== (int) $t->{$col}) {
                    $set[$col] = (int) $in[$k];
                }
            }
            if (array_key_exists('operatingPointId', $in) && $in['operatingPointId'] !== Fmt::u($t->operating_point_id)) {
                $set['operating_point_id'] = $this->section($f, $in['operatingPointId']);
            }
            if ($set === []) {
                return $old;
            }
            DB::table('dining_table')->where('id', $t->id)->update($set + ['row_version' => $t->row_version + 1]);
            $new = $this->present(DB::table('dining_table')->where('id', $t->id)->first());
            ConfigChange::record('config.table.update', 'DiningTable', $id, $old, $new, 'diningTable', $this->syncView($new, $t), $new['rowVersion'], facilityId: $old['facilityId'],
                organizationId: Ids::fromBinary($t->organization_id), siteId: Ids::fromBinary($t->site_id));

            return $new;
        });
    }

    /** @return array<string, mixed> */
    public function setActive(string $id, bool $active, ?int $ifMatch): array
    {
        return DB::transaction(function () use ($id, $active, $ifMatch): array {
            $t = $this->lock($id);
            Concurrency::assertVersion((int) $t->row_version, $ifMatch, 'table');
            if ((bool) $t->is_active === $active) {
                return $this->present($t);
            }
            if (! $active) {
                $this->assertFree($t, 'deactivate');
                if (DB::table('dining_table')->where('merge_parent_id', $t->id)->exists()) {
                    throw ApiProblem::conflict('table_in_use', 'Unmerge the tables joined to this one before deactivating it.');
                }
            }
            $old = $this->present($t);
            DB::table('dining_table')->where('id', $t->id)->update(['is_active' => $active ? 1 : 0, 'merge_parent_id' => $active ? $t->merge_parent_id : null, 'row_version' => $t->row_version + 1]);
            $new = $this->present(DB::table('dining_table')->where('id', $t->id)->first());
            ConfigChange::record($active ? 'config.table.reactivate' : 'config.table.deactivate', 'DiningTable', $id, $old, $new, 'diningTable', $this->syncView($new, $t), $new['rowVersion'],
                facilityId: $old['facilityId'], organizationId: Ids::fromBinary($t->organization_id), siteId: Ids::fromBinary($t->site_id));

            return $new;
        });
    }

    /** @return array<string, mixed> the target table after joining */
    public function merge(string $id, string $intoId, ?int $ifMatch): array
    {
        return DB::transaction(function () use ($id, $intoId, $ifMatch): array {
            if (! Ids::isUuid($id)) {
                throw ApiProblem::notFound('not_found', 'Table was not found.');
            }
            $id = Ids::normalize($id);
            $intoId = Ids::normalize($intoId);
            if ($id === $intoId) {
                throw ApiProblem::unprocessable('validation_failed', 'A table cannot be merged into itself.', ['intoTableId' => ['Choose a different table.']]);
            }
            [$a, $b] = strcmp($id, $intoId) < 0 ? [$id, $intoId] : [$intoId, $id]; // consistent lock order
            $rows = [$a => $this->lock($a), $b => $this->lock($b)];
            $src = $rows[$id];
            $dst = $rows[$intoId];
            Concurrency::assertVersion((int) $src->row_version, $ifMatch, 'table');
            if ($src->facility_unit_id !== $dst->facility_unit_id) {
                throw ApiProblem::unprocessable('validation_failed', 'Tables can only be merged within one facility.', ['intoTableId' => ['Different facility.']]);
            }
            foreach ([$src, $dst] as $t) {
                if (! $t->is_active) {
                    throw ApiProblem::unprocessable('validation_failed', "Table {$t->label} is inactive.", ['intoTableId' => ['Both tables must be active.']]);
                }
                $this->assertFree($t, 'merge');
            }
            if ($src->merge_parent_id !== null || $dst->merge_parent_id !== null || DB::table('dining_table')->where('merge_parent_id', $src->id)->exists()) {
                throw ApiProblem::conflict('table_already_merged', 'One of these tables is already part of a merge. Unmerge it first.');
            }
            DB::table('dining_table')->where('id', $src->id)->update(['merge_parent_id' => $dst->id, 'row_version' => $src->row_version + 1]);
            DB::table('dining_table')->where('id', $dst->id)->update(['row_version' => $dst->row_version + 1]);
            $newSrc = $this->present(DB::table('dining_table')->where('id', $src->id)->first());
            $newDst = $this->present(DB::table('dining_table')->where('id', $dst->id)->first());
            $ctx = ['facilityId' => $newDst['facilityId'], 'organizationId' => Ids::fromBinary($dst->organization_id), 'siteId' => Ids::fromBinary($dst->site_id)];
            ConfigChange::record('config.table.merge', 'DiningTable', $newSrc['id'], ['mergedIntoId' => null], ['mergedIntoId' => $newDst['id'], 'label' => $src->label, 'intoLabel' => $dst->label],
                'diningTable', $this->syncView($newSrc, $src), $newSrc['rowVersion'], ...$ctx);
            Outbox::record('ConfigurationUpdated', 'DiningTable', $newDst['id'], ['domain' => 'diningTable', 'changes' => $this->syncView($newDst, $dst)], $newDst['rowVersion'],
                organizationId: $ctx['organizationId'], siteId: $ctx['siteId'], facilityId: $ctx['facilityId']);

            return $newDst;
        });
    }

    /** @return array<string, mixed> the table that was split off */
    public function unmerge(string $id, ?int $ifMatch): array
    {
        return DB::transaction(function () use ($id, $ifMatch): array {
            $t = $this->lock($id);
            Concurrency::assertVersion((int) $t->row_version, $ifMatch, 'table');
            if ($t->merge_parent_id === null) {
                return $this->present($t);
            }
            $parent = DB::table('dining_table')->where('id', $t->merge_parent_id)->lockForUpdate()->first();
            $this->assertFree($t, 'unmerge');
            if ($parent !== null) {
                $this->assertFree($parent, 'unmerge');
                DB::table('dining_table')->where('id', $parent->id)->update(['row_version' => $parent->row_version + 1]);
            }
            DB::table('dining_table')->where('id', $t->id)->update(['merge_parent_id' => null, 'row_version' => $t->row_version + 1]);
            $new = $this->present(DB::table('dining_table')->where('id', $t->id)->first());
            ConfigChange::record('config.table.unmerge', 'DiningTable', $id, ['mergedIntoId' => Fmt::u($t->merge_parent_id)], ['mergedIntoId' => null], 'diningTable', $this->syncView($new, $t), $new['rowVersion'],
                facilityId: $new['facilityId'], organizationId: Ids::fromBinary($t->organization_id), siteId: Ids::fromBinary($t->site_id));

            return $new;
        });
    }

    /** Flat sync payload (runtime `status` is deliberately not synced). @param array<string, mixed> $view @return array<string, mixed> */
    private function syncView(array $view, object $r): array
    {
        unset($view['status']);

        return $view + ['organizationId' => Ids::fromBinary($r->organization_id), 'siteId' => Ids::fromBinary($r->site_id)];
    }

    public function lock(string $id): object
    {
        if (! Ids::isUuid($id)) {
            throw ApiProblem::notFound('not_found', 'Table was not found.');
        }

        return DB::table('dining_table')->where('id', Ids::toBinary($id))->where('site_id', Ids::toBinary((string) Tenant::siteId()))->lockForUpdate()->first()
            ?? throw ApiProblem::notFound('not_found', 'Table was not found.');
    }

    private function facilityForTables(string $facilityId): object
    {
        $f = $this->facilities->lock($facilityId);
        if (! DB::table('facility_capability')->where('facility_unit_id', $f->id)->where('capability_code', 'TABLE_SERVICE')->where('is_enabled', 1)->exists()) {
            throw ApiProblem::unprocessable('capability_disabled', 'Turn on the Table service capability for this facility before adding tables.', ['facilityId' => ['Capability TABLE_SERVICE is not enabled.']]);
        }
        if (! $f->is_active) {
            throw ApiProblem::unprocessable('validation_failed', 'The facility is inactive.', ['facilityId' => ['Reactivate the facility first.']]);
        }

        return $f;
    }

    private function section(object $f, ?string $operatingPointId): ?string
    {
        if ($operatingPointId === null) {
            return null;
        }
        $op = Ids::isUuid($operatingPointId) ? DB::table('operating_point')->where('id', Ids::toBinary($operatingPointId))->first() : null;
        if ($op === null || $op->facility_unit_id !== $f->id || $op->kind !== 'TABLE_AREA' || ! $op->is_active) {
            throw ApiProblem::unprocessable('validation_failed', 'The section must be an active TABLE_AREA operating point of this facility.', ['operatingPointId' => ['Choose a table area of this facility.']]);
        }

        return $op->id;
    }

    /** @return object|null the inserted row, null when the label already exists */
    private function insert(object $f, string $label, int $seats, ?string $section, int $sort): ?object
    {
        if (DB::table('dining_table')->where('facility_unit_id', $f->id)->where('label', $label)->exists()) {
            return null;
        }
        $id = Ids::toBinary(Ids::uuid7());
        DB::table('dining_table')->insert([
            'id' => $id, 'organization_id' => $f->organization_id, 'site_id' => $f->site_id, 'facility_unit_id' => $f->id, 'operating_point_id' => $section,
            'label' => $label, 'seats' => max(1, $seats), 'status' => 'FREE', 'is_active' => 1, 'sort_order' => $sort, 'row_version' => 1,
        ]);

        return DB::table('dining_table')->where('id', $id)->first();
    }

    private function assertFree(object $t, string $verb): void
    {
        $blockers = [];
        if ($t->status === 'OCCUPIED') {
            $blockers[] = ['type' => 'occupied', 'count' => 1, 'message' => "Table {$t->label} is occupied."];
        }
        if (($n = DB::table('order')->where('dining_table_id', $t->id)->whereIn('status', self::OPEN_ORDER)->count()) > 0) {
            $blockers[] = ['type' => 'open_orders', 'count' => $n, 'message' => "Table {$t->label} has {$n} open order(s)."];
        }
        if (($n = DB::table('tab')->where('dining_table_id', $t->id)->whereIn('status', ['OPEN', 'SETTLING'])->count()) > 0) {
            $blockers[] = ['type' => 'open_tabs', 'count' => $n, 'message' => "Table {$t->label} has {$n} open tab(s)."];
        }
        if ($blockers !== []) {
            throw ApiProblem::conflict('table_in_use', "Cannot {$verb} yet: ".implode(' ', array_column($blockers, 'message')), ['blockers' => $blockers]);
        }
    }
}

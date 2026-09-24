<?php

namespace App\Domain\Config\Services;

use App\Domain\Booking\Services\BookingRules;
use App\Domain\Config\Support\ConfigChange;
use App\Domain\Config\Support\RuleDefinitions;
use App\Domain\Orders\Services\OperatingRules;
use App\Domain\Payments\Support\FacilityRules;
use App\Support\Api\Concurrency;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * Typed operating rules of a facility (GET/PUT /facilities/{id}/operating-rules). Values are validated against RuleDefinitions,
 * stored where the runtime reads them (operating_rule / booking_rule / bookable_resource), audited with old/new and outboxed as a
 * version-checked `ConfigurationUpdated {domain: facilityRules}`. The aggregate version is facility_unit.row_version.
 */
class OperatingRuleService
{
    public function __construct(private readonly FacilityLoader $facilities) {}

    /** Catalogue for GET /organization/rule-definitions. @return list<array<string, mixed>> */
    public function catalogue(): array
    {
        return array_values(array_map(fn (array $d) => $this->present($d), RuleDefinitions::all()));
    }

    /** @return array<string, mixed> */
    public function present(array $d): array
    {
        return [
            'key' => $d['key'], 'label' => $d['label'], 'description' => $d['description'], 'group' => $d['group'], 'type' => $d['type'],
            'capability' => $d['capability'], 'capabilities' => $d['capabilities'], 'default' => RuleDefinitions::defaultOf($d), 'unit' => $d['unit'],
            'allowed' => $d['allowed'], 'min' => $d['min'], 'max' => $d['max'], 'integer' => (bool) $d['integer'],
            'dangerLevel' => $d['danger'], 'enforcement' => $d['enforcement'], 'name' => RuleDefinitions::camel($d['key']),
        ];
    }

    /**
     * Effective values for the rules that apply to the facility's enabled capabilities.
     *
     * @return array{facilityId: string, version: int, capabilities: list<string>, values: array<string, mixed>, items: list<array<string, mixed>>, notApplicable: list<string>}
     */
    public function effective(string $facilityId): array
    {
        $bin = Ids::toBinary($facilityId);
        $facility = DB::table('facility_unit')->where('id', $bin)->first(['row_version']);
        $enabled = DB::table('facility_capability')->where('facility_unit_id', $bin)->where('is_enabled', 1)->pluck('capability_code')->all();
        $stored = $this->storedValues($bin, true);

        $values = [];
        $items = [];
        $na = [];
        foreach (RuleDefinitions::all() as $key => $d) {
            if (array_intersect($d['capabilities'], $enabled) === []) {
                $na[] = $key;

                continue;
            }
            $has = array_key_exists($key, $stored);
            $value = $has ? $stored[$key] : RuleDefinitions::defaultOf($d);
            $values[$key] = $value;
            $items[] = ['key' => $key, 'value' => $value, 'isDefault' => ! $has, 'label' => $d['label'], 'group' => $d['group'], 'dangerLevel' => $d['danger']];
        }

        return ['facilityId' => $facilityId, 'version' => (int) $facility->row_version, 'capabilities' => $enabled, 'values' => $values, 'items' => $items, 'notApplicable' => $na];
    }

    /**
     * @param  array<string, mixed>  $rules  key => value | null (null resets to the default)
     * @return array{facilityId: string, version: int, changed: list<string>}
     */
    public function apply(string $facilityId, array $rules, bool $confirm, ?int $ifMatch): array
    {
        if ($rules === []) {
            throw ApiProblem::unprocessable('validation_failed', 'Nothing to change.', ['rules' => ['Provide at least one rule.']]);
        }

        return DB::transaction(function () use ($facilityId, $rules, $confirm, $ifMatch): array {
            $f = $this->facilities->lock($facilityId);
            Concurrency::assertVersion((int) $f->row_version, $ifMatch, 'facility configuration');
            $bin = $f->id;
            $enabled = DB::table('facility_capability')->where('facility_unit_id', $bin)->where('is_enabled', 1)->pluck('capability_code')->all();

            [$prepared, $errors] = $this->prepare($rules, $enabled, Ids::fromBinary($bin));
            if ($errors !== []) {
                throw ApiProblem::unprocessable('validation_failed', 'One or more rules are invalid.', $errors);
            }

            $oldAll = $this->storedValues($bin, true);
            $old = [];
            $new = [];
            $changes = [];
            $dangerous = [];
            foreach ($prepared as $key => $value) {
                $d = RuleDefinitions::get($key);
                $before = array_key_exists($key, $oldAll) ? $oldAll[$key] : RuleDefinitions::defaultOf($d);
                $after = $value === null ? RuleDefinitions::defaultOf($d) : $value;
                if ($before === $after && (array_key_exists($key, $oldAll) === ($value !== null))) {
                    continue; // no-op
                }
                $old[$key] = $before;
                $new[$key] = $after;
                $changes[$key] = $value;
                if ($d['danger'] === 'high') {
                    $dangerous[] = $key;
                }
            }
            if ($changes === []) {
                return ['facilityId' => $facilityId, 'version' => (int) $f->row_version, 'changed' => []];
            }
            if ($dangerous !== [] && ! $confirm) {
                throw ApiProblem::unprocessable('danger_confirmation_required', 'These rules are high-risk (money, stock or security). Send "confirm": true to apply them.',
                    collect($dangerous)->mapWithKeys(fn ($k) => ["rules.{$k}" => ['High-risk rule: resend with confirm=true.']])->all());
            }

            foreach ($changes as $key => $value) {
                $this->write($bin, $f, $key, $value, $enabled);
            }
            $version = (int) $f->row_version + 1;
            DB::table('facility_unit')->where('id', $bin)->update(['row_version' => $version]);
            ConfigChange::record('config.rules.update', 'Facility', $facilityId, $old, $new, 'facilityRules', ['rules' => $changes], $version,
                facilityId: $facilityId, organizationId: Ids::fromBinary($f->organization_id), siteId: Ids::fromBinary($f->site_id));
            $this->flushRuntimeCaches();

            return ['facilityId' => $facilityId, 'version' => $version, 'changed' => array_keys($changes)];
        });
    }

    /**
     * Used at facility creation (no version bump, no audit of its own: the create audit row carries the snapshot).
     *
     * @param  array<string, mixed>  $rules  key => api value (already validated)
     * @param  list<string>  $enabled
     */
    public function writeInitial(object $facilityRow, array $rules, array $enabled): void
    {
        foreach ($rules as $key => $value) {
            $this->write($facilityRow->id, $facilityRow, $key, $value, $enabled);
        }
    }

    /**
     * Validate a rule map for a facility that has (or will have) the given capabilities.
     *
     * @param  array<string, mixed>  $rules
     * @param  list<string>  $enabled
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>} normalised values (null = reset) and field errors
     */
    public function prepare(array $rules, array $enabled, ?string $facilityId): array
    {
        $out = [];
        $errors = [];
        foreach ($rules as $key => $value) {
            $d = RuleDefinitions::get((string) $key);
            if ($d === null) {
                $errors["rules.{$key}"] = ['Unknown rule. See GET /organization/rule-definitions.'];

                continue;
            }
            if (array_intersect($d['capabilities'], $enabled) === []) {
                $errors["rules.{$key}"] = ['Needs capability '.implode(' or ', $d['capabilities']).' to be enabled at this facility.'];

                continue;
            }
            if ($value === null) {
                $out[$key] = null;

                continue;
            }
            [$norm, $err] = RuleDefinitions::normalize($d, $value);
            if ($err !== null) {
                $errors["rules.{$key}"] = [$err];

                continue;
            }
            if ($d['type'] === 'facility') {
                $target = DB::table('facility_unit')->where('id', Ids::toBinary($norm))->whereNull('deleted_at')->first(['id', 'is_active', 'organization_id']);
                if ($target === null) {
                    $errors["rules.{$key}"] = ['Unknown facility.'];

                    continue;
                }
                if ($key === 'payment_facility_unit_id') {
                    $hasPay = DB::table('facility_capability')->where('facility_unit_id', $target->id)->where('capability_code', 'PAYMENT_ACCEPTANCE')->where('is_enabled', 1)->exists();
                    if (! $hasPay || ! $target->is_active) {
                        $errors["rules.{$key}"] = ['That facility must be active and accept payments (PAYMENT_ACCEPTANCE).'];

                        continue;
                    }
                }
            }
            $out[$key] = $norm;
        }

        return [$out, $errors];
    }

    /** Stored (non-default) values by key, honouring which store the runtime reads. @return array<string, mixed> */
    private function storedValues(string $facilityBin, bool $onlyEnabledCaps): array
    {
        $q = DB::table('operating_rule as r')->join('facility_capability as c', 'c.id', '=', 'r.facility_capability_id')->where('c.facility_unit_id', $facilityBin);
        if ($onlyEnabledCaps) {
            $q->where('c.is_enabled', 1);
        }
        $out = [];
        foreach ($q->orderBy('r.updated_at')->get(['r.rule_key', 'r.rule_value']) as $r) {
            if (($d = RuleDefinitions::get($r->rule_key)) !== null) {
                $out[$r->rule_key] = RuleDefinitions::fromStored($d, $r->rule_value);
            }
        }
        // booking_rule store wins for the keys the Booking module reads from it
        $b = DB::table('booking_rule')->where('facility_unit_id', $facilityBin)->first();
        if ($b !== null) {
            $enabled = DB::table('facility_capability')->where('facility_unit_id', $facilityBin)->where('is_enabled', 1)->pluck('capability_code')->all();
            foreach (RuleDefinitions::all() as $key => $d) {
                if ($d['store'] === 'booking_rule' && isset($b->{$key}) && array_intersect($d['capabilities'], $enabled) !== []) {
                    $v = $b->{$key};
                    $out[$key] = $d['type'] === 'number' && ! ($d['integer'] ?? false) ? (float) $v : (int) $v;
                }
            }
        }

        return $out;
    }

    /** @param  list<string>  $enabled */
    private function write(string $bin, object $f, string $key, mixed $value, array $enabled): void
    {
        $d = RuleDefinitions::get($key);
        $store = $d['store'];
        if ($store === 'booking_rule') {
            $this->writeBookingRule($bin, $f, $key, $value);
            if (! $d['mirror']) {
                return;
            }
        }
        if ($store === 'resources' && $value !== null) {
            $this->writeResources($bin, $key, $value);
        }

        // operating_rule: update in place (keeps the capability the row was seeded under), else attach to the first applicable enabled capability.
        $existing = DB::table('operating_rule as r')->join('facility_capability as c', 'c.id', '=', 'r.facility_capability_id')
            ->where('c.facility_unit_id', $bin)->where('r.rule_key', $key)->get(['r.id']);
        if ($value === null) {
            foreach ($existing as $row) {
                DB::table('operating_rule')->where('id', $row->id)->delete();
            }

            return;
        }
        $stored = RuleDefinitions::toStored($d, $value);
        if ($existing->isNotEmpty()) {
            DB::table('operating_rule')->whereIn('id', $existing->pluck('id')->all())->update(['rule_value' => $stored]);

            return;
        }
        $capCode = collect($d['capabilities'])->first(fn ($c) => in_array($c, $enabled, true)) ?? $d['capability'];
        $cap = DB::table('facility_capability')->where('facility_unit_id', $bin)->where('capability_code', $capCode)->first(['id']);
        if ($cap === null) {
            $capId = Ids::toBinary(Ids::uuid7());
            DB::table('facility_capability')->insert(['id' => $capId, 'facility_unit_id' => $bin, 'capability_code' => $capCode, 'is_enabled' => 0]);
        } else {
            $capId = $cap->id;
        }
        DB::table('operating_rule')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'facility_capability_id' => $capId, 'rule_key' => $key, 'rule_value' => $stored]);
    }

    private function writeBookingRule(string $bin, object $f, string $key, mixed $value): void
    {
        $row = DB::table('booking_rule')->where('facility_unit_id', $bin)->first(['id']);
        if ($row === null) {
            if ($value === null) {
                return;
            }
            DB::table('booking_rule')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'organization_id' => $f->organization_id, 'facility_unit_id' => $bin, $key => $value]);

            return;
        }
        DB::table('booking_rule')->where('id', $row->id)->update([$key => $value]);
    }

    private function writeResources(string $bin, string $key, mixed $value): void
    {
        $base = DB::table('bookable_resource')->where('facility_unit_id', $bin)->whereNull('deleted_at');
        match ($key) {
            'booking_offline_strategy' => (clone $base)->update(['offline_strategy' => $value]),
            'booking_online_stale_after_seconds' => (clone $base)->update(['online_stale_after_seconds' => $value]),
            'booking_local_reserve_percent' => (clone $base)->update(['local_reserve_units' => DB::raw('FLOOR(capacity * '.((int) $value).' / 100)')]),
            default => null,
        };
    }

    private function flushRuntimeCaches(): void
    {
        foreach ([OperatingRules::class, FacilityRules::class, BookingRules::class] as $cls) {
            if (app()->resolved($cls)) {
                $o = app($cls);
                method_exists($o, 'forget') ? $o->forget() : (method_exists($o, 'flush') ? $o->flush() : null);
            }
        }
    }
}

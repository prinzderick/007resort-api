<?php

namespace App\Domain\Config\Services;

use App\Domain\Config\Support\CapabilityCatalogue;
use App\Domain\Config\Support\ConfigChange;
use App\Support\Api\Concurrency;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/** PUT /facilities/{id}/capabilities: replace the enabled set, validating dependencies and refusing removals that would strand live work. */
class CapabilityAdminService
{
    public function __construct(private readonly FacilityLoader $facilities, private readonly FacilityUsage $usage) {}

    /**
     * Validate a target capability set. Dependencies are checked for capabilities that are newly enabled and for remaining
     * capabilities that need one being disabled.
     *
     * @param  list<string>  $current
     * @param  list<string>  $target
     * @return array<string, list<string>> field errors
     */
    public function dependencyErrors(array $current, array $target): array
    {
        $errors = [];
        foreach ($target as $code) {
            if (! CapabilityCatalogue::exists($code)) {
                $errors["capabilities.{$code}"] = ['Unknown capability. See GET /organization/capability-types.'];
            }
        }
        if ($errors !== []) {
            return $errors;
        }
        $added = array_diff($target, $current);
        $removed = array_diff($current, $target);
        foreach ($added as $code) {
            $missing = array_values(array_diff(CapabilityCatalogue::requires($code), $target));
            if ($missing !== []) {
                $errors["capabilities.{$code}"] = ['Requires '.implode(' and ', $missing).' to be enabled first.'];
            }
        }
        foreach ($target as $code) {
            if (in_array($code, $added, true)) {
                continue;
            }
            $gone = array_values(array_intersect(CapabilityCatalogue::requires($code), $removed));
            if ($gone !== []) {
                foreach ($gone as $g) {
                    $errors["capabilities.{$g}"][] = "{$code} requires {$g}; turn {$code} off first.";
                }
            }
        }

        return $errors;
    }

    /** @param list<string> $codes @return array{facilityId: string, version: int, capabilities: list<string>, changed: bool} */
    public function set(string $facilityId, array $codes, ?int $ifMatch): array
    {
        $codes = array_values(array_unique(array_map('strval', $codes)));
        sort($codes);

        return DB::transaction(function () use ($facilityId, $codes, $ifMatch): array {
            $f = $this->facilities->lock($facilityId);
            Concurrency::assertVersion((int) $f->row_version, $ifMatch, 'facility configuration');
            $bin = $f->id;
            $current = DB::table('facility_capability')->where('facility_unit_id', $bin)->where('is_enabled', 1)->orderBy('capability_code')->pluck('capability_code')->all();

            $errors = $this->dependencyErrors($current, $codes);
            if ($errors !== []) {
                throw ApiProblem::unprocessable('capability_dependency', 'Some capabilities need others to be enabled first (or others still need them).', $errors);
            }
            $added = array_values(array_diff($codes, $current));
            $removed = array_values(array_diff($current, $codes));
            if ($added === [] && $removed === []) {
                return ['facilityId' => $facilityId, 'version' => (int) $f->row_version, 'capabilities' => $current, 'changed' => false];
            }

            $blockers = [];
            foreach ($removed as $code) {
                foreach ($this->usage->forCapability($facilityId, $code) as $b) {
                    $blockers[] = ['capability' => $code] + $b;
                }
            }
            if ($blockers !== []) {
                $why = implode(' ', array_map(fn ($b) => "{$b['capability']}: {$b['message']}", $blockers));
                throw ApiProblem::conflict('capability_in_use', 'Cannot turn these capabilities off yet. '.$why, ['blockers' => $blockers]);
            }

            foreach ($added as $code) {
                $row = DB::table('facility_capability')->where('facility_unit_id', $bin)->where('capability_code', $code)->first(['id']);
                $row === null
                    ? DB::table('facility_capability')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'facility_unit_id' => $bin, 'capability_code' => $code, 'is_enabled' => 1])
                    : DB::table('facility_capability')->where('id', $row->id)->update(['is_enabled' => 1]);
            }
            if ($removed !== []) {
                DB::table('facility_capability')->where('facility_unit_id', $bin)->whereIn('capability_code', $removed)->update(['is_enabled' => 0]);
            }
            $version = (int) $f->row_version + 1;
            DB::table('facility_unit')->where('id', $bin)->update(['row_version' => $version]);
            ConfigChange::record('config.capabilities.update', 'Facility', $facilityId, ['capabilities' => $current], ['capabilities' => $codes, 'enabled' => $added, 'disabled' => $removed],
                'facilityCapabilities', ['capabilities' => $codes], $version, facilityId: $facilityId, organizationId: Ids::fromBinary($f->organization_id), siteId: Ids::fromBinary($f->site_id));

            return ['facilityId' => $facilityId, 'version' => $version, 'capabilities' => $codes, 'changed' => true];
        });
    }
}

<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Support\Fmt;
use App\Domain\Payments\Support\Tenantless;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Sync\Outbox;
use Illuminate\Support\Facades\DB;

/** `payment_terminal` registry (configuration: audited + outbox-synced, never a ledger). */
class PaymentTerminalService
{
    /** @return array<string, mixed> */
    public function present(object $t): array
    {
        return [
            'id' => Ids::fromBinary($t->id), 'facilityId' => Ids::fromBinary($t->facility_unit_id), 'provider' => $t->provider, 'label' => $t->label, 'serial' => $t->serial,
            'status' => $t->status, 'assignedDeviceId' => Fmt::uuid($t->assigned_device_id), 'assignedStaffId' => Fmt::uuid($t->assigned_staff_id), 'rowVersion' => (int) $t->row_version,
        ];
    }

    /** @return array<string, mixed> */
    public function create(array $in): array
    {
        $facility = Ids::normalize($in['facilityId']);
        $f = DB::table('facility_unit')->where('id', Ids::toBinary($facility))->whereNull('deleted_at')->first() ?? throw ApiProblem::unprocessable('validation_failed', 'Unknown facility.', ['facilityId' => ['Unknown facility.']]);
        $id = $in['id'] ?? Ids::uuid7();
        $this->assertAssignees($in);

        return DB::transaction(function () use ($in, $facility, $f, $id) {
            if (DB::table('payment_terminal')->where('id', Ids::toBinary($id))->exists()) {
                throw ApiProblem::conflict('concurrency_conflict', 'A terminal with this id already exists.');
            }
            DB::table('payment_terminal')->insert([
                'id' => Ids::toBinary($id), 'organization_id' => $f->organization_id, 'site_id' => $f->site_id, 'facility_unit_id' => $f->id,
                'provider' => $in['provider'] ?? 'MANUAL_BANK', 'label' => $in['label'], 'serial' => $in['serial'] ?? null,
                'assigned_device_id' => Fmt::bin($in['assignedDeviceId'] ?? null), 'assigned_staff_id' => Fmt::bin($in['assignedStaffId'] ?? null),
            ]);
            $t = DB::table('payment_terminal')->where('id', Ids::toBinary($id))->first();
            $this->record('payment_terminal.create', $t, null);

            return $this->present($t);
        });
    }

    /** @return array<string, mixed> */
    public function update(string $id, array $in): array
    {
        $this->assertAssignees($in);

        return DB::transaction(function () use ($id, $in) {
            $t = DB::table('payment_terminal')->where('id', Ids::toBinary($id))->lockForUpdate()->first() ?? throw ApiProblem::notFound('not_found', 'Terminal not found.');
            if ($t->status === 'RETIRED' && ($in['status'] ?? 'RETIRED') !== 'RETIRED') {
                throw ApiProblem::conflict('terminal_retired', 'A retired terminal cannot be reactivated; register a new one.');
            }
            $upd = [];
            foreach (['label' => 'label', 'serial' => 'serial', 'status' => 'status'] as $k => $col) {
                if (array_key_exists($k, $in)) {
                    $upd[$col] = $in[$k];
                }
            }
            foreach (['assignedDeviceId' => 'assigned_device_id', 'assignedStaffId' => 'assigned_staff_id'] as $k => $col) {
                if (array_key_exists($k, $in)) {
                    $upd[$col] = Fmt::bin($in[$k]);
                }
            }
            if ($upd !== []) {
                DB::table('payment_terminal')->where('id', $t->id)->update($upd + ['row_version' => $t->row_version + 1]);
            }
            $new = DB::table('payment_terminal')->where('id', $t->id)->first();
            $this->record('payment_terminal.update', $new, $t);

            return $this->present($new);
        });
    }

    private function record(string $action, object $new, ?object $old): void
    {
        $facility = Ids::fromBinary($new->facility_unit_id);
        ['org' => $org, 'site' => $site] = Tenantless::facility($facility);
        Audit::record($action, 'PaymentTerminal', Ids::fromBinary($new->id), old: $old ? $this->present($old) : null, new: $this->present($new), organizationId: $org, siteId: $site, facilityUnitId: $facility);
        Outbox::record('PaymentTerminalChanged', 'PaymentTerminal', Ids::fromBinary($new->id), $this->present($new), entityVersion: (int) $new->row_version, organizationId: $org, siteId: $site, facilityId: $facility);
    }

    private function assertAssignees(array $in): void
    {
        if (! empty($in['assignedStaffId']) && ! DB::table('staff')->where('id', Ids::toBinary($in['assignedStaffId']))->exists()) {
            throw ApiProblem::unprocessable('validation_failed', 'Unknown staff member.', ['assignedStaffId' => ['Unknown staff member.']]);
        }
        if (! empty($in['assignedDeviceId']) && ! DB::table('device')->where('id', Ids::toBinary($in['assignedDeviceId']))->exists()) {
            throw ApiProblem::unprocessable('validation_failed', 'Unknown device.', ['assignedDeviceId' => ['Unknown device.']]);
        }
    }
}

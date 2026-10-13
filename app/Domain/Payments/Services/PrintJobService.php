<?php

namespace App\Domain\Payments\Services;

use App\Support\Api\Authz;
use App\Support\Api\Fmt;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * "Send this receipt to the printer": a tablet (or anyone with `receipt.print`) queues a job; a POS terminal or the admin portal
 * at that facility prints it (`receipt.print` or `receipt.reprint`) and marks it printed. The receipt itself is never altered.
 */
final class PrintJobService
{
    public function __construct(private readonly ReceiptService $receipts) {}

    /** @return array<string, mixed> */
    public function request(string $receiptId, int $copies): array
    {
        $receipt = $this->receipts->find(Ids::normalize($receiptId)) ?? throw ApiProblem::notFound('not_found', 'Receipt not found.');
        $facilityId = Ids::fromBinary($receipt->facility_unit_id);
        Authz::require('receipt.print', $facilityId);
        $me = Authz::staffId();

        return DB::transaction(function () use ($receipt, $facilityId, $copies, $me) {
            // pressing the button twice must not print twice: reuse a job queued in the last minute for the same receipt
            $existing = DB::table('print_job')->where('receipt_id', $receipt->id)->where('status', 'QUEUED')
                ->where('created_at', '>=', now('UTC')->subMinute()->format('Y-m-d H:i:s.u'))->orderByDesc('id')->first();
            if ($existing !== null) {
                return $this->present($existing);
            }
            $id = Ids::uuid7();
            DB::table('print_job')->insert([
                'id' => Ids::toBinary($id), 'organization_id' => $receipt->organization_id, 'site_id' => $receipt->site_id,
                'facility_unit_id' => $receipt->facility_unit_id, 'receipt_id' => $receipt->id, 'copies' => max(1, min(5, $copies)),
                'requested_by_staff_id' => Ids::toBinary($me), 'requested_device_id' => Fmt::b(RequestContext::deviceId()),
            ]);
            Audit::record('receipt.print.request', 'PrintJob', $id, new: ['receiptId' => Ids::fromBinary($receipt->id), 'number' => $receipt->number, 'copies' => $copies], facilityUnitId: $facilityId);

            return $this->present(DB::table('print_job')->where('id', Ids::toBinary($id))->first());
        });
    }

    /** @return list<array<string, mixed>> */
    public function queue(string $facilityId, ?string $status): array
    {
        $facilityId = Ids::normalize($facilityId);
        if (! Authz::can('receipt.print', $facilityId) && ! Authz::can('receipt.reprint', $facilityId)) {
            throw \App\Support\Http\ApiProblem::permissionDenied('receipt.print');
        }
        $q = DB::table('print_job')->where('facility_unit_id', Ids::toBinary($facilityId));
        $q->whereIn('status', array_map('strtoupper', explode(',', $status ?: 'QUEUED')));

        return $q->orderBy('id')->limit(100)->get()->map(fn ($j) => $this->present($j, true))->all();
    }

    /** @return array<string, mixed> one job with its receipt (for the terminal / admin print view) */
    public function show(string $jobId): array
    {
        $j = DB::table('print_job')->where('id', Ids::toBinary(Ids::normalize($jobId)))->first();
        if ($j === null || Ids::fromBinary($j->organization_id) !== Tenant::organizationId()) {
            throw ApiProblem::notFound('not_found', 'Print job not found.');
        }
        $facilityId = Ids::fromBinary($j->facility_unit_id);
        if (! Authz::can('receipt.print', $facilityId) && ! Authz::can('receipt.reprint', $facilityId)) {
            throw ApiProblem::permissionDenied('receipt.print');
        }

        return $this->present($j, true);
    }

    /** @return array<string, mixed> */
    public function markPrinted(string $jobId): array
    {
        return $this->finish($jobId, 'PRINTED', null);
    }

    /** @return array<string, mixed> */
    public function markFailed(string $jobId, string $reason): array
    {
        return $this->finish($jobId, 'FAILED', $reason);
    }

    /** @return array<string, mixed> */
    public function cancel(string $jobId): array
    {
        $me = Authz::staffId();

        return DB::transaction(function () use ($jobId, $me) {
            $j = $this->lock($jobId);
            $facilityId = Ids::fromBinary($j->facility_unit_id);
            if (Ids::fromBinary($j->requested_by_staff_id) !== $me && ! Authz::can('receipt.reprint', $facilityId)) {
                throw ApiProblem::permissionDenied('receipt.reprint');
            }
            $this->assertQueued($j);
            DB::table('print_job')->where('id', $j->id)->update(['status' => 'CANCELLED', 'row_version' => $j->row_version + 1]);
            Audit::record('receipt.print.cancel', 'PrintJob', Ids::fromBinary($j->id), old: ['status' => 'QUEUED'], new: ['status' => 'CANCELLED'], facilityUnitId: $facilityId);

            return $this->present(DB::table('print_job')->where('id', $j->id)->first());
        });
    }

    /** @return array<string, mixed> */
    private function finish(string $jobId, string $status, ?string $reason): array
    {
        $me = Authz::staffId();

        return DB::transaction(function () use ($jobId, $status, $reason, $me) {
            $j = $this->lock($jobId);
            $facilityId = Ids::fromBinary($j->facility_unit_id);
            if (! Authz::can('receipt.print', $facilityId) && ! Authz::can('receipt.reprint', $facilityId)) {
                throw ApiProblem::permissionDenied('receipt.print');
            }
            if ($j->status === $status) { // the same terminal confirming twice is not an error
                return $this->present($j);
            }
            $this->assertQueued($j);
            DB::table('print_job')->where('id', $j->id)->update([
                'status' => $status, 'printed_by_staff_id' => Ids::toBinary($me), 'printed_device_id' => Fmt::b(RequestContext::deviceId()),
                'printed_at' => Fmt::now(), 'failure_reason' => $reason, 'row_version' => $j->row_version + 1,
            ]);
            Audit::record('receipt.print.'.strtolower($status), 'PrintJob', Ids::fromBinary($j->id), old: ['status' => 'QUEUED'], new: ['status' => $status, 'reason' => $reason], facilityUnitId: $facilityId);

            return $this->present(DB::table('print_job')->where('id', $j->id)->first());
        });
    }

    private function lock(string $jobId): object
    {
        $j = DB::table('print_job')->where('id', Ids::toBinary(Ids::normalize($jobId)))->lockForUpdate()->first();
        if ($j === null || Ids::fromBinary($j->organization_id) !== Tenant::organizationId()) {
            throw ApiProblem::notFound('not_found', 'Print job not found.');
        }

        return $j;
    }

    private function assertQueued(object $j): void
    {
        if ($j->status !== 'QUEUED') {
            throw ApiProblem::conflict('print_job_not_queued', "This print job is already {$j->status}.", ['status' => $j->status]);
        }
    }

    /** @return array<string, mixed> */
    private function present(object $j, bool $withReceipt = false): array
    {
        $receipt = $this->receipts->find(Ids::fromBinary($j->receipt_id));
        $out = [
            'id' => Ids::fromBinary($j->id),
            'facilityId' => Ids::fromBinary($j->facility_unit_id),
            'receiptId' => Ids::fromBinary($j->receipt_id),
            'receiptNumber' => $receipt?->number,
            'copies' => (int) $j->copies,
            'status' => $j->status,
            'requestedByStaffId' => Ids::fromBinary($j->requested_by_staff_id),
            'requestedBy' => DB::table('staff')->where('id', $j->requested_by_staff_id)->selectRaw("CONCAT(first_name,' ',last_name) AS n")->value('n'),
            'printedByStaffId' => Fmt::u($j->printed_by_staff_id),
            'printedAt' => Fmt::ts($j->printed_at),
            'failureReason' => $j->failure_reason,
            'createdAt' => Fmt::ts($j->created_at),
        ];
        if ($withReceipt && $receipt !== null) {
            $out['receipt'] = $this->receipts->present($receipt, false);
        }

        return $out;
    }
}

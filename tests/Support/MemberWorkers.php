<?php

namespace Tests\Support;

use App\Domain\Attendance\Adapters\ZktecoAdmsAdapter;
use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Services\PunchIngestService;
use App\Domain\Membership\Services\MembershipValidator;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/** Child-process worker bodies. */
final class MemberWorkers
{
    public function scan(int $index, string $membershipId, string $facilityId, bool $sameRef): array
    {
        $number = DB::table('membership')->where('id', Ids::toBinary($membershipId))->value('number');
        $r = app(MembershipValidator::class)->validate(['membershipNumber' => $number, 'clientRef' => $sameRef ? 'same-scan' : 'scan-'.$index], $facilityId);

        return ['valid' => $r['valid'], 'reason' => $r['reason'], 'duplicate' => $r['duplicate']];
    }

    /** Ingest the SAME batch of raw ADMS lines (used by the concurrent dedup test). */
    public function ingestBatch(int $index, string $attendanceDeviceId, string $body): array
    {
        $device = AttendanceDevice::query()->findOrFail($attendanceDeviceId);
        $punches = app(ZktecoAdmsAdapter::class)->parsePunches($body, $device);

        return app(PunchIngestService::class)->ingest($device, $punches);
    }

    /** Each racer ingests its own slice ($bodies[$index]). */
    public function ingestIndexed(int $index, string $attendanceDeviceId, array $bodies): array
    {
        return $this->ingestBatch($index, $attendanceDeviceId, $bodies[$index]);
    }
}

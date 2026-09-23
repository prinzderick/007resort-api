<?php

namespace Tests\Support;

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
}

<?php

namespace App\Domain\Devices\Services;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/** One-time device registration codes (issued by an admin holding `device.register`, redeemed once by POST /devices/register). */
class RegistrationCodeService
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I

    public const TTL_HOURS = 24;

    /** @return array{code: string, expiresAt: string} plaintext code is shown ONCE; only its hash is stored */
    public function issue(string $organizationId, string $siteId, ?string $facilityId, ?string $createdBy, int $ttlHours = self::TTL_HOURS): array
    {
        $raw = '';
        for ($i = 0; $i < 10; $i++) {
            $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $expires = now('UTC')->addHours($ttlHours);
        DB::table('device_registration_code')->insert([
            'id' => Ids::toBinary(Ids::uuid7()),
            'code_hash' => self::hash($raw),
            'organization_id' => Ids::toBinary($organizationId),
            'site_id' => Ids::toBinary($siteId),
            'facility_unit_id' => $facilityId ? Ids::toBinary($facilityId) : null,
            'created_by' => $createdBy ? Ids::toBinary($createdBy) : null,
            'expires_at' => $expires->format('Y-m-d H:i:s.u'),
        ]);

        return ['code' => 'R7-'.substr($raw, 0, 5).'-'.substr($raw, 5), 'expiresAt' => $expires->format('Y-m-d\TH:i:s.v\Z')];
    }

    public static function hash(string $code): string
    {
        $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
        if (strlen($c) === 12 && str_starts_with($c, 'R7')) { // display form R7-XXXXX-XXXXX
            $c = substr($c, 2);
        }

        return hash('sha256', $c);
    }
}

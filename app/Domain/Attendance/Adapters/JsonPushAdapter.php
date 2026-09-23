<?php

namespace App\Domain\Attendance\Adapters;

use App\Domain\Attendance\Contracts\BiometricTerminalAdapter;
use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Support\ParsedPunch;
use Carbon\CarbonImmutable;

/** The API's own JSON shape (contract `PunchIngest`) used by a push bridge/agent sitting next to any terminal. */
class JsonPushAdapter implements BiometricTerminalAdapter
{
    public function key(): string
    {
        return 'JSON_PUSH';
    }

    public function parsePunches(string|array $payload, AttendanceDevice $device): array
    {
        if (is_string($payload)) {
            $payload = json_decode($payload, true) ?? [];
        }
        $tz = $device->time_zone ?: 'Africa/Lagos';
        $out = [];
        foreach ($payload['punches'] ?? [] as $p) {
            $at = CarbonImmutable::parse((string) $p['punchedAt'], $tz)->utc(); // offset in the string wins over $tz
            $out[] = new ParsedPunch(
                terminalUserId: (string) $p['deviceUserId'],
                punchedAt: $at,
                verifyMode: isset($p['verifyMode']) ? strtoupper((string) $p['verifyMode']) : null,
                direction: in_array($p['direction'] ?? 'UNKNOWN', ['IN', 'OUT', 'UNKNOWN'], true) ? ($p['direction'] ?? 'UNKNOWN') : 'UNKNOWN',
                rawId: isset($p['rawId']) ? (string) $p['rawId'] : null,
            );
        }

        return $out;
    }
}

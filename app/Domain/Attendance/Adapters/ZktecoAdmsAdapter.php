<?php

namespace App\Domain\Attendance\Adapters;

use App\Domain\Attendance\Contracts\BiometricTerminalAdapter;
use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Support\ParsedPunch;
use Carbon\CarbonImmutable;

/**
 * ZKTeco ADMS / iClock push protocol (the terminal's "Cloud Server" setting: server address + port, requests under /iclock).
 *
 * ATTLOG body (POST /iclock/cdata?SN=<serial>&table=ATTLOG&Stamp=<n>), one record per line, TAB separated:
 *   <PIN>\t<YYYY-MM-DD HH:MM:SS>\t<status>\t<verify>\t<workcode>\t<reserved>...
 * status: 0 check-in, 1 check-out, 2 break-out, 3 break-in, 4 overtime-in, 5 overtime-out.
 * verify: 0 password, 1 fingerprint, 2 card, 15 face, 25 palm (others kept as MODE_<n>).
 * Times are the terminal's local clock without an offset -> converted with the device time zone.
 * Firmware variants separate fields with runs of spaces instead of tabs; the regex accepts both.
 */
class ZktecoAdmsAdapter implements BiometricTerminalAdapter
{
    private const VERIFY = [0 => 'PASSWORD', 1 => 'FINGERPRINT', 2 => 'CARD', 3 => 'PASSWORD', 4 => 'CARD', 15 => 'FACE', 25 => 'PALM'];

    private const IN = [0, 3, 4];

    private const OUT = [1, 2, 5];

    public function key(): string
    {
        return 'ZKTECO_ADMS';
    }

    public function parsePunches(string|array $payload, AttendanceDevice $device): array
    {
        if (is_array($payload)) {
            $payload = implode("\n", array_map('strval', $payload));
        }
        $tz = $device->time_zone ?: 'Africa/Lagos';
        $out = [];
        foreach (preg_split('/\r\n|\n|\r/', $payload) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ! preg_match('/^(\S+)\s+(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:\s+(\d+))?(?:\s+(\d+))?/', $line, $m)) {
                continue; // malformed / non-ATTLOG line: ignore rather than fail the whole batch
            }
            $status = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : null;
            $verify = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : null;
            try {
                $local = CarbonImmutable::createFromFormat('Y-m-d H:i:s', "{$m[2]} {$m[3]}", $tz);
                if ($local === false || $local->format('Y-m-d H:i:s') !== "{$m[2]} {$m[3]}") {
                    continue; // PHP silently rolls 2026-13-45 over; reject impossible dates instead
                }
                $at = $local->utc();
            } catch (\Throwable) {
                continue;
            }
            $out[] = new ParsedPunch(
                terminalUserId: $m[1],
                punchedAt: $at,
                verifyMode: $verify === null ? null : (self::VERIFY[$verify] ?? 'MODE_'.$verify),
                direction: in_array($status, self::IN, true) ? 'IN' : (in_array($status, self::OUT, true) ? 'OUT' : 'UNKNOWN'),
            );
        }

        return $out;
    }

    /** Body of the GET /iclock/cdata?SN=..&options=all handshake response. */
    public function handshake(AttendanceDevice $device): string
    {
        return implode("\n", [
            "GET OPTION FROM: {$device->serial_number}",
            'ATTLOGStamp='.($device->adms_stamp ?: 'None'),
            'OPERLOGStamp=9999',
            'ATTPHOTOStamp=None',
            'ErrorDelay=30',
            'Delay=10',
            'TransTimes=00:00;14:05',
            'TransInterval=1',
            'TransFlag=TransData AttLog',
            'TimeZone=1',
            'Realtime=1',
            'Encrypt=0',
        ])."\n";
    }
}

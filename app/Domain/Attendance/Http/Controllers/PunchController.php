<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Adapters\BiometricAdapters;
use App\Domain\Attendance\Adapters\ZktecoAdmsAdapter;
use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Services\PunchIngestService;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class PunchController
{
    public function __construct(private readonly PunchIngestService $ingest) {}

    /** POST /api/v1/attendance/punches  (X-Device-Token of the terminal/bridge; contract PunchIngest) */
    public function ingest(Request $request): JsonResponse
    {
        /** @var AttendanceDevice $device */
        $device = $request->attributes->get('attendance_device');
        $max = (int) config('attendance.max_punches_per_request');
        $d = $request->validate([
            'terminalSerial' => ['required', 'string', 'max:64'],
            'punches' => ['required', 'array', 'max:'.$max],
            'punches.*.deviceUserId' => ['required', 'string', 'max:32'],
            'punches.*.punchedAt' => ['required', 'date'],
            'punches.*.verifyMode' => ['nullable', 'string', 'max:24'],
            'punches.*.direction' => ['nullable', 'in:IN,OUT,UNKNOWN'],
            'punches.*.rawId' => ['required', 'string', 'max:64'],
        ]);
        if ($d['terminalSerial'] !== $device->serial_number) {
            throw ApiProblem::forbidden('terminal_mismatch', 'terminalSerial does not match the authenticated device.');
        }
        $parsed = BiometricAdapters::named('JSON_PUSH')->parsePunches($d, $device);

        return response()->json($this->ingest->ingest($device, $parsed));
    }

    // ---- ZKTeco ADMS / iClock (mounted at /iclock/*, NOT under /api/v1; see routes-iclock.php) ----

    /** GET = handshake / options; POST = data upload (table=ATTLOG carries punches). */
    public function cdata(Request $request): Response
    {
        $device = $this->device($request);
        if ($device === null) {
            return $this->text('Unknown device', 403);
        }
        $adapter = BiometricAdapters::named('ZKTECO_ADMS');
        if ($request->isMethod('GET')) {
            DB::table('attendance_device')->where('id', Ids::toBinary($device->id))->update(['last_seen_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u')]);

            return $this->text($adapter instanceof ZktecoAdmsAdapter ? $adapter->handshake($device) : 'OK');
        }

        $table = strtoupper((string) $request->query('table', ''));
        if ($table !== 'ATTLOG') {
            return $this->text('OK'); // OPERLOG / USERINFO / ATTPHOTO etc.: acknowledged, not used
        }
        $parsed = $adapter->parsePunches((string) $request->getContent(), $device);
        $r = $this->ingest->ingest($device, $parsed);
        if (($stamp = $request->query('Stamp')) !== null && preg_match('/^[0-9A-Za-z]{1,32}$/', (string) $stamp)) {
            DB::table('attendance_device')->where('id', Ids::toBinary($device->id))->update(['adms_stamp' => $stamp]);
        }

        return $this->text('OK: '.($r['accepted'] + $r['duplicates']));
    }

    /** GET /iclock/getrequest: the terminal polls for server commands. We issue none. */
    public function getRequest(Request $request): Response
    {
        $device = $this->device($request);
        if ($device === null) {
            return $this->text('Unknown device', 403);
        }
        DB::table('attendance_device')->where('id', Ids::toBinary($device->id))->update(['last_seen_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u')]);

        return $this->text('OK');
    }

    /** POST /iclock/devicecmd: command results from the terminal. Acknowledged. */
    public function deviceCmd(Request $request): Response
    {
        return $this->device($request) === null ? $this->text('Unknown device', 403) : $this->text('OK');
    }

    private function device(Request $request): ?AttendanceDevice
    {
        $sn = trim((string) $request->query('SN', ''));
        if ($sn === '') {
            return null;
        }

        return AttendanceDevice::query()->where('serial_number', $sn)->where('status', 'ACTIVE')->first();
    }

    private function text(string $body, int $status = 200): Response
    {
        return response($body, $status)->header('Content-Type', 'text/plain');
    }
}

<?php

namespace App\Domain\Devices\Services;

use App\Domain\Devices\Models\Device;
use App\Support\Ids;
use App\Support\Realtime\RealtimeEvent;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Admin/system commands to a device (contract DeviceCommand): stored for poll fallback AND pushed on `private-device.{id}`. */
class DeviceCommandService
{
    /** @param  'FORCE_LOGOUT'|'REFRESH_STATE'|'LOCK'|'REVOKE'  $command */
    public function issue(Device $device, string $command, array $payload = []): string
    {
        $id = Ids::uuid7();
        DB::table('device_command')->insert([
            'id' => Ids::toBinary($id), 'device_id' => Ids::toBinary($device->id), 'command' => $command,
            'payload' => json_encode((object) $payload, JSON_THROW_ON_ERROR),
            'issued_by' => ($s = RequestContext::staffId()) ? Ids::toBinary($s) : null,
            'issued_at' => now('UTC')->format('Y-m-d H:i:s.u'),
        ]);
        RealtimeEvent::publish("device.{$device->id}", 'device.command', ['command' => $command, 'payload' => (object) $payload]);

        return $id;
    }

    /** Pending commands, marked delivered. @return list<array<string, mixed>> */
    public function drain(Device $device): array
    {
        return DB::transaction(function () use ($device): array {
            $rows = DB::table('device_command')->where('device_id', Ids::toBinary($device->id))->whereNull('delivered_at')->orderBy('issued_at')->lockForUpdate()->get();
            if ($rows->isNotEmpty()) {
                DB::table('device_command')->whereIn('id', $rows->pluck('id')->all())->update(['delivered_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
            }

            return $rows->map(fn ($r) => [
                'id' => Ids::fromBinary($r->id), 'command' => $r->command,
                'issuedAt' => CarbonImmutable::parse($r->issued_at, 'UTC')->format('Y-m-d\TH:i:s.v\Z'),
                'payload' => json_decode($r->payload) ?? (object) [],
            ])->all();
        });
    }
}

<?php

namespace App\Domain\Config\Http\Controllers;

use App\Domain\Config\Services\DeviceAdminService;
use App\Domain\Devices\Models\Device;
use App\Support\Api\Concurrency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeviceAdminController
{
    public function __construct(private readonly DeviceAdminService $devices) {}

    public function update(Request $request, string $deviceId): JsonResponse
    {
        $d = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:200'], 'facilityId' => ['sometimes', 'nullable', 'uuid'], 'operatingPointId' => ['sometimes', 'nullable', 'uuid'],
            'mode' => ['sometimes', 'nullable', Rule::in(Device::MODES)], 'active' => ['sometimes', 'boolean'],
        ]);
        $r = $this->devices->update($deviceId, $d, Concurrency::ifMatch($request));

        return Concurrency::json($r, 200, $r['rowVersion']);
    }
}

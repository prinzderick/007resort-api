<?php

namespace Tests\Support;

use App\Domain\Organization\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/** Probe routes used by tests only (mounted under /api/v1/_test). Also loaded by concurrency workers. */
final class TestRoutes
{
    public static function register(): void
    {
        Route::middleware(['api', 'auth:staff'])->prefix('api/v1/_test')->group(function () {
            Route::get('needs-device-register', fn () => ['ok' => true])->middleware('permission:device.register');
            Route::get('facilities/{facilityId}/settle', fn (string $facilityId) => ['ok' => $facilityId])
                ->middleware('permission:order.settle,facility=facilityId');
            Route::get('needs-step-up', fn () => ['ok' => true])->middleware('stepup');

            Route::post('orgs', function (Request $r) {
                $data = $r->validate(['name' => ['required', 'string', 'max:100']]);
                $org = Organization::create(['name' => $data['name']]);
                usleep((int) $r->query('sleepMs', 0) * 1000);

                return response()->json(['id' => $org->id, 'name' => $org->name], 201);
            })->middleware('idempotent')->name('test.orgs.create');
        });
    }
}

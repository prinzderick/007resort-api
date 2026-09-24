<?php

namespace App\Domain\Config\Http\Controllers;

use App\Domain\Config\Services\SettingsService;
use App\Support\Api\Concurrency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController
{
    public function __construct(private readonly SettingsService $settings) {}

    public function business(): JsonResponse
    {
        $b = $this->settings->business();

        return Concurrency::json($b, 200, $b['rowVersion']);
    }

    public function updateBusiness(Request $request): JsonResponse
    {
        $d = $request->validate([
            'organizationName' => ['sometimes', 'string', 'min:1', 'max:200'], 'siteName' => ['sometimes', 'string', 'min:1', 'max:200'],
            'timezone' => ['sometimes', 'string', 'max:64', fn ($a, $v, $fail) => in_array($v, \DateTimeZone::listIdentifiers(), true) || $fail('Unknown time zone (use an IANA name such as Africa/Lagos).')],
            'currency' => ['sometimes', 'in:NGN'], 'address' => ['sometimes', 'nullable', 'string', 'max:255'], 'phone' => ['sometimes', 'nullable', 'string', 'max:40'], 'email' => ['sometimes', 'nullable', 'email', 'max:190'],
        ]);
        unset($d['currency']); // NGN only (read-only)
        $b = $this->settings->updateBusiness($d, Concurrency::ifMatch($request));

        return Concurrency::json($b, 200, $b['rowVersion']);
    }

    public function receipt(): JsonResponse
    {
        $r = $this->settings->receipt();

        return Concurrency::json($r, 200, $r['rowVersion']);
    }

    public function updateReceipt(Request $request): JsonResponse
    {
        $d = $request->validate([
            'businessName' => ['sometimes', 'nullable', 'string', 'max:200'], 'address' => ['sometimes', 'nullable', 'string', 'max:255'], 'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'headerNote' => ['sometimes', 'nullable', 'string', 'max:255'], 'footer' => ['sometimes', 'nullable', 'string', 'max:500'], 'logoUrl' => ['sometimes', 'nullable', 'url:https,http', 'max:500'],
            'showTin' => ['sometimes', 'boolean'], 'paperColumns' => ['sometimes', 'integer', 'in:32,48'],
        ]);
        $r = $this->settings->updateReceipt($d, Concurrency::ifMatch($request));

        return Concurrency::json($r, 200, $r['rowVersion']);
    }

    public function paymentMethods(string $facilityId): JsonResponse
    {
        $m = $this->settings->paymentMethods($facilityId);

        return Concurrency::json($m, 200, $m['version']);
    }

    public function setPaymentMethods(Request $request, string $facilityId): JsonResponse
    {
        $d = $request->validate(['methods' => ['required', 'array', 'min:1']]);
        $m = $this->settings->setPaymentMethods($facilityId, $d['methods'], Concurrency::ifMatch($request));

        return Concurrency::json($m, 200, $m['version']);
    }
}

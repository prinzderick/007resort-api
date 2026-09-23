<?php

namespace App\Domain\Organization\Http\Controllers;

use App\Domain\Organization\Services\TaxSettingService;
use App\Support\Http\ApiProblem;
use App\Support\Http\Etag;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaxSettingController
{
    public function __construct(private readonly TaxSettingService $tax) {}

    public function show(): JsonResponse
    {
        $s = $this->tax->get($this->org());

        return Etag::json($s, $s['rowVersion']);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vatEnabled' => ['sometimes', 'boolean'],
            'vatRatePercent' => ['sometimes', 'string', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
            'pricesTaxInclusive' => ['sometimes', 'boolean'],
            'vatNumber' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);
        if (isset($data['vatRatePercent']) && (float) $data['vatRatePercent'] > 100) {
            throw ApiProblem::unprocessable('validation_failed', 'Invalid VAT rate.', ['vatRatePercent' => ['Must be between 0 and 100.']]);
        }
        $current = $this->tax->get($this->org());
        Etag::assertMatches($request, $current['rowVersion']);

        $s = $this->tax->update($this->org(), $data, $current['rowVersion']);

        return Etag::json($s, $s['rowVersion']);
    }

    private function org(): string
    {
        return Tenant::organizationId() ?? throw ApiProblem::notFound('not_found', 'No organization is configured on this node.');
    }
}

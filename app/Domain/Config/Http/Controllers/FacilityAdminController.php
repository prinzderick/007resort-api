<?php

namespace App\Domain\Config\Http\Controllers;

use App\Domain\Config\Services\CapabilityAdminService;
use App\Domain\Config\Services\FacilityAdminService;
use App\Domain\Config\Services\FacilityLoader;
use App\Domain\Config\Services\OperatingRuleService;
use App\Domain\Config\Support\CapabilityCatalogue;
use App\Domain\Config\Support\FacilityInput;
use App\Domain\Config\Support\FacilityTemplates;
use App\Domain\Config\Support\RuleDefinitions;
use App\Domain\Organization\Services\CapabilityService;
use App\Support\Api\Concurrency;
use App\Support\Ids;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FacilityAdminController
{
    public function __construct(
        private readonly FacilityAdminService $facilities,
        private readonly FacilityLoader $loader,
        private readonly CapabilityAdminService $capabilities,
        private readonly OperatingRuleService $rules,
        private readonly CapabilityService $effective,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $request->merge(array_filter(['code' => strtoupper((string) $request->input('code', '')), 'kind' => $request->has('kind') ? strtoupper((string) $request->input('kind')) : null], fn ($v) => $v !== null && $v !== ''));
        $data = $request->validate(FacilityInput::rules(true));
        $f = $this->facilities->create($data);

        return Concurrency::json($f, 201, $f['rowVersion']);
    }

    public function update(Request $request, string $facilityId): JsonResponse
    {
        if ($request->has('kind')) {
            $request->merge(['kind' => strtoupper((string) $request->input('kind'))]);
        }
        $data = $request->validate(FacilityInput::rules(false));
        $f = $this->facilities->update($facilityId, $data, Concurrency::ifMatch($request));

        return Concurrency::json($f, 200, $f['rowVersion']);
    }

    public function deactivate(Request $request, string $facilityId): JsonResponse
    {
        $d = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:255'], 'cascade' => ['sometimes', 'boolean']]);
        $f = $this->facilities->deactivate($facilityId, $d['reason'] ?? null, (bool) ($d['cascade'] ?? false), Concurrency::ifMatch($request));

        return Concurrency::json($f, 200, $f['rowVersion']);
    }

    public function reactivate(Request $request, string $facilityId): JsonResponse
    {
        $f = $this->facilities->reactivate($facilityId, Concurrency::ifMatch($request));

        return Concurrency::json($f, 200, $f['rowVersion']);
    }

    public function move(Request $request, string $facilityId): JsonResponse
    {
        $d = $request->validate(['parentId' => ['present', 'nullable', 'uuid']]);
        $f = $this->facilities->move($facilityId, $d['parentId'], Concurrency::ifMatch($request));

        return Concurrency::json($f, 200, $f['rowVersion']);
    }

    public function destroy(string $facilityId): Response
    {
        $this->facilities->destroy($facilityId);

        return response()->noContent();
    }

    public function templates(): JsonResponse
    {
        return response()->json(['items' => array_values(FacilityTemplates::all()), 'kinds' => $this->kinds(), 'nextCursor' => null]);
    }

    public function capabilityTypes(): JsonResponse
    {
        $items = [];
        foreach (CapabilityCatalogue::all() as $code => $c) {
            $items[] = ['code' => $code] + $c + ['ruleKeys' => collect(RuleDefinitions::all())->filter(fn ($d) => in_array($code, $d['capabilities'], true))->keys()->values()->all()];
        }

        return response()->json(['items' => $items, 'nextCursor' => null]);
    }

    public function ruleDefinitions(): JsonResponse
    {
        return response()->json(['items' => $this->rules->catalogue(), 'nextCursor' => null]);
    }

    public function setCapabilities(Request $request, string $facilityId): JsonResponse
    {
        $d = $request->validate(['capabilities' => ['present', 'array'], 'capabilities.*' => ['required', 'string', 'max:48']]);
        $r = $this->capabilities->set($facilityId, $d['capabilities'], Concurrency::ifMatch($request));

        return Concurrency::json(['facilityId' => $facilityId] + $this->effective->effective($facilityId) + ['changed' => $r['changed']], 200, $r['version']);
    }

    public function operatingRules(string $facilityId): JsonResponse
    {
        $this->loader->find($facilityId);
        $r = $this->rules->effective(Ids::normalize($facilityId));

        return Concurrency::json($r, 200, $r['version']);
    }

    public function setOperatingRules(Request $request, string $facilityId): JsonResponse
    {
        $d = $request->validate(['rules' => ['required', 'array'], 'confirm' => ['sometimes', 'boolean']]);
        $r = $this->rules->apply(Ids::normalize($facilityId), $d['rules'], (bool) ($d['confirm'] ?? false), Concurrency::ifMatch($request));
        $out = $this->rules->effective(Ids::normalize($facilityId)) + ['changed' => $r['changed']];

        return Concurrency::json($out, 200, $out['version']);
    }

    /** @return list<string> */
    private function kinds(): array
    {
        return ['RECEPTION', 'RESTAURANT', 'CLUB', 'BAR', 'SPA', 'SALON', 'CAFE', 'KITCHEN', 'RETAIL', 'STORE', 'SPORTS', 'SPORTS_VENUE', 'POOL', 'EVENT_CENTRE', 'OFFICE', 'GATE', 'GENERAL'];
    }
}

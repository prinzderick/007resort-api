<?php

namespace App\Domain\Config\Http\Controllers;

use App\Domain\Config\Services\TicketTypeAdminService as S;
use App\Support\Api\Concurrency;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use App\Support\Ids;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TicketTypeAdminController
{
    private const MONEY = ['regex:/^\d{1,15}(\.\d{1,4})?$/'];

    public function __construct(private readonly S $types) {}

    public function index(Request $request): JsonResponse
    {
        $q = DB::table('ticket_type')->where('site_id', Ids::toBinary((string) Tenant::siteId()));
        if (($f = $request->query('facilityId')) !== null && $f !== '') {
            $q->where('facility_unit_id', Ids::isUuid($f) ? Ids::toBinary($f) : throw ApiProblem::unprocessable('validation_failed', 'facilityId must be a UUID.', ['facilityId' => ['Must be a UUID.']]));
        }
        if (($a = $request->query('active')) !== null && $a !== '') {
            $q->where('is_active', filter_var($a, FILTER_VALIDATE_BOOL) ? 1 : 0);
        }

        return response()->json(CursorPage::paginate($q, $request, 'code')->toArray(fn ($r) => $this->types->present($r)));
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['code' => strtoupper((string) $request->input('code', ''))]);
        $d = $request->validate(['code' => ['required', 'string', 'regex:/^[A-Z0-9][A-Z0-9_\-]{1,63}$/'], 'name' => ['required', 'string', 'max:200'], 'facilityId' => ['required', 'uuid'], ...$this->rules(), 'price' => ['sometimes', 'nullable', 'string', ...self::MONEY]]);
        $v = $this->types->create($d);

        return Concurrency::json($v, 201, $v['rowVersion']);
    }

    public function update(Request $request, string $ticketTypeId): JsonResponse
    {
        $d = $request->validate(['code' => ['prohibited'], 'name' => ['sometimes', 'string', 'max:200'], 'facilityId' => ['sometimes', 'uuid'], ...$this->rules(), 'price' => ['sometimes', 'nullable', 'string', ...self::MONEY]]);
        $v = $this->types->update($ticketTypeId, $d, Concurrency::ifMatch($request));

        return Concurrency::json($v, 200, $v['rowVersion']);
    }

    /** @return array<string, list<mixed>> */
    private function rules(): array
    {
        return [
            'format' => ['sometimes', Rule::in(S::FORMATS)], 'validationMode' => ['sometimes', Rule::in(S::MODES)], 'validityKind' => ['sometimes', Rule::in(S::VALIDITY)],
            'validityMinutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:525600'], 'earlyEntryMinutes' => ['sometimes', 'integer', 'min:0', 'max:1440'], 'active' => ['sometimes', 'boolean'],
        ];
    }
}

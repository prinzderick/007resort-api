<?php

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Identity\Models\Staff;
use App\Domain\Identity\Services\StaffAdminService;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use App\Support\Http\Etag;
use App\Support\Ids;
use App\Support\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Staff administration (permission `staff.manage`). Secrets are write-only and never audited or returned. */
class StaffController
{
    public function __construct(private readonly StaffAdminService $service) {}

    public function index(Request $request): JsonResponse
    {
        $q = Staff::query()->where('site_id', RequestContext::siteId());
        match ($request->query('filter')['status'] ?? null) {
            'ACTIVE' => $q->where('is_active', 1)->whereNull('deleted_at'),
            'SUSPENDED' => $q->where('is_active', 0)->whereNull('deleted_at'),
            'TERMINATED' => $q->whereNotNull('deleted_at'),
            default => null,
        };
        if (($term = trim((string) $request->query('q', ''))) !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
            $q->where(fn ($w) => $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('staff_number', 'like', $like)
                ->orWhereIn('id', fn ($s) => $s->select('staff_id')->from('user_account')->where('username', 'like', $like)));
        }

        return response()->json(CursorPage::paginate($q, $request, 'staff_number')->toArray(fn (Staff $s) => $s->toMember()));
    }

    public function show(string $staffId): JsonResponse
    {
        $s = $this->find($staffId);

        return Etag::json($s->toMember() + $this->service->credentialSummary($s), $s->row_version);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'staffNumber' => ['required', 'string', 'max:32'],
            'firstName' => ['required', 'string', 'max:120'],
            'lastName' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'username' => ['nullable', 'string', 'min:3', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/'],
        ]);
        $s = $this->service->create($d);

        return Etag::json($s->toMember() + $this->service->credentialSummary($s), $s->row_version, 201);
    }

    public function update(Request $request, string $staffId): JsonResponse
    {
        $s = $this->find($staffId);
        Etag::assertMatches($request, $s->row_version);
        $d = $request->validate([
            'firstName' => ['sometimes', 'string', 'max:120'],
            'lastName' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'status' => ['sometimes', 'in:ACTIVE,SUSPENDED,TERMINATED'],
        ]);
        $s = $this->service->update($s, $d);

        return Etag::json($s->toMember() + $this->service->credentialSummary($s), $s->row_version);
    }

    public function setPassword(Request $request, string $staffId): Response
    {
        $d = $request->validate(['password' => ['required', 'string', 'min:8', 'max:128']]);
        $this->service->setSecret($this->find($staffId), 'PASSWORD', $d['password']);

        return response()->noContent();
    }

    public function setPin(Request $request, string $staffId): Response
    {
        $d = $request->validate(['pin' => ['required', 'string', 'regex:/^\d{4,8}$/']]);
        $this->service->setSecret($this->find($staffId), 'PIN', $d['pin']);

        return response()->noContent();
    }

    public function registerCard(Request $request, string $staffId): Response
    {
        $d = $request->validate(['cardUid' => ['required', 'string', 'min:4', 'max:64']]);
        $this->service->registerCard($this->find($staffId), $d['cardUid']);

        return response()->noContent();
    }

    public function removeCard(string $staffId): Response
    {
        $this->service->removeCard($this->find($staffId));

        return response()->noContent();
    }

    private function find(string $staffId): Staff
    {
        return Ids::isUuid($staffId)
            ? (Staff::query()->where('site_id', RequestContext::siteId())->find($staffId) ?? throw ApiProblem::notFound('not_found', 'Staff member was not found.'))
            : throw ApiProblem::notFound('not_found', 'Staff member was not found.');
    }
}

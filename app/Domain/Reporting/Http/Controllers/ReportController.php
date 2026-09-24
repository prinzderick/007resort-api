<?php

namespace App\Domain\Reporting\Http\Controllers;

use App\Domain\Identity\Auth\Scope;
use App\Domain\Identity\Services\PermissionChecker;
use App\Domain\Reporting\Queries\AttendanceSummaryQuery;
use App\Domain\Reporting\Queries\CashierShiftReportQuery;
use App\Domain\Reporting\Queries\FacilityDailySummaryQuery;
use App\Domain\Reporting\Queries\MembershipSummaryQuery;
use App\Domain\Reporting\Queries\RevenueQuery;
use App\Domain\Reporting\Support\Freshness;
use App\Support\Http\ApiProblem;
use App\Support\RequestContext;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only report endpoints. Every response carries `freshness` (generatedAt, sourceNode, lastSyncAt, stale, ...). */
class ReportController
{
    public function __construct(private readonly PermissionChecker $checker) {}

    /** GET /reports/facility-daily-summary?date=&facilityId= */
    public function facilityDailySummary(Request $request, FacilityDailySummaryQuery $q): JsonResponse
    {
        $d = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'facilityId' => ['required', 'uuid']]);
        $this->requireAt('report.view', Scope::facility($d['facilityId']));
        $report = $q->run($d['facilityId'], $d['date']) ?? throw ApiProblem::notFound('facility_not_found', 'Facility not found.');

        return $this->respond($report, Freshness::siteOfFacility($d['facilityId']));
    }

    /** GET /reports/cashier-shift/{shiftId} */
    public function cashierShift(string $shiftId, CashierShiftReportQuery $q): JsonResponse
    {
        $report = $q->run($shiftId) ?? throw ApiProblem::notFound('shift_not_found', 'Shift / cash session not found.');
        $this->requireAt('report.view', Scope::facility($report['facilityId']));

        return $this->respond($report, Freshness::siteOfFacility($report['facilityId']));
    }

    /** GET /reports/revenue?from=&to=&facilityId=  (without facilityId: whole site, needs report.view.all) */
    public function revenue(Request $request, RevenueQuery $q): JsonResponse
    {
        $d = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'], 'facilityId' => ['sometimes', 'nullable', 'uuid']]);
        $this->assertRange($d['from'], $d['to']);
        $site = Tenant::siteId() ?? throw ApiProblem::badRequest('tenant_unresolved', 'No site in context.');
        if (! empty($d['facilityId'])) {
            $this->requireAt('report.view', Scope::facility($d['facilityId']));
        } else {
            $this->requireAt('report.view.all', Scope::site($site));
        }

        return $this->respond($q->run($site, $d['from'], $d['to'], RevenueQuery::expand($d['facilityId'] ?? null)), $site);
    }

    /** GET /reports/attendance-summary?from=&to=&staffId= */
    public function attendanceSummary(Request $request, AttendanceSummaryQuery $q): JsonResponse
    {
        $d = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'], 'staffId' => ['sometimes', 'nullable', 'uuid']]);
        $this->assertRange($d['from'], $d['to']);
        $site = Tenant::siteId() ?? throw ApiProblem::badRequest('tenant_unresolved', 'No site in context.');
        $this->requireAt('attendance.view', Scope::site($site));

        return $this->respond($q->run($site, $d['from'], $d['to'], $d['staffId'] ?? null), $site);
    }

    /** GET /reports/membership-summary?from=&to= (defaults: today) */
    public function membershipSummary(Request $request, MembershipSummaryQuery $q): JsonResponse
    {
        $today = CarbonImmutable::now('UTC')->format('Y-m-d');
        $d = $request->validate(['from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $from = $d['from'] ?? $today;
        $to = $d['to'] ?? $from;
        $this->assertRange($from, $to);
        $site = Tenant::siteId() ?? throw ApiProblem::badRequest('tenant_unresolved', 'No site in context.');
        $this->requireAt('report.view', Scope::site($site));

        return $this->respond($q->run($site, $from, $to), $site);
    }

    /** @param array<string, mixed> $report */
    private function respond(array $report, ?string $siteId): JsonResponse
    {
        return response()->json($report + ['freshness' => Freshness::forSite($siteId)]);
    }

    private function requireAt(string $permission, Scope $scope): void
    {
        try {
            $ok = $this->checker->can((string) RequestContext::staffId(), $permission, $scope);
        } catch (ModelNotFoundException) {
            throw ApiProblem::notFound('scope_not_found', 'The referenced facility/site was not found.');
        }
        if (! $ok) {
            throw ApiProblem::forbidden('permission_denied', "Missing permission: {$permission}.", ['permission' => $permission]);
        }
    }

    private function assertRange(string $from, string $to): void
    {
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 366) {
            throw ApiProblem::unprocessable('validation_failed', 'Range too large (max 366 days).', ['to' => ['Range too large (max 366 days).']]);
        }
    }
}

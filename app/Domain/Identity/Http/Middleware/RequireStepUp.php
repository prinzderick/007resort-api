<?php

namespace App\Domain\Identity\Http\Middleware;

use App\Domain\Identity\Services\StepUpService;
use App\Support\Http\ApiProblem;
use App\Support\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `stepup:<permission>` — requires a valid single-use `X-Step-Up-Token` for that approver permission (403 step_up_required
 * otherwise) and exposes the approver via RequestContext::approverId(). For "approve inline OR go to the approval queue"
 * flows call StepUpService::consume() in the controller instead.
 */
class RequireStepUp
{
    public function __construct(private readonly StepUpService $stepUp) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $approver = $this->stepUp->consume($request, $permission)
            ?? throw ApiProblem::forbidden('step_up_required', 'Supervisor step-up is required for this action.');
        RequestContext::set(RequestContext::APPROVER_ID, $approver);

        return $next($request);
    }
}

<?php

namespace App\Domain\Identity\Http\Middleware;

use App\Domain\Identity\Services\StaffAuthService;
use App\Support\Http\ApiProblem;
use App\Support\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `stepup` middleware: requires a successful POST /auth/staff/step-up within the last few minutes. */
class RequireStepUp
{
    public function handle(Request $request, Closure $next): Response
    {
        $sid = RequestContext::sessionId();
        if ($sid === null || ! StaffAuthService::hasRecentStepUp($sid)) {
            throw ApiProblem::forbidden('step_up_required', 'Re-enter your password or PIN to continue.');
        }

        return $next($request);
    }
}

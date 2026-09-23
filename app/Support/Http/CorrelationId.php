<?php

namespace App\Support\Http;

use App\Support\Ids;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** Accept/echo/generate `X-Correlation-Id` (one per user action; flows into logs). */
class CorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = substr((string) ($request->header('X-Correlation-Id') ?: Ids::uuid7()), 0, 100);
        $request->attributes->set('correlation_id', $id);
        Log::withContext(['correlationId' => $id]);

        $response = $next($request);
        $response->headers->set('X-Correlation-Id', $id);

        return $response;
    }
}

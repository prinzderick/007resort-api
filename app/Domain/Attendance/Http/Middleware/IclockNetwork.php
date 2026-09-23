<?php

namespace App\Domain\Attendance\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/** `attendance.iclock`: optional source-network allow-list for the ADMS endpoints (terminals cannot send auth headers). */
class IclockNetwork
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = (array) config('attendance.iclock_allowed_cidrs');
        if ($allowed !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            return response('Forbidden', 403)->header('Content-Type', 'text/plain');
        }

        return $next($request);
    }
}

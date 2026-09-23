<?php

namespace App\Domain\Sync\Http\Middleware;

use App\Domain\Sync\Services\NodeCredentials;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `node.auth` — node-to-node authentication for /sync/inbox|pull|heartbeat (architecture/17 §7.1).
 *  - bearer node credential, distinct from any staff/device token, checked against hashed NODE_TOKEN_HASHES;
 *  - an invalid/missing credential is a 401 + a `security_event` (WARNING) and counts against a per-IP failure limit;
 *  - a valid credential is rate limited per credential via Redis (`sync.rate_limit_per_minute`);
 *  - sets request attributes `sync.node` (local|cloud), `sync.site` (bound site or null), `sync.credential`.
 * Replay: a captured request re-applies nothing (event_id dedup) and heartbeats are bounded by a sentAt window.
 */
class AuthenticateNode
{
    public function handle(Request $request, Closure $next): Response
    {
        $peer = NodeCredentials::authenticate($request->bearerToken());

        if ($peer === null) {
            $this->rejected($request);
        }

        $key = 'sync-node:'.$peer['credentialId'];
        $limit = (int) config('sync.rate_limit_per_minute');
        try {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                throw ApiProblem::tooManyRequests('Node rate limit exceeded.', RateLimiter::availableIn($key));
            }
            RateLimiter::hit($key, 60);
        } catch (ApiProblem $e) {
            throw $e;
        } catch (Throwable) {
            // Redis unavailable: fail OPEN for an already-authenticated peer (sync must not depend on Redis).
        }

        $request->attributes->set('sync.node', $peer['node']);
        $request->attributes->set('sync.site', $peer['siteId']);
        $request->attributes->set('sync.credential', $peer['credentialId']);

        return $next($request);
    }

    private function rejected(Request $request): never
    {
        $badKey = 'sync-bad:'.$request->ip();
        $flooding = false;
        try {
            $flooding = RateLimiter::tooManyAttempts($badKey, 30);
            if (! $flooding) {
                RateLimiter::hit($badKey, 60);
            }
        } catch (Throwable) {
        }
        if ($flooding) { // stop writing a row per attempt once someone is hammering us
            throw ApiProblem::tooManyRequests('Too many failed node authentication attempts.', 60);
        }

        try {
            Audit::securityEvent('sync.node_auth_failed', 'WARNING', null, $request->ip(), [
                'path' => '/'.ltrim($request->path(), '/'), 'method' => $request->method(),
                'reason' => $request->bearerToken() ? 'unknown_or_revoked_credential' : 'missing_credential',
            ]);
        } catch (Throwable) {
        }

        throw ApiProblem::unauthenticated('invalid_node_token', 'A valid node credential is required.');
    }
}

<?php

namespace App\Support\Idempotency;

use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\RequestContext;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `idempotent[:scope]` route middleware (architecture/15 §1). Put AFTER auth middleware.
 *
 *  - Requires an `Idempotency-Key` header (400 idempotency_key_missing).
 *  - First sighting of (scope, actor, key): runs the handler inside ONE DB transaction together with
 *    the INSERT of the idempotency_record, so the business effect and the stored response commit
 *    atomically. Only 2xx responses are stored; any >=400 response/exception rolls the whole thing back
 *    (a failed request has no effect and the client may retry with the same key).
 *  - Replay (same key + same request fingerprint): returns the stored status + body, marked
 *    `Idempotent-Replayed: true`, without executing the handler.
 *  - Same key with a DIFFERENT method/path/body: 422 idempotency_key_reused.
 *  - Concurrent duplicates are serialised by a Redis lock (best effort) and, authoritatively, by the
 *    (scope,key) primary key: the loser rolls back and replays the winner's response.
 * Keys are namespaced per actor (staff/device) so one user can never replay another's response.
 */
class Idempotent
{
    public const HEADER = 'Idempotency-Key';

    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $key = trim((string) $request->header(self::HEADER, ''));
        if ($key === '') {
            throw ApiProblem::badRequest('idempotency_key_missing', 'The Idempotency-Key header is required for this request.');
        }
        if (strlen($key) > 128 || preg_match('/[^\x20-\x7E]/', $key)) {
            throw ApiProblem::badRequest('validation_failed', 'Idempotency-Key must be printable ASCII, max 128 characters.');
        }

        $scope = $this->scope($request, $scope);
        $actor = RequestContext::staffId() ?? (RequestContext::customerId() ? 'c:'.RequestContext::customerId() : null) ?? RequestContext::deviceId() ?? 'anonymous';
        $storedKey = hash('sha256', $actor.'|'.$key);
        $requestHash = $this->fingerprint($request);

        $lock = null;
        try {
            $lock = Cache::lock('idem:'.hash('sha1', $scope.'|'.$storedKey), 30);
            if (! $lock->block(10)) {
                throw ApiProblem::conflict('idempotency_request_in_progress', 'A request with this Idempotency-Key is still being processed.');
            }
        } catch (ApiProblem $e) {
            throw $e;
        } catch (Throwable) {
            $lock = null; // Redis unavailable: fall back to the DB primary key guard alone.
        }

        try {
            if ($replay = $this->existing($scope, $storedKey, $requestHash)) {
                return $replay;
            }

            DB::beginTransaction();
            try {
                $response = $next($request);

                if ($response->getStatusCode() >= 400) {
                    DB::rollBack();

                    return $response;
                }

                try {
                    DB::table('idempotency_record')->insert([
                        'scope' => $scope,
                        'idempotency_key' => $storedKey,
                        'request_hash' => $requestHash,
                        'response_status' => $response->getStatusCode(),
                        'response_body' => $this->bodyJson($response),
                        'created_at' => now('UTC')->format('Y-m-d H:i:s.u'),
                    ]);
                } catch (QueryException $e) {
                    if (($e->errorInfo[1] ?? null) === 1062) { // lost the race: undo our effects, replay the winner
                        DB::rollBack();

                        return $this->existing($scope, $storedKey, $requestHash) ?? throw $e;
                    }
                    throw $e;
                }
                DB::commit();

                return $response;
            } catch (Throwable $e) {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                throw $e;
            }
        } finally {
            $lock?->release();
        }
    }

    private function existing(string $scope, string $storedKey, string $requestHash): ?Response
    {
        $row = DB::table('idempotency_record')->where('scope', $scope)->where('idempotency_key', $storedKey)->first();
        if ($row === null) {
            return null;
        }
        if (! hash_equals($row->request_hash, $requestHash)) {
            throw ApiProblem::unprocessable('idempotency_key_reused', 'This Idempotency-Key was already used with a different request.');
        }

        return response($row->response_body === 'null' ? '' : $row->response_body, (int) $row->response_status, [
            'Content-Type' => 'application/json',
            'Idempotent-Replayed' => 'true',
        ]);
    }

    private function scope(Request $request, ?string $scope): string
    {
        $scope ??= $request->route()?->getName() ?: ($request->method().' '.($request->route()?->uri() ?? $request->path()));

        return strlen($scope) <= 64 ? $scope : 'h:'.substr(hash('sha256', $scope), 0, 40);
    }

    private function fingerprint(Request $request): string
    {
        $body = $request->getContent();
        $decoded = json_decode($body);
        $canonical = json_last_error() === JSON_ERROR_NONE && $body !== '' ? Audit::canonicalize($decoded) : $body;
        $query = $request->query();
        ksort($query);

        return hash('sha256', $request->method()."\n".$request->path()."\n".http_build_query($query)."\n".$canonical);
    }

    private function bodyJson(Response $response): string
    {
        $content = (string) $response->getContent();
        if ($content === '') {
            return 'null';
        }
        json_decode($content);

        return json_last_error() === JSON_ERROR_NONE ? $content : json_encode($content);
    }
}

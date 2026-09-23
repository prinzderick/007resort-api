<?php

namespace Tests\Support;

use App\Domain\Sync\Services\PeerClient;
use App\Domain\Sync\Services\PeerResponse;
use App\Domain\Sync\Services\PeerUnreachable;
use Closure;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Tests\TwoNodeTestCase;

/**
 * In-process HTTP transport between the two simulated nodes. A call made while the "local" node is current is
 * executed against the "cloud" app instance (its own MySQL database and node config) through the real HTTP kernel,
 * so routing, middleware, validation, problem+json and DB transactions are all the real thing — only the socket is
 * skipped. Failure injection: `$down`, `failBefore` (request never reaches the peer), `loseResponse` (peer processed
 * it, the reply is lost — the classic at-least-once duplicate generator).
 */
final class InProcessPeer implements PeerClient
{
    public bool $down = false;

    public ?string $tokenOverride = null;

    /** @var (Closure(string, string): bool)|null */
    public ?Closure $failBefore = null;

    /** @var (Closure(string, string): bool)|null */
    public ?Closure $loseResponse = null;

    /** @var (Closure(string, string, ?array): ?PeerResponse)|null  force a specific peer response (e.g. 500 / 429 / 422) */
    public ?Closure $respond = null;

    /** @var list<array{method: string, path: string, status: ?int}> */
    public array $calls = [];

    public function __construct(private readonly TwoNodeTestCase $nodes) {}

    public function request(string $method, string $path, array $query = [], ?array $json = null): PeerResponse
    {
        $from = $this->nodes->currentNode();
        $to = $from === 'local' ? 'cloud' : 'local';
        $token = $this->tokenOverride ?? (string) config('sync.peer_node_token');

        if ($this->down || ($this->failBefore && ($this->failBefore)($method, $path))) {
            $this->calls[] = ['method' => $method, 'path' => $path, 'status' => null];
            throw new PeerUnreachable('simulated: connection refused');
        }

        if ($this->respond && ($forced = ($this->respond)($method, $path, $json)) !== null) {
            $this->calls[] = ['method' => $method, 'path' => $path, 'status' => $forced->status];

            return $forced;
        }

        $response = $this->nodes->onNode($to, fn () => $this->dispatch($method, $path, $query, $json, $token));
        $this->calls[] = ['method' => $method, 'path' => $path, 'status' => $response->status];

        if ($this->loseResponse && ($this->loseResponse)($method, $path)) {
            throw new PeerUnreachable('simulated: response lost (timeout)');
        }

        return $response;
    }

    private function dispatch(string $method, string $path, array $query, ?array $json, string $token): PeerResponse
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '10.20.30.40'];
        $content = null;
        if ($json !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
            $content = json_encode($json);
        }
        $original = app('request');
        app('auth')->forgetGuards();
        $response = app(Kernel::class)->handle(Request::create('/api/v1'.$path, $method, $query, [], [], $server, $content));
        app()->instance('request', $original);
        app('auth')->forgetGuards();

        $body = json_decode((string) $response->getContent(), true);
        $retry = $response->headers->get('Retry-After');

        return new PeerResponse($response->getStatusCode(), is_array($body) ? $body : [], is_numeric($retry) ? (int) $retry : null);
    }

    public function callCount(string $path): int
    {
        return count(array_filter($this->calls, fn ($c) => $c['path'] === $path));
    }
}

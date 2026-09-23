<?php

namespace Tests;

use App\Domain\Sync\Services\NodeCredentials;
use App\Domain\Sync\Services\OutboxPublisher;
use App\Domain\Sync\Services\PeerClient;
use App\Domain\Sync\Services\SyncApplierRegistry;
use App\Support\Ids;
use App\Support\Sync\Outbox;
use Closure;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\InProcessPeer;
use Tests\Support\TestData;
use Tests\Support\TestRoutes;

/**
 * Two-node test harness (ADR-0013): a "local" and a "cloud" node in ONE process, each with its own real MySQL
 * database (`*_local_test` / `*_cloud_test`), its own node config (APP_NODE, sync.* flags, credentials) and a real
 * HTTP kernel between them (InProcessPeer). Switch with useNode() / onNode(). No wrapping transaction: data is
 * committed so the code under test uses real transactions, exactly like production.
 *
 *   $this->onNode('local', fn () => DB::transaction(fn () => Outbox::record(...)));
 *   $this->onNode('local', fn () => app(OutboxPublisher::class)->run());     // pushes to cloud via InProcessPeer
 *   $this->onNode('cloud', fn () => DB::table('inbox_event')->count());
 */
abstract class TwoNodeTestCase extends BaseTestCase
{
    public const CLOUD_CONNECTION = 'node_cloud';

    private static bool $migrated = false;

    protected string $org;

    protected string $site;

    protected string $rawToken;

    protected InProcessPeer $peer;

    private string $localConnection = 'mysql';

    private string $current = 'local';

    /** @var array<string, mixed> config overrides that survive node switches (unlike plain config()) */
    private array $overrides = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->localConnection = (string) config('database.default');
        $localDb = (string) DB::connection()->getDatabaseName();
        if (! str_ends_with($localDb, '_test')) {
            throw new \RuntimeException("Refusing to run against non-test database '{$localDb}'.");
        }
        $cloudDb = (string) (env('SYNC_TEST_CLOUD_DATABASE') ?: preg_replace('/_test$/', '_cloud_test', $localDb));
        if ($cloudDb === $localDb) {
            throw new \RuntimeException('The cloud test database must differ from the local one.');
        }
        config(['database.connections.'.self::CLOUD_CONNECTION => array_merge(config('database.connections.'.$this->localConnection), ['database' => $cloudDb])]);
        DB::purge(self::CLOUD_CONNECTION);

        if (! self::$migrated) {
            $admin = array_merge(config('database.connections.'.$this->localConnection), ['database' => null]);
            config(['database.connections.two_node_admin' => $admin]);
            DB::connection('two_node_admin')->statement("CREATE DATABASE IF NOT EXISTS `{$cloudDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
            Artisan::call('migrate:fresh', ['--force' => true]);
            Artisan::call('migrate:fresh', ['--force' => true, '--database' => self::CLOUD_CONNECTION]);
            self::$migrated = true;
        }

        $this->org = Ids::uuid7();
        $this->site = Ids::uuid7();
        $token = NodeCredentials::generate();
        $this->rawToken = $token['token'];
        $this->tokenHash = $token['hash'];

        foreach (['local', 'cloud'] as $node) {
            $this->useNode($node);
            DB::statement('CREATE TABLE IF NOT EXISTS sync_probe (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, event_id VARCHAR(36) NOT NULL, kind VARCHAR(64) NOT NULL, entity_id VARCHAR(36) NOT NULL, version INT NOT NULL) ENGINE=InnoDB');
            TestData::wipe();
            DB::table('organization')->insert(['id' => Ids::toBinary($this->org), 'name' => 'Two-node Resort']);
            DB::table('site')->insert(['id' => Ids::toBinary($this->site), 'organization_id' => Ids::toBinary($this->org), 'name' => 'Otueke']);
        }
        $this->peer = new InProcessPeer($this);
        $this->app->instance(PeerClient::class, $this->peer);
        $this->app->forgetInstance(OutboxPublisher::class);

        $this->useNode('local');
        Cache::flush();
        TestRoutes::register();
    }

    protected string $tokenHash = '';

    protected function tearDown(): void
    {
        $this->useNode('local');
        foreach (['cloud', 'local'] as $node) {
            $this->useNode($node);
            TestData::wipe();
        }
        $this->useNode('local');
        parent::tearDown();
    }

    public function currentNode(): string
    {
        return $this->current;
    }

    /** Make $node the current node for everything that follows (default DB connection + node config). */
    public function useNode(string $node): void
    {
        $this->current = $node;
        DB::setDefaultConnection($node === 'cloud' ? self::CLOUD_CONNECTION : $this->localConnection);
        config($this->overrides + $this->nodeConfig($node));
        $this->app['auth']->forgetGuards();
    }

    /** Config changes that must hold on BOTH nodes and survive useNode()/onNode() switches. */
    protected function override(array $config): void
    {
        $this->overrides = $config + $this->overrides;
        config($config);
    }

    /** Run $fn as the given node, then return to the previous one. */
    public function onNode(string $node, Closure $fn): mixed
    {
        $previous = $this->current;
        $this->useNode($node);
        try {
            return $fn();
        } finally {
            $this->useNode($previous);
        }
    }

    /** @return array<string, mixed> */
    protected function nodeConfig(string $node): array
    {
        $common = [
            'node.node' => $node, 'node.is_local' => $node === 'local', 'node.is_cloud' => $node === 'cloud',
            'node.site_id' => $this->site, 'node.organization_id' => $this->org,
            'sync.rate_limit_per_minute' => 600, 'sync.batch_size' => 50, 'sync.max_attempts' => 8,
            'sync.backoff_base' => 5, 'sync.backoff_cap' => 300, 'sync.stale_after' => 90, 'sync.heartbeat_interval' => 30,
            'sync.defer_escalate_after' => 900, 'sync.pull_lease' => 60, 'sync.audit_applied' => true,
        ];

        return $common + ($node === 'local' ? [
            'sync.push_enabled' => true, 'sync.pull_enabled' => true, 'sync.heartbeat_enabled' => true,
            'sync.accept_push' => false, 'sync.serve_pull' => false, 'sync.accept_heartbeat' => false,
            'sync.peer_node_token' => $this->rawToken, 'sync.node_token_hashes' => '',
        ] : [
            'sync.push_enabled' => false, 'sync.pull_enabled' => false, 'sync.heartbeat_enabled' => false,
            'sync.accept_push' => true, 'sync.serve_pull' => true, 'sync.accept_heartbeat' => true,
            'sync.peer_node_token' => '', 'sync.node_token_hashes' => 'local:'.$this->tokenHash,
        ]);
    }

    /** One-call helper: write a business row's worth of outbox event, in a real transaction, on $node. */
    protected function emit(string $node, string $type, string $entityType, string $entityId, array $payload, int $version = 1, ?string $siteId = null): string
    {
        return $this->onNode($node, fn () => DB::transaction(fn () => Outbox::record($type, $entityType, $entityId, $payload, $version, $this->org, $siteId ?? $this->site)));
    }

    protected function facility(string $node, string $code = 'restaurant', ?string $id = null): string
    {
        return $this->onNode($node, function () use ($code, $id) {
            $f = TestData::facility(['org' => $this->org, 'site' => $this->site], $code);
            if ($id !== null) {
                DB::table('facility_unit')->where('id', Ids::toBinary($f->id))->update(['id' => Ids::toBinary($id)]);

                return $id;
            }

            return $f->id;
        });
    }

    protected function applierRegistry(): SyncApplierRegistry
    {
        return $this->app->make(SyncApplierRegistry::class);
    }

    /** @return array<string, mixed> */
    protected function envelope(string $type, string $entityId, int $version, array $payload, string $sourceNode = 'local', ?string $eventId = null): array
    {
        return [
            'eventId' => $eventId ?? Ids::uuid7(), 'eventType' => $type, 'entityType' => 'Thing', 'entityId' => $entityId,
            'entityVersion' => $version, 'organizationId' => $this->org, 'siteId' => $this->site, 'facilityId' => null,
            'sourceNode' => $sourceNode, 'occurredAt' => now('UTC')->format('Y-m-d\TH:i:s.u\Z'), 'payload' => $payload === [] ? new \stdClass : $payload,
        ];
    }

    /** One-instance-many-requests: drop cached guard users like a fresh request would. */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}

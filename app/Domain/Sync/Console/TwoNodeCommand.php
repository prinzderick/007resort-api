<?php

namespace App\Domain\Sync\Console;

use App\Domain\Sync\Services\NodeCredentials;
use App\Support\Ids;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Run a Local node and a Cloud node side by side on one laptop, each with its own MySQL database and Redis DB:
 *
 *   composer two-node          (== php artisan r007:two-node)
 *
 * Creates/migrates both databases, seeds one shared organization + site (fixed UUIDs), mints a throwaway node
 * credential in memory (never written to disk) and starts serve + queue worker + scheduler for each node.
 * Local pushes its outbox to Cloud and polls Cloud; Cloud never calls Local. Ctrl-C stops everything.
 */
class TwoNodeCommand extends Command
{
    protected $signature = 'r007:two-node
        {--local-port=8171} {--cloud-port=8172}
        {--local-db=r007_sync_local} {--cloud-db=r007_sync_cloud}
        {--no-serve : prepare databases only (no processes)}';

    protected $description = 'Run Local + Cloud nodes on this machine (two databases, two Redis DBs, credentials minted in memory)';

    private const ORG = '0192f6a0-0000-7000-8000-0000000000a1';

    private const SITE = '0192f6a0-0000-7000-8000-0000000000a2';

    public function handle(): int
    {
        $php = PHP_BINARY;
        $token = NodeCredentials::generate();
        $nodes = [
            'local' => ['port' => (int) $this->option('local-port'), 'db' => $this->option('local-db'), 'redis' => 10],
            'cloud' => ['port' => (int) $this->option('cloud-port'), 'db' => $this->option('cloud-db'), 'redis' => 11],
        ];

        $env = [];
        foreach ($nodes as $name => $n) {
            $env[$name] = [
                'APP_NODE' => $name, 'APP_ENV' => 'local', 'DB_DATABASE' => $n['db'],
                'APP_URL' => 'http://127.0.0.1:'.$n['port'],
                'REDIS_DB' => (string) $n['redis'], 'REDIS_CACHE_DB' => (string) $n['redis'], 'REDIS_PREFIX' => "r007_two_{$name}_",
                'SITE_ID' => self::SITE, 'ORGANIZATION_ID' => self::ORG,
                'QUEUE_CONNECTION' => 'redis', 'CACHE_STORE' => 'redis', 'BROADCAST_CONNECTION' => 'log',
                'SYNC_HEARTBEAT_INTERVAL' => '10', 'SYNC_STALE_AFTER' => '30',
            ];
        }
        $env['local'] += ['PEER_URL' => 'http://127.0.0.1:'.$nodes['cloud']['port'], 'PEER_NODE_TOKEN' => $token['token']];
        $env['cloud'] += ['NODE_TOKEN_HASHES' => 'local:'.$token['hash']];

        foreach ($nodes as $name => $n) {
            $this->createDatabase($n['db']);
            $this->info("Migrating {$name} ({$n['db']})...");
            $p = new Process([$php, 'artisan', 'migrate', '--force'], base_path(), $env[$name], null, 300);
            $p->run();
            if (! $p->isSuccessful()) {
                $this->error($p->getErrorOutput().$p->getOutput());

                return self::FAILURE;
            }
            $this->seedTenant($n['db']);
        }
        if ($this->option('no-serve')) {
            $this->info('Databases ready.');

            return self::SUCCESS;
        }

        /** @var array<string, Process> $running */
        $running = [];
        foreach ($nodes as $name => $n) {
            $cmds = [
                "{$name}:serve" => [$php, 'artisan', 'serve', '--host=127.0.0.1', '--port='.$n['port'], '--no-reload'],
                "{$name}:queue" => [$php, 'artisan', 'queue:work', '--tries=1', '--sleep=1', '--max-time=3600'],
                "{$name}:schedule" => [$php, 'artisan', 'schedule:work'],
            ];
            foreach ($cmds as $label => $cmd) {
                $p = new Process($cmd, base_path(), $env[$name], null, null);
                $p->start(fn ($type, $buffer) => $this->emit($label, $buffer));
                $running[$label] = $p;
            }
        }
        $this->info(sprintf('Local  http://127.0.0.1:%d/api/v1  (DB %s)', $nodes['local']['port'], $nodes['local']['db']));
        $this->info(sprintf('Cloud  http://127.0.0.1:%d/api/v1  (DB %s)', $nodes['cloud']['port'], $nodes['cloud']['db']));
        $this->line('Watch sync with:  APP_NODE=local DB_DATABASE='.$nodes['local']['db'].' php artisan r007:sync:status   (Ctrl-C stops both nodes)');

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGINT, SIGTERM] as $sig) {
                pcntl_signal($sig, function () use (&$running) {
                    foreach ($running as $p) {
                        $p->stop(3);
                    }
                    exit(0);
                });
            }
        }
        while (true) {
            foreach ($running as $label => $p) {
                if (! $p->isRunning()) {
                    $this->error("[$label] exited (code {$p->getExitCode()}); stopping.");
                    foreach ($running as $q) {
                        $q->stop(3);
                    }

                    return self::FAILURE;
                }
            }
            usleep(250_000);
        }
    }

    private function createDatabase(string $db): void
    {
        $cfg = config('database.connections.mysql');
        config(['database.connections.two_node_admin' => array_merge($cfg, ['database' => null])]);
        DB::purge('two_node_admin');
        DB::connection('two_node_admin')->statement('CREATE DATABASE IF NOT EXISTS `'.str_replace('`', '', $db).'` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    }

    private function seedTenant(string $db): void
    {
        $cfg = config('database.connections.mysql');
        config(['database.connections.two_node_seed' => array_merge($cfg, ['database' => $db])]);
        DB::purge('two_node_seed');
        $c = DB::connection('two_node_seed');
        $c->table('organization')->insertOrIgnore(['id' => Ids::toBinary(self::ORG), 'name' => '007 Resort & Spa']);
        $c->table('site')->insertOrIgnore(['id' => Ids::toBinary(self::SITE), 'organization_id' => Ids::toBinary(self::ORG), 'name' => 'Otueke (two-node demo)']);
    }

    private function emit(string $name, string $buffer): void
    {
        foreach (preg_split('/\R/', rtrim($buffer)) ?: [] as $line) {
            if ($line !== '') {
                $this->line(sprintf('<fg=%s>[%s]</> %s', str_starts_with($name, 'local') ? 'cyan' : 'magenta', $name, $line));
            }
        }
    }
}

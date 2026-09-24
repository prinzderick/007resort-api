<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Runs the processes of a node side by side (pure PHP — no npm needed): HTTP server, queue worker,
 * scheduler and, with --reverb, the Reverb WebSocket server. Ctrl-C stops everything.
 *
 *   composer dev            -> php artisan r007:run
 *   composer local-node     -> php artisan r007:run --reverb
 */
class RunNode extends Command
{
    protected $signature = 'r007:run {--host=127.0.0.1 : bind address for HTTP and Reverb (0.0.0.0 to serve the LAN)} {--port= : HTTP port (default API_PORT or 8007)} {--reverb : also start Laravel Reverb}';

    protected $description = 'Run serve + queue + scheduler (+ reverb) together for local development / the Local node';

    public function handle(): int
    {
        $port = $this->option('port') ?: (env('API_PORT') ?: 8007);
        $php = PHP_BINARY;
        $procs = [
            'serve' => [$php, 'artisan', 'serve', '--host='.$this->option('host'), '--port='.$port, '--no-reload'],
            'queue' => [$php, 'artisan', 'queue:work', '--tries=3', '--sleep=1', '--max-time=3600'],
            'schedule' => [$php, 'artisan', 'schedule:work'],
        ];
        if ($this->option('reverb')) {
            $procs['reverb'] = [$php, 'artisan', 'reverb:start', '--host='.$this->option('host')];
        }

        /** @var array<string, Process> $running */
        $running = [];
        foreach ($procs as $name => $cmd) {
            // The PHP built-in server is single-threaded; several workers let tablets, KDS and long-polling clients overlap (macOS/Linux).
            $p = new Process($cmd, base_path(), $name === 'serve' ? [
                'PHP_CLI_SERVER_WORKERS' => (string) (env('API_WORKERS') ?: 4),
                // CMS image uploads (8 MB) need more than PHP's stock upload limits: add config/php/*.ini to the scan dirs (empty entry = the default dir).
                'PHP_INI_SCAN_DIR' => (string) (getenv('PHP_INI_SCAN_DIR') ?: '').':'.base_path('config/php'),
            ] : null, null, null);
            $p->start(fn ($type, $buffer) => $this->emit($name, $buffer));
            $running[$name] = $p;
        }
        $this->info('Node "'.config('node.node').'" running: '.implode(', ', array_keys($running)).' — API on http://'.$this->option('host').':'.$port.'/api/v1  (Ctrl-C to stop)');

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
            foreach ($running as $name => $p) {
                if (! $p->isRunning()) {
                    $this->error("[$name] exited (code {$p->getExitCode()}); stopping node.");
                    foreach ($running as $q) {
                        $q->stop(3);
                    }

                    return self::FAILURE;
                }
            }
            usleep(250_000);
        }
    }

    private function emit(string $name, string $buffer): void
    {
        foreach (preg_split('/\R/', rtrim($buffer)) ?: [] as $line) {
            if ($line !== '') {
                $this->line(sprintf('<fg=cyan>[%s]</> %s', $name, $line));
            }
        }
    }
}

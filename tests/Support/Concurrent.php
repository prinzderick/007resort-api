<?php

namespace Tests\Support;

/**
 * Runs callables in N separate PHP processes (each with its own app + MySQL connection), released at
 * the same instant, and returns their JSON results. Real concurrency against real MySQL.
 *
 *   $results = Concurrent::run(8, MyWorker::class, 'attempt', [$deviceId]);
 *
 * The worker class needs a public method `$method(int $index, ...$args)` returning a JSON-serialisable value.
 * Worker classes must be autoloadable (put them in tests/Support or the test file's namespace).
 */
final class Concurrent
{
    /** @return list<array{index: int, result: mixed, error: ?string}> */
    public static function run(int $processes, string $class, string $method, array $args = []): array
    {
        $script = base_path('tests/Support/worker.php');
        $startAt = microtime(true) + 1.5 + $processes * 0.05;
        $procs = [];
        for ($i = 0; $i < $processes; $i++) {
            $cmd = [PHP_BINARY, $script, $class, $method, (string) $i, sprintf('%.6f', $startAt), json_encode($args)];
            $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            $procs[$i] = [$p, $pipes];
        }
        $out = [];
        foreach ($procs as $i => [$p, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            proc_close($p);
            $decoded = json_decode(trim((string) strrchr("\n".trim($stdout), "\n")), true);
            $out[] = is_array($decoded) ? $decoded : ['index' => $i, 'result' => null, 'error' => 'worker failed: '.substr($stderr.$stdout, 0, 2000)];
        }

        return $out;
    }
}

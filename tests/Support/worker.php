<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRoutes;

// Child process entrypoint for Tests\Support\Concurrent. Boots the app, waits for the barrier, runs the worker.
[$script, $class, $method, $index, $startAt, $argsJson] = $argv;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestRoutes::register();

// Warm the connection so all processes hit MySQL at the same instant after the barrier.
DB::select('select 1');
while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    $result = (new $class)->$method((int) $index, ...json_decode($argsJson, true));
    echo "\n".json_encode(['index' => (int) $index, 'result' => $result, 'error' => null]);
} catch (Throwable $e) {
    echo "\n".json_encode(['index' => (int) $index, 'result' => null, 'error' => get_class($e).': '.$e->getMessage()]);
}

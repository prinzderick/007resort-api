<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestData;
use Tests\Support\TestRoutes;

/**
 * Base for REAL concurrency tests: no wrapping transaction (data is committed so child PHP processes
 * on their own MySQL connections can see it) and rows are wiped in setUp/tearDown.
 * Use Tests\Support\Concurrent::run() to fire N processes at the same instant. See docs/MODULES.md.
 */
abstract class ConcurrentTestCase extends BaseTestCase
{
    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (! str_ends_with((string) DB::connection()->getDatabaseName(), '_test')) {
            throw new \RuntimeException('Refusing to run against a non-test database.');
        }
        if (! self::$migrated) {
            Artisan::call('migrate:fresh', ['--force' => true]);
            self::$migrated = true;
        }
        TestData::wipe();
        Cache::flush();
        TestRoutes::register();
    }

    protected function tearDown(): void
    {
        TestData::wipe();
        parent::tearDown();
    }
}

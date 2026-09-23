<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRoutes;

/**
 * Feature tests run against REAL MySQL 8.4 (never SQLite) in the dedicated `*_test` database.
 * Schema is built once per run (migrate:fresh); each test runs in a transaction that is rolled back.
 * Tests that need real concurrency (separate connections) use ConcurrentTestCase instead.
 */
abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush(); // test Redis DB only (REDIS_CACHE_DB in phpunit.xml)
        TestRoutes::register();
    }

    /** One app instance serves many requests in a test: drop cached guard users and per-request headers like a fresh request would. */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        try {
            return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        } finally {
            $this->defaultHeaders = []; // headers (withToken/withHeaders) apply to ONE request, never leak into the next
        }
    }

    protected function setUpTraits()
    {
        $db = DB::connection()->getDatabaseName();
        if (! str_ends_with((string) $db, '_test')) {
            throw new \RuntimeException("Refusing to run tests against non-test database '{$db}'.");
        }
        if (! self::$migrated) {
            Artisan::call('migrate:fresh', ['--force' => true]);
            self::$migrated = true;
        }

        return parent::setUpTraits();
    }
}

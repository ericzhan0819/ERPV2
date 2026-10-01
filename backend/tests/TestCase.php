<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $config = $app['config'];
        $name = $config->get('database.default');
        $connection = $config->get("database.connections.{$name}", []);
        $sqlite = ($connection['driver'] ?? null) === 'sqlite' && $name === 'sqlite' && ($connection['database'] ?? null) === ':memory:';
        $mysql = str_contains(static::class, 'Mysql')
            && $name === 'mysql'
            && ($connection['database'] ?? null) === 'erpv2_ci_test'
            && env('RUN_MYSQL_CONCURRENCY_TESTS') === '1'
            && env('MYSQL_CONCURRENCY_TEST_CONNECTION') === $name
            && env('MYSQL_CONCURRENCY_TEST_DATABASE') === 'erpv2_ci_test';

        // Runs before RefreshDatabase can invoke migrate:fresh; never connect to validate safety.
        if (! $app->environment('testing') || $config->get('app.env') !== 'testing'
            || $app->configurationIsCached() || isset($connection['read']) || isset($connection['write'])
            || ! empty($connection['url']) || (! $sqlite && ! $mysql)) {
            throw new RuntimeException('Unsafe test database configuration: refusing database access.');
        }

        return $app;
    }
}

<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class TestDatabaseIsolationTest extends TestCase
{
    public function test_exported_database_variables_cannot_override_memory_database(): void
    {
        $process = new Process([
            PHP_BINARY, 'vendor/bin/phpunit', '--filter',
            'UserAccountSchemaTest::test_account_fields_and_username_unique_index_exist',
        ], dirname(__DIR__, 2), [
            'APP_ENV' => 'production', 'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => '/nonexistent-probe/database.sqlite',
            'DB_URL' => 'mysql://invalid:invalid@127.0.0.1:1/production',
        ]);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }

    public function test_unsafe_effective_configuration_is_rejected_before_refresh_database(): void
    {
        $root = dirname(__DIR__, 2);
        $path = tempnam($root, 'phpunit-isolation-');
        try {
            $xml = file_get_contents($root.'/phpunit.xml');
            file_put_contents($path, str_replace('value="sqlite"', 'value="mysql"', $xml));
            $process = new Process([
                PHP_BINARY, 'vendor/bin/phpunit', '-c', $path, '--filter',
                'UserAccountSchemaTest::test_account_fields_and_username_unique_index_exist',
            ], $root, ['DB_HOST' => '127.0.0.1', 'DB_PORT' => '1']);
            $process->run();
            $output = $process->getOutput().$process->getErrorOutput();
            $this->assertNotSame(0, $process->getExitCode());
            $this->assertStringContainsString('Unsafe test database configuration', $output);
            $this->assertStringNotContainsString('SQLSTATE', $output);
        } finally {
            unlink($path);
        }
    }
}

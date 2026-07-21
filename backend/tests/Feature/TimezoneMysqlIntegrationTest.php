<?php

namespace Tests\Feature;

use App\Services\SalaryEligibilityService;
use App\Services\VehicleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TimezoneMysqlIntegrationTest extends TestCase
{
    public function test_taipei_timestamp_round_trips_across_real_mysql_connections(): void
    {
        $this->prepareDisposableMysqlDatabase();

        $primary = DB::connection();
        $this->assertSame('+08:00', $primary->selectOne('SELECT @@session.time_zone AS timezone')->timezone);

        $vehicleId = $primary->table('vehicles')->insertGetId([
            'stock_no' => 'TZ202607010001',
            'status' => 'sold',
            'brand' => 'Timezone',
            'model' => 'Boundary',
            'sold_at' => '2026-07-01 00:10:00',
            'created_at' => '2026-07-01 00:10:00',
            'updated_at' => '2026-07-01 00:10:00',
        ]);

        $secondConnectionName = 'timezone_mysql_second';
        config([
            "database.connections.{$secondConnectionName}" => config('database.connections.'.$primary->getName()),
        ]);
        DB::purge($secondConnectionName);

        try {
            $second = DB::connection($secondConnectionName);
            $this->assertNotSame(spl_object_id($primary->getPdo()), spl_object_id($second->getPdo()));
            $this->assertSame('+08:00', $second->selectOne('SELECT @@session.time_zone AS timezone')->timezone);

            $stored = $second->selectOne(
                "SELECT DATE_FORMAT(sold_at, '%Y-%m-%d %H:%i:%s') AS local_time,
                        DATE_FORMAT(sold_at, '%Y-%m') AS period_month,
                        UNIX_TIMESTAMP(sold_at) AS epoch
                   FROM vehicles
                  WHERE id = ?",
                [$vehicleId],
            );
            $expectedEpoch = CarbonImmutable::create(2026, 7, 1, 0, 10, 0, 'Asia/Taipei')->timestamp;

            $this->assertSame('2026-07-01 00:10:00', $stored->local_time);
            $this->assertSame('2026-07', $stored->period_month);
            $this->assertSame($expectedEpoch, (int) $stored->epoch);
            $this->assertSame(
                '2026-06-30 16:10:00',
                CarbonImmutable::createFromTimestampUTC((int) $stored->epoch)->format('Y-m-d H:i:s'),
            );
        } finally {
            DB::disconnect($secondConnectionName);
            DB::purge($secondConnectionName);
        }
    }

    public function test_salary_eligibility_selects_real_mysql_timestamp_month_boundaries_in_taipei(): void
    {
        $this->prepareDisposableMysqlDatabase();

        $connection = DB::connection();
        $ids = [];
        foreach ([
            'before' => '2026-05-31 23:59:59',
            'first' => '2026-06-01 00:00:00',
            'last' => '2026-06-30 23:59:59',
            'next' => '2026-07-01 00:00:00',
        ] as $label => $soldAt) {
            $ids[$label] = $connection->table('vehicles')->insertGetId([
                'stock_no' => 'TZ-ELIGIBILITY-'.strtoupper($label),
                'status' => 'sold',
                'brand' => 'Timezone',
                'model' => 'Eligibility',
                'sold_at' => $soldAt,
                'created_at' => $soldAt,
                'updated_at' => $soldAt,
            ]);
        }

        $result = app(SalaryEligibilityService::class)->inspectPeriod('2026-06');

        $this->assertSame([$ids['first'], $ids['last']], array_keys($result['vehicle_results']));
        $this->assertArrayNotHasKey($ids['before'], $result['vehicle_results']);
        $this->assertArrayNotHasKey($ids['next'], $result['vehicle_results']);
    }

    public function test_vehicle_sold_month_filter_uses_real_mysql_taipei_timestamp_boundaries(): void
    {
        $this->prepareDisposableMysqlDatabase();

        $connection = DB::connection();
        $ids = [];
        foreach ([
            'before' => '2026-06-30 23:59:59',
            'first' => '2026-07-01 00:00:00',
            'last' => '2026-07-31 23:59:59',
            'next' => '2026-08-01 00:00:00',
        ] as $label => $soldAt) {
            $ids[$label] = $connection->table('vehicles')->insertGetId([
                'stock_no' => 'TZ-SOLD-MONTH-'.strtoupper($label),
                'status' => 'sold',
                'brand' => 'Timezone',
                'model' => 'SoldMonth',
                'sold_at' => $soldAt,
                'created_at' => $soldAt,
                'updated_at' => $soldAt,
            ]);
        }

        $result = app(VehicleService::class)->listVehicles([
            'status' => 'sold',
            'sold_month' => '2026-07',
            'per_page' => 100,
        ]);

        $this->assertEqualsCanonicalizing([$ids['first'], $ids['last']], $result->pluck('id')->all());
    }

    private function prepareDisposableMysqlDatabase(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('此測試需要 MySQL/MariaDB；SQLite 不具 TIMESTAMP session timezone 語意。');
        }

        if (env('RUN_MYSQL_TIMEZONE_TESTS') !== '1') {
            $this->markTestSkipped(
                '此測試會 migrate:fresh 目前測試資料庫；請只在可拋棄 MySQL/MariaDB schema 上設定 RUN_MYSQL_TIMEZONE_TESTS=1 後執行。'
            );
        }

        $connection = DB::connection();
        $connectionName = $connection->getName();
        $databaseName = (string) $connection->getDatabaseName();
        $allowedConnection = (string) env('MYSQL_TIMEZONE_TEST_CONNECTION', '');
        $allowedDatabase = (string) env('MYSQL_TIMEZONE_TEST_DATABASE', '');

        $this->assertSame('testing', (string) config('app.env'), '拒絕執行 migrate:fresh：APP_ENV 必須是 testing。');
        $this->assertTrue(app()->environment('testing'), '拒絕執行 migrate:fresh：Laravel application environment 必須是 testing。');
        $this->assertTrue(app()->runningUnitTests(), '拒絕執行 migrate:fresh：只能由 PHPUnit 測試程序執行。');
        $this->assertNotSame('', $allowedConnection, '拒絕執行 migrate:fresh：必須設定專用測試連線 allowlist。');
        $this->assertSame($allowedConnection, $connectionName, '拒絕執行 migrate:fresh：目前 DB connection 不在 allowlist。');
        $this->assertNotSame('', $allowedDatabase, '拒絕執行 migrate:fresh：必須設定可拋棄測試資料庫 allowlist。');
        $this->assertSame($allowedDatabase, $databaseName, '拒絕執行 migrate:fresh：目前 DB database 不在 allowlist。');
        $this->assertTrue(
            $this->isClearlyDisposableTestDatabaseName($databaseName),
            "拒絕執行 migrate:fresh：資料庫名稱 [{$databaseName}] 必須明確包含 test/testing/phpunit/ci，且不得包含 production/staging/dev/local。",
        );

        $this->artisan('migrate:fresh')->run();
    }

    private function isClearlyDisposableTestDatabaseName(string $databaseName): bool
    {
        $normalized = strtolower(trim($databaseName));

        return preg_match('/(^|[_-])(test|testing|phpunit|ci)([_-]|$)/', $normalized) === 1
            && preg_match('/prod|production|live|staging|stage|dev|development|local/', $normalized) !== 1;
    }
}

<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerIdentitySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalized_identity_columns_and_unique_index_are_enforced_and_migration_is_rerunnable(): void
    {
        $this->assertTrue(Schema::hasColumn('customers', 'normalized_name'));
        $this->assertTrue(Schema::hasColumn('customers', 'normalized_phone'));
        $this->assertTrue(Schema::hasIndex('customers', Customer::IDENTITY_UNIQUE_INDEX));

        $columns = collect(Schema::getColumns('customers'))->keyBy('name');
        $this->assertFalse($columns->get('normalized_name')['nullable']);
        $this->assertTrue($columns->get('normalized_phone')['nullable']);

        $migration = require database_path('migrations/2026_07_18_000014_add_normalized_customer_identity.php');
        $migration->up();

        $this->assertTrue(Schema::hasIndex('customers', Customer::IDENTITY_UNIQUE_INDEX));
    }

    public function test_database_unique_constraint_rejects_duplicate_normalized_identity(): void
    {
        DB::table('customers')->insert([
            'name' => '第一筆顯示名稱',
            'normalized_name' => '同一身份',
            'phone' => '0912-345-678',
            'normalized_phone' => '0912345678',
            'customer_type' => Customer::TYPE_BUYER,
        ]);

        $this->expectException(QueryException::class);

        DB::table('customers')->insert([
            'name' => '第二筆顯示名稱',
            'normalized_name' => '同一身份',
            'phone' => '0912345678',
            'normalized_phone' => '0912345678',
            'customer_type' => Customer::TYPE_SELLER,
        ]);
    }
}

<?php

use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL 的 DDL 不受 migration transaction 完整保護。若既有資料有重複而中止，
        // 欄位可能已經建立；因此每一步都必須可安全重跑，讓人工整理重複資料後能直接
        // 再次 migrate，而不是卡在 duplicate column / index already exists。
        if (! Schema::hasColumn('customers', 'normalized_name')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->string('normalized_name')->nullable()->after('name');
            });
        }

        if (! Schema::hasColumn('customers', 'normalized_phone')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->string('normalized_phone')->nullable()->after('phone');
            });
        }

        DB::table('customers')
            ->select(['id', 'name', 'phone'])
            ->orderBy('id')
            ->chunkById(200, function ($customers): void {
                foreach ($customers as $customer) {
                    DB::table('customers')
                        ->where('id', $customer->id)
                        ->update([
                            'normalized_name' => Customer::normalizeIdentityName((string) $customer->name),
                            'normalized_phone' => Customer::normalizeIdentityPhone($customer->phone),
                        ]);
                }
            });

        // 沒有電話時 normalized_phone 維持 NULL；MySQL/SQLite 的複合 unique 都允許
        // 多筆 NULL，因此同名但無電話的客戶仍不會被系統擅自合併。
        $duplicate = DB::table('customers')
            ->select(['normalized_name', 'normalized_phone'])
            ->whereNotNull('normalized_phone')
            ->groupBy('normalized_name', 'normalized_phone')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException(
                'customers 已存在相同正規化姓名與電話的重複資料；請先人工確認並合併後再重新執行 migration。'
            );
        }

        Schema::table('customers', function (Blueprint $table): void {
            $table->string('normalized_name')->nullable(false)->change();
        });

        if (! Schema::hasIndex('customers', Customer::IDENTITY_UNIQUE_INDEX)) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->unique(
                    ['normalized_name', 'normalized_phone'],
                    Customer::IDENTITY_UNIQUE_INDEX,
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('customers', Customer::IDENTITY_UNIQUE_INDEX)) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->dropUnique(Customer::IDENTITY_UNIQUE_INDEX);
            });
        }

        $columns = array_values(array_filter(
            ['normalized_name', 'normalized_phone'],
            fn (string $column): bool => Schema::hasColumn('customers', $column),
        ));

        if ($columns !== []) {
            Schema::table('customers', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};

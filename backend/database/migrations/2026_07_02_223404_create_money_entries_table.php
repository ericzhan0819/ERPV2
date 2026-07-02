<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('money_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();

            $table->date('entry_date');
            $table->enum('direction', ['income', 'expense']);
            $table->string('category');
            // amount 以「分」為單位儲存，不使用 float/decimal。
            // 資料庫層以 CHECK 約束確保 amount > 0；FormRequest（第 7 部分）仍需補 integer|min:1 驗證。
            $table->unsignedBigInteger('amount');

            $table->string('counterparty_name')->nullable();
            $table->text('description')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index(['direction', 'entry_date']);
            $table->index('vehicle_id');
            $table->index('cash_account_id');
        });

        // MariaDB/MySQL 尚無 Blueprint::check() 語法糖，改用原生 CHECK 約束確保 amount 為正整數。
        // SQLite（測試環境）不支援 ALTER TABLE ADD CONSTRAINT 語法，故僅在 MySQL/MariaDB 執行。
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE money_entries ADD CONSTRAINT chk_money_entries_amount_positive CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('money_entries');
    }
};

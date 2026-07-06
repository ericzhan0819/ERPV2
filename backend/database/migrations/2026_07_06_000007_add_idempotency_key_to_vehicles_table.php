<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable()->after('stock_no');
            $table->unique('idempotency_key', 'vehicles_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropUnique('vehicles_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};

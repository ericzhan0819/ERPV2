<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('money_entries', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable();
            $table->unique('idempotency_key', 'money_entries_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('money_entries', function (Blueprint $table) {
            $table->dropUnique('money_entries_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};

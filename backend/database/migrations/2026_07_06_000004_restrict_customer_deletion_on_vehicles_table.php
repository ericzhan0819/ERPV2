<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supersedes the nullOnDelete() FKs added in
     * 2026_07_06_000003_add_customer_ids_to_vehicles_table: a customer with linked
     * vehicles must never be deletable, even silently. nullOnDelete would instead let
     * a customer delete succeed and quietly clear the vehicle's link, losing the
     * relationship. This is a forward-only migration (not an edit to the earlier one)
     * so it actually reruns the constraint change on any database that already
     * applied 2026_07_06_000003 before this fix existed.
     */
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropForeign(['seller_customer_id']);
            $table->dropForeign(['buyer_customer_id']);
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->foreign('seller_customer_id')->references('id')->on('customers')->restrictOnDelete();
            $table->foreign('buyer_customer_id')->references('id')->on('customers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropForeign(['seller_customer_id']);
            $table->dropForeign(['buyer_customer_id']);
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->foreign('seller_customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('buyer_customer_id')->references('id')->on('customers')->nullOnDelete();
        });
    }
};

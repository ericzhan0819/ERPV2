<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // restrictOnDelete (not nullOnDelete): a customer with linked vehicles must
            // never be deletable, even silently. The FK constraint is the final,
            // race-proof backstop behind CustomerService::deleteCustomer()'s own check.
            $table->foreignId('seller_customer_id')->nullable()->after('seller_phone')->constrained('customers')->restrictOnDelete();
            $table->foreignId('buyer_customer_id')->nullable()->after('buyer_phone')->constrained('customers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('seller_customer_id');
            $table->dropConstrainedForeignId('buyer_customer_id');
        });
    }
};

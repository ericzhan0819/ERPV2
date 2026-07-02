<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('stock_no')->unique();
            $table->string('status')->default('preparing');

            $table->string('brand');
            $table->string('model');
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('license_plate')->nullable();
            $table->string('vin')->nullable();
            $table->unsignedInteger('mileage_km')->nullable();
            $table->string('color')->nullable();

            $table->date('purchase_date')->nullable();
            $table->string('purchase_source_type')->nullable();
            $table->string('seller_name')->nullable();
            $table->string('seller_phone')->nullable();
            $table->unsignedBigInteger('purchase_price')->nullable();

            $table->unsignedBigInteger('asking_price')->nullable();
            $table->unsignedBigInteger('floor_price')->nullable();
            $table->date('listing_date')->nullable();
            $table->text('sales_note')->nullable();

            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->unsignedBigInteger('sold_price')->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('buyer_phone')->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};

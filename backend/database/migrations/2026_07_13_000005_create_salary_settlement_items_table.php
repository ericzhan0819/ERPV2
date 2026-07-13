<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_settlement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_settlement_id')->constrained('salary_settlements')->cascadeOnDelete();
            $table->string('type', 40)->index();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('description');
            $table->json('calculation_snapshot')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // vehicle_id 為 NULL 的底薪、保險與手動加扣項可有多筆；有 vehicle_id 的
            // 自動收／賣車獎金則由此唯一鍵防止同 settlement 重複計入同車同類型。
            $table->unique(
                ['salary_settlement_id', 'vehicle_id', 'type'],
                'salary_items_settlement_vehicle_type_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_settlement_items');
    }
};

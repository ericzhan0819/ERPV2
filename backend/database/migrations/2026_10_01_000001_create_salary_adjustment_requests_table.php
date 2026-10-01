<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_adjustment_requests', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 100)->unique();
            $table->foreignId('salary_settlement_id')->constrained()->restrictOnDelete();
            $table->foreignId('salary_settlement_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('type');
            $table->unsignedBigInteger('amount');
            $table->string('description');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_adjustment_requests');
    }
};

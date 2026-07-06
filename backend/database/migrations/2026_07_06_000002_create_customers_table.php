<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('line_id')->nullable();
            $table->string('customer_type')->default('other');
            $table->string('source')->nullable();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index('customer_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};

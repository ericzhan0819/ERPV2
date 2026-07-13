<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('base_salary')->default(0);
            $table->unsignedBigInteger('fixed_allowance')->default(0);
            $table->unsignedBigInteger('labor_insurance_deduction')->default(0);
            $table->unsignedBigInteger('health_insurance_deduction')->default(0);
            $table->boolean('commission_enabled')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_profiles');
    }
};

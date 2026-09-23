<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 先以 manager 作為既有帳號的安全預設；新增欄位後再把舊 is_admin=true 帳號回填為 admin。
            $table->string('role')->default('manager')->after('is_admin');
            $table->string('phone')->nullable()->after('role');
            $table->string('job_title')->nullable()->after('phone');
            $table->date('hire_date')->nullable()->after('job_title');
            $table->text('notes')->nullable()->after('hire_date');
        });

        DB::table('users')->where('is_admin', true)->update(['role' => 'admin']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'phone', 'job_title', 'hire_date', 'notes']);
        });
    }
};

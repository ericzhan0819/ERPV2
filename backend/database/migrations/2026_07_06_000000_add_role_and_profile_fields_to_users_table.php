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
            // Default 'manager' backfills every existing row (including is_admin=false
            // accounts) in the same statement that adds the column - see 企劃書_v1.1.md
            // §3.3: demoting existing non-admin accounts straight to 'sales' would be a
            // breaking, unrequested privilege drop, so they land on 'manager' and an
            // admin can re-assign 'sales' afterward.
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

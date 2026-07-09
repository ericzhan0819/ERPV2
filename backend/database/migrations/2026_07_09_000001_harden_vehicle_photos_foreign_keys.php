<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Forward-only fix: environments that already ran 2026_07_09_000000 recorded it in
// migrations and will never re-run its (edited) body. This migration corrects the
// live schema instead: vehicle_id was CASCADE (a vehicle delete could silently wipe
// photo rows before storage cleanup) and uploaded_by was nullable + SET NULL (a user
// delete could erase photo upload attribution). Both are hardened to RESTRICT, matching
// the app-level guards added in VehicleService::deleteVehicle() and UserService::deleteUser().
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('vehicle_photos')->whereNull('uploaded_by')->exists()) {
            throw new \RuntimeException(
                'vehicle_photos.uploaded_by 仍有 NULL 值，無法安全套用 NOT NULL 限制，請先人工確認來源後再重新執行本次 migration。'
            );
        }

        Schema::table('vehicle_photos', function (Blueprint $table) {
            $table->dropForeign(['vehicle_id']);
            $table->dropForeign(['uploaded_by']);
        });

        Schema::table('vehicle_photos', function (Blueprint $table) {
            $table->unsignedBigInteger('uploaded_by')->nullable(false)->change();

            $table->foreign('vehicle_id')->references('id')->on('vehicles')->restrictOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_photos', function (Blueprint $table) {
            $table->dropForeign(['vehicle_id']);
            $table->dropForeign(['uploaded_by']);
        });

        Schema::table('vehicle_photos', function (Blueprint $table) {
            $table->unsignedBigInteger('uploaded_by')->nullable()->change();

            $table->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
        });
    }
};

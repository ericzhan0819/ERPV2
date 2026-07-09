<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_photos', function (Blueprint $table) {
            $table->id();
            // Restrict (not cascade): deleting a vehicle must not silently wipe out
            // vehicle_photos rows before storage files are cleaned up. VehicleService::
            // deleteVehicle() checks for existing photos and blocks deletion instead.
            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->string('disk', 30)->default('public');
            $table->string('path');
            $table->string('thumbnail_path');
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_cover')->default(false);
            // Restrict (not null-on-delete): losing uploader attribution on a photo
            // erases accountability for who uploaded it. UserService::deleteUser()
            // checks for existing photos and blocks deletion instead (same pattern
            // as vehicles / money entries created_by / updated_by).
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // Generated column: only holds vehicle_id when is_cover=true, else NULL.
            // A unique index on this column enforces "at most one cover photo per vehicle"
            // at the database level (NULLs are not compared as equal, so non-cover rows
            // never collide), safely across concurrent connections on MySQL/MariaDB/SQLite.
            $table->unsignedBigInteger('cover_slot')
                ->virtualAs('CASE WHEN is_cover = 1 THEN vehicle_id ELSE NULL END')
                ->nullable();

            $table->index('vehicle_id');
            $table->index(['vehicle_id', 'sort_order']);
            $table->index(['vehicle_id', 'is_cover']);
            $table->unique('cover_slot', 'vehicle_photos_cover_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_photos');
    }
};

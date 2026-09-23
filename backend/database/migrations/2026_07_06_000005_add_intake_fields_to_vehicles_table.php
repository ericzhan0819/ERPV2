<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            if (! Schema::hasColumn('vehicles', 'displacement')) {
                $table->string('displacement')->nullable()->after('color');
            }
            if (! Schema::hasColumn('vehicles', 'transmission')) {
                $table->string('transmission')->nullable()->after('displacement');
            }
            if (! Schema::hasColumn('vehicles', 'fuel_type')) {
                $table->string('fuel_type')->nullable()->after('transmission');
            }
            if (! Schema::hasColumn('vehicles', 'parking_location')) {
                $table->string('parking_location')->nullable()->after('fuel_type');
            }

            if (! Schema::hasColumn('vehicles', 'has_registration_document')) {
                $table->boolean('has_registration_document')->default(false)->after('parking_location');
            }
            if (! Schema::hasColumn('vehicles', 'has_spare_key')) {
                $table->boolean('has_spare_key')->default(false)->after('has_registration_document');
            }
            if (! Schema::hasColumn('vehicles', 'is_transfer_completed')) {
                $table->boolean('is_transfer_completed')->default(false)->after('has_spare_key');
            }
            if (! Schema::hasColumn('vehicles', 'is_inspection_completed')) {
                $table->boolean('is_inspection_completed')->default(false)->after('is_transfer_completed');
            }
            if (! Schema::hasColumn('vehicles', 'is_preparation_completed')) {
                $table->boolean('is_preparation_completed')->default(false)->after('is_inspection_completed');
            }

            if (! Schema::hasColumn('vehicles', 'lien_note')) {
                $table->text('lien_note')->nullable()->after('is_preparation_completed');
            }
            if (! Schema::hasColumn('vehicles', 'condition_note')) {
                $table->text('condition_note')->nullable()->after('lien_note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            foreach ([
                'displacement',
                'transmission',
                'fuel_type',
                'parking_location',
                'has_registration_document',
                'has_spare_key',
                'is_transfer_completed',
                'is_inspection_completed',
                'is_preparation_completed',
                'lien_note',
                'condition_note',
            ] as $column) {
                if (Schema::hasColumn('vehicles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

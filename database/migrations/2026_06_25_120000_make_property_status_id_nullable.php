<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('properties') || !Schema::hasColumn('properties', 'status_id')) {
            return;
        }

        Schema::table('properties', function (Blueprint $table) {
            $table->unsignedBigInteger('status_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('properties') || !Schema::hasColumn('properties', 'status_id')) {
            return;
        }

        $fallbackStatusId = Schema::hasTable('property_statuses')
            ? DB::table('property_statuses')->value('id')
            : null;

        if ($fallbackStatusId !== null) {
            DB::table('properties')
                ->whereNull('status_id')
                ->update(['status_id' => $fallbackStatusId]);
        }

        Schema::table('properties', function (Blueprint $table) {
            $table->unsignedBigInteger('status_id')->nullable(false)->change();
        });
    }
};

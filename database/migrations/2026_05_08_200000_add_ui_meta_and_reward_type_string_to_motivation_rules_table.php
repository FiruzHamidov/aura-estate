<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('motivation_rules', function (Blueprint $table) {
            if (! Schema::hasColumn('motivation_rules', 'ui_meta')) {
                $table->json('ui_meta')->nullable()->after('date_to');
            }
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE motivation_rules MODIFY reward_type VARCHAR(64) NOT NULL");
        } elseif ($driver === 'pgsql') {
            DB::statement("ALTER TABLE motivation_rules ALTER COLUMN reward_type TYPE VARCHAR(64)");
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE motivation_rules MODIFY reward_type ENUM('trip_tashkent','trip_umra','company_party') NOT NULL");
        } elseif ($driver === 'pgsql') {
            DB::statement("ALTER TABLE motivation_rules ALTER COLUMN reward_type TYPE VARCHAR(64)");
        }

        Schema::table('motivation_rules', function (Blueprint $table) {
            if (Schema::hasColumn('motivation_rules', 'ui_meta')) {
                $table->dropColumn('ui_meta');
            }
        });
    }
};

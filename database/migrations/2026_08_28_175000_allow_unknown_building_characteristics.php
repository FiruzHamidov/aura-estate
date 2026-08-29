<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_buildings', function (Blueprint $table) {
            $table->boolean('heating')->nullable()->default(null)->change();
            $table->boolean('has_terrace')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        if (DB::table('new_buildings')->whereNull('heating')->orWhereNull('has_terrace')->exists()) {
            throw new RuntimeException('Confirm unknown building characteristics before reverting nullable columns.');
        }
        Schema::table('new_buildings', function (Blueprint $table) {
            $table->boolean('heating')->nullable(false)->default(false)->change();
            $table->boolean('has_terrace')->nullable(false)->default(false)->change();
        });
    }
};

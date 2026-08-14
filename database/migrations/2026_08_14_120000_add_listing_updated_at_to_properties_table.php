<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->timestamp('listing_updated_at')
                ->nullable()
                ->after('updated_at')
                ->index();
        });

        DB::table('properties')
            ->select('id')
            ->whereNull('listing_updated_at')
            ->orderBy('id')
            ->chunkById(1000, function ($properties): void {
                DB::table('properties')
                    ->whereIn('id', $properties->pluck('id'))
                    ->update(['listing_updated_at' => DB::raw('created_at')]);
            });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex(['listing_updated_at']);
            $table->dropColumn('listing_updated_at');
        });
    }
};

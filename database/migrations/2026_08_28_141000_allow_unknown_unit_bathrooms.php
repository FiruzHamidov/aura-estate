<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing zero/count values are preserved. Newly unknown values need not invent a zero.
        Schema::table('developer_units', fn (Blueprint $table) => $table->unsignedTinyInteger('bathrooms')->nullable()->default(null)->change());
    }

    public function down(): void
    {
        if (DB::table('developer_units')->whereNull('bathrooms')->exists()) {
            throw new RuntimeException('Resolve unknown bathroom counts before reverting the nullable column.');
        }
        Schema::table('developer_units', fn (Blueprint $table) => $table->unsignedTinyInteger('bathrooms')->nullable(false)->default(0)->change());
    }
};

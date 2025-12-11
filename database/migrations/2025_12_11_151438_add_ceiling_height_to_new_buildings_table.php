<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_buildings', function (Blueprint $table) {
            $table->decimal('ceiling_height', 4, 2)
                ->nullable()
                ->after('floors_range'); // располагаем после floors_range
        });
    }

    public function down(): void
    {
        Schema::table('new_buildings', function (Blueprint $table) {
            $table->dropColumn('ceiling_height');
        });
    }
};

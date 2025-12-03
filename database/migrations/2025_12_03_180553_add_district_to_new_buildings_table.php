<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('new_buildings', function (Blueprint $table) {
            $table->string('district')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('new_buildings', function (Blueprint $table) {
            $table->dropColumn('district');
        });
    }
};

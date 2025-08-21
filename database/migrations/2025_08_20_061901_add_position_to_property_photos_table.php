<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('property_photos', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->after('file_path');
            $table->index(['property_id', 'position']);
        });
    }

    public function down()
    {
        Schema::table('property_photos', function (Blueprint $table) {
            $table->dropIndex(['property_id', 'position']);
            $table->dropColumn('position');
        });
    }
};

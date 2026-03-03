<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->enum('construction_status', [
                'under_construction',
                'built',
                'commissioned',
            ])->nullable()->after('condition');

            $table->enum('renovation_permission_status', [
                'not_allowed',
                'allowed',
            ])->nullable()->after('construction_status');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn([
                'construction_status',
                'renovation_permission_status',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('favorites', function (Blueprint $table) {
            $table->unsignedBigInteger('property_id')->nullable()->change();
            $table->string('entity_type', 30)->default('property');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unique(['user_id', 'entity_type', 'entity_id'], 'favorites_typed_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('favorites')->where('entity_type', '!=', 'property')->exists()) {
            throw new RuntimeException('Keep the expansion while residential favorites exist. Disable the UI instead of deleting user data.');
        }
        Schema::table('favorites', function (Blueprint $table) {
            $table->dropUnique('favorites_typed_unique');
            $table->dropColumn(['entity_type', 'entity_id']);
            $table->unsignedBigInteger('property_id')->nullable(false)->change();
        });
    }
};

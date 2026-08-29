<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('residential_media_migrations', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 60);
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('new_building_id');
            $table->string('source_path');
            $table->string('source_sha256', 64);
            $table->string('backup_path');
            $table->json('old_values');
            $table->json('new_values');
            $table->timestamp('quarantined_at')->nullable();
            $table->timestamps();
            $table->unique(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('residential_media_migrations')->exists()) {
            throw new RuntimeException('Keep the media migration journal and recovery metadata during rollback.');
        }
        Schema::dropIfExists('residential_media_migrations');
    }
};

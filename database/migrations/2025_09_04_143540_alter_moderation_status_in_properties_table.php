<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("
            ALTER TABLE properties
            MODIFY moderation_status
            ENUM('pending', 'approved', 'rejected', 'draft', 'deleted', 'sold', 'rented')
            NOT NULL DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        // откат — возвращаем как было
        DB::statement("
            ALTER TABLE properties
            MODIFY moderation_status
            ENUM('pending', 'approved', 'rejected', 'draft', 'deleted')
            NOT NULL DEFAULT 'pending'
        ");
    }
};

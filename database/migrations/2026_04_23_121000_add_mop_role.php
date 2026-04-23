<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->upsert([
            [
                'name' => 'МОП',
                'slug' => 'mop',
                'description' => 'Старший менеджер с доступом к объектам группы филиалов',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['slug'], ['name', 'description', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('roles')
            ->where('slug', 'mop')
            ->delete();
    }
};

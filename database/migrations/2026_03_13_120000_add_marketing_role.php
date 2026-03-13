<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();

        DB::table('roles')->upsert([
            [
                'name' => 'Маркетолог',
                'slug' => 'marketing',
                'description' => 'Доступ уровня администратора без доступа к отчетам',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['slug'], ['name', 'description', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('roles')
            ->where('slug', 'marketing')
            ->delete();
    }
};

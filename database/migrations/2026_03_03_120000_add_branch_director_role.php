<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();

        DB::table('roles')->upsert([
            [
                'name' => 'РОП',
                'slug' => 'rop',
                'description' => 'Руководитель филиала с доступом к данным своего филиала',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['slug'], ['name', 'description', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('roles')
            ->where('slug', 'rop')
            ->delete();
    }
};

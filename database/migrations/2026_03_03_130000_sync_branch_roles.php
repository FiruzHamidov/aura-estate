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
            [
                'name' => 'Директор филиала',
                'slug' => 'branch_director',
                'description' => 'Директор филиала с доступом к управлению филиалом',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['slug'], ['name', 'description', 'updated_at']);

        DB::table('roles')
            ->where('slug', 'branch_admin')
            ->delete();
    }

    public function down(): void
    {
        $now = now();

        DB::table('roles')->upsert([
            [
                'name' => 'Директор филиала',
                'slug' => 'rop',
                'description' => 'Руководитель филиала с доступом к данным своего филиала',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Администратор филиала',
                'slug' => 'branch_admin',
                'description' => 'Управление сотрудниками и процессами внутри филиала',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['slug'], ['name', 'description', 'updated_at']);

        DB::table('roles')
            ->where('slug', 'branch_director')
            ->delete();
    }
};

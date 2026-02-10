<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();

        DB::table('roles')->upsert([
            [
                'name' => 'Администратор филиала',
                'slug' => 'branch_admin',
                'description' => 'Управление сотрудниками и процессами внутри филиала',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Менеджер',
                'slug' => 'manager',
                'description' => 'Работа с лидами и клиентскими заявками',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Оператор',
                'slug' => 'operator',
                'description' => 'Первичная обработка обращений клиентов',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['slug'], ['name', 'description', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('roles')
            ->whereIn('slug', ['branch_admin', 'manager', 'operator'])
            ->delete();
    }
};

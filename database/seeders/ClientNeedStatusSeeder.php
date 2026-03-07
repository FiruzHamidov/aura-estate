<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ClientNeedStatusSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        \DB::table('client_need_statuses')->upsert([
            ['name' => 'Новая', 'slug' => 'new', 'is_closed' => false, 'sort_order' => 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'В работе', 'slug' => 'in_progress', 'is_closed' => false, 'sort_order' => 20, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Ожидание', 'slug' => 'waiting', 'is_closed' => false, 'sort_order' => 30, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Закрыта успешно', 'slug' => 'closed_success', 'is_closed' => true, 'sort_order' => 40, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Закрыта без результата', 'slug' => 'closed_lost', 'is_closed' => true, 'sort_order' => 50, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ], ['slug'], ['name', 'is_closed', 'sort_order', 'is_active', 'updated_at']);
    }
}

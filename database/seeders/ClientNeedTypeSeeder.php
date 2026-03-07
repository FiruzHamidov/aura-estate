<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ClientNeedTypeSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        \DB::table('client_need_types')->upsert([
            ['name' => 'Покупка', 'slug' => 'buy', 'sort_order' => 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Аренда', 'slug' => 'rent', 'sort_order' => 20, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Продажа', 'slug' => 'sell', 'sort_order' => 30, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Инвестиция', 'slug' => 'invest', 'sort_order' => 40, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ], ['slug'], ['name', 'sort_order', 'is_active', 'updated_at']);
    }
}

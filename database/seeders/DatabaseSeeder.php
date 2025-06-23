<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Вызываем все сидеры по очереди
        $this->call([
            RoleSeeder::class,
            PropertyTypeSeeder::class,
            PropertyStatusSeeder::class,
            LocationSeeder::class,
            UserSeeder::class,
            PropertySeeder::class,
            // и далее любые другие сидеры:
            // ApplicationTypeSeeder::class,
            // RenovationServiceSeeder::class,
            // и т.д.
        ]);
    }
}

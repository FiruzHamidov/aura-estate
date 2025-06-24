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
            LocationSeeder::class,
            BuildingTypeSeeder::class,
            ParkingTypeSeeder::class,
            HeatingTypeSeeder::class,
            RepairTypeSeeder::class,
            PropertySeeder::class,
        ]);
    }
}

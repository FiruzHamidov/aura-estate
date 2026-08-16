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
            ClientTypeSeeder::class,
            ClientNeedTypeSeeder::class,
            ClientNeedStatusSeeder::class,
            ClientSourceSeeder::class,
            PropertyTypeSeeder::class,
            PropertyStatusSeeder::class,
            LocationSeeder::class,
            UserSeeder::class,
            LocationSeeder::class,
            BuildingTypeSeeder::class,
            ParkingTypeSeeder::class,
            HeatingTypeSeeder::class,
            RepairTypeSeeder::class,
            ContractTypesSeeder::class,
            DocumentTypeSeeder::class,
            PropertySeeder::class,
            ConstructionStageSeeder::class,
            MaterialSeeder::class,
            FeatureSeeder::class,
            TagSeeder::class,
            DeveloperSeeder::class,
            DemoNewBuildingSeeder::class,
            KpiModuleSeeder::class,
            MotivationRulesSeeder::class,
            AttendanceHolidaySeeder::class,
        ]);
    }
}

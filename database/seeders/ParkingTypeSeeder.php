<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ParkingType;

class ParkingTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            'Открытая',
            'Подземная',
            'Закрытая',
            'Многоуровневая',
            'Отсутствует'
        ];

        foreach ($types as $type) {
            ParkingType::create(['name' => $type]);
        }
    }
}

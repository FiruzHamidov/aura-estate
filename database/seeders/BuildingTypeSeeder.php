<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\BuildingType;

class BuildingTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            'Панельный',
            'Кирпичный',
            'Монолитный',
            'Блочный',
            'Деревянный'
        ];

        foreach ($types as $type) {
            BuildingType::create(['name' => $type]);
        }
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\RepairType;

class RepairTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            'Евроремонт',
            'Косметический ремонт',
            'Без ремонта',
            'Капитальный ремонт',
            'Дизайнерский ремонт',
        ];

        foreach ($types as $type) {
            RepairType::create(['name' => $type]);
        }
    }
}

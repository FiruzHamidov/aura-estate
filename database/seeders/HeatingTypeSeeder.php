<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\HeatingType;

class HeatingTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            'Центральное',
            'Индивидуальное',
            'Автономное',
            'Газовое',
            'Без отопления'
        ];

        foreach ($types as $type) {
            HeatingType::create(['name' => $type]);
        }
    }
}

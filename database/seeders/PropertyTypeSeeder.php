<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PropertyType;

class PropertyTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'Квартира', 'slug' => 'apartment'],
            ['name' => 'Дом', 'slug' => 'house'],
            ['name' => 'Дача', 'slug' => 'cottage'],
            ['name' => 'Комната', 'slug' => 'room'],
            ['name' => 'Коммерческая недвижимость', 'slug' => 'commercial'],
            ['name' => 'Промбаза', 'slug' => 'industrial_base'],
            ['name' => 'Завод', 'slug' => 'factory'],
        ] as $type) {
            PropertyType::query()->updateOrCreate(
                ['slug' => $type['slug']],
                ['name' => $type['name']]
            );
        }
    }
}

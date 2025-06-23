<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PropertyType;

class PropertyTypeSeeder extends Seeder
{
    public function run(): void
    {
        PropertyType::create([
            'name' => 'Квартира',
            'slug' => 'apartment',
        ]);

        PropertyType::create([
            'name' => 'Дом',
            'slug' => 'house',
        ]);

        PropertyType::create([
            'name' => 'Дача',
            'slug' => 'cottage',
        ]);

        PropertyType::create([
            'name' => 'Комната',
            'slug' => 'room',
        ]);

        PropertyType::create([
            'name' => 'Коммерческая недвижимость',
            'slug' => 'commercial',
        ]);
    }
}

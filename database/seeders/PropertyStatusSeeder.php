<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PropertyStatus;

class PropertyStatusSeeder extends Seeder
{
    public function run(): void
    {
        PropertyStatus::create([
            'name' => 'Доступен',
            'slug' => 'available',
        ]);

        PropertyStatus::create([
            'name' => 'Продан',
            'slug' => 'sold',
        ]);

        PropertyStatus::create([
            'name' => 'Арендован',
            'slug' => 'rented',
        ]);

        PropertyStatus::create([
            'name' => 'Забронирован',
            'slug' => 'reserved',
        ]);
    }
}

<?php

namespace Database\Seeders;

use App\Models\ConstructionStage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ConstructionStageSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['name' => 'Котлован', 'sort_order'=>10],
            ['name' => 'Монтаж этажей', 'sort_order'=>20],
            ['name' => 'Отделка', 'sort_order'=>30],
            ['name' => 'Сдан', 'sort_order'=>40],
        ];
        foreach ($items as $i) {
            ConstructionStage::updateOrCreate(
                ['slug' => Str::slug($i['name'])],
                ['name' => $i['name'], 'sort_order'=>$i['sort_order'], 'is_active'=>true]
            );
        }
    }
}

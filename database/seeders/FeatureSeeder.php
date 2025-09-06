<?php

namespace Database\Seeders;

use App\Models\Feature;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class FeatureSeeder extends Seeder
{
    public function run(): void
    {
        $names = [
            'Охраняемая территория','Подземный паркинг','Детская площадка',
            'Видеонаблюдение','Рядом школа','Рядом парк'
        ];
        foreach ($names as $name) {
            Feature::updateOrCreate(['slug'=>Str::slug($name)], ['name'=>$name]);
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\Material;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MaterialSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Кирпич','Монолит-кирпич','Панель'] as $name) {
            Material::updateOrCreate(['slug'=>Str::slug($name)], ['name'=>$name]);
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\Developer;
use Illuminate\Database\Seeder;

class DeveloperSeeder extends Seeder
{
    public function run(): void
    {
        Developer::updateOrCreate(
            ['name' => 'ООО «ЛучСтрой»'],
            [
                'phone' => '+992 900 000 000',
                'under_construction_count' => 2,
                'built_count' => 5,
                'founded_year' => 2012,
                'total_projects' => 7,
                'logo_path' => 'storage/logos/luchstroy.png',
                'website' => 'https://luchstroy.tj',
                'instagram' => 'https://instagram.com/luchstroy',
                'facebook' => null,
                'telegram' => null,
            ]
        );
    }
}

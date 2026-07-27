<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;

class DocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['slug' => 'technical_passport', 'name' => 'Техпаспорт'],
            ['slug' => 'certificate', 'name' => 'Свидетельство'],
            ['slug' => 'sale_contract', 'name' => 'Договор купли-продажи'],
            ['slug' => 'order', 'name' => 'Ордер'],
            ['slug' => 'certificate_other', 'name' => 'Сертификат'],
            ['slug' => 'other', 'name' => 'Другое'],
        ] as $type) {
            DocumentType::query()->updateOrCreate(
                ['slug' => $type['slug']],
                ['name' => $type['name']]
            );
        }
    }
}

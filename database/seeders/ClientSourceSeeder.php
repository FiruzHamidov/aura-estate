<?php

namespace Database\Seeders;

use App\Models\ClientSource;
use Illuminate\Database\Seeder;

class ClientSourceSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'phone', 'name' => 'Телефон', 'sort_order' => 10],
            ['code' => 'instagram', 'name' => 'Instagram', 'sort_order' => 20],
            ['code' => 'telegram', 'name' => 'Telegram', 'sort_order' => 30],
            ['code' => 'whatsapp', 'name' => 'WhatsApp', 'sort_order' => 40],
            ['code' => 'facebook', 'name' => 'Facebook', 'sort_order' => 50],
            ['code' => 'website', 'name' => 'Сайт', 'sort_order' => 60],
            ['code' => 'referral', 'name' => 'По рекомендации', 'sort_order' => 70],
            ['code' => 'walk_in', 'name' => 'Офлайн/с улицы', 'sort_order' => 80],
            ['code' => 'showing', 'name' => 'Показ', 'sort_order' => 85],
            ['code' => 'other', 'name' => 'Другое', 'sort_order' => 90],
        ];

        foreach ($rows as $row) {
            ClientSource::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'sort_order' => $row['sort_order'],
                    'is_active' => true,
                ]
            );
        }
    }
}

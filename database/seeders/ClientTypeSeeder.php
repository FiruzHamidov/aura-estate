<?php

namespace Database\Seeders;

use App\Models\ClientType;
use Illuminate\Database\Seeder;

class ClientTypeSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        \DB::table('client_types')->upsert([
            [
                'name' => 'Физлицо',
                'slug' => ClientType::SLUG_INDIVIDUAL,
                'is_business' => false,
                'sort_order' => 10,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Бизнесмен',
                'slug' => ClientType::SLUG_BUSINESS_OWNER,
                'is_business' => true,
                'sort_order' => 20,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['slug'], ['name', 'is_business', 'sort_order', 'is_active', 'updated_at']);
    }
}

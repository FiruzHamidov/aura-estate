<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ContractTypesSeeder extends Seeder
{
    public function run()
    {
        DB::table('contract_types')->insert([
            ['slug' => 'alternative', 'name' => 'Альтернативный'],
            ['slug' => 'exclusive',   'name' => 'Эксклюзив'],
            ['slug' => 'none',        'name' => 'Без договора'],
        ]);
    }
}

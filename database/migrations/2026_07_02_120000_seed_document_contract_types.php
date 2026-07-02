<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach ([
            ['slug' => 'technical_passport', 'name' => 'Техпаспорт'],
            ['slug' => 'contract', 'name' => 'Договор'],
        ] as $type) {
            DB::table('contract_types')->updateOrInsert(
                ['slug' => $type['slug']],
                [
                    'name' => $type['name'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('contract_types')
            ->whereIn('slug', ['technical_passport', 'contract'])
            ->whereNotIn('id', function ($query) {
                $query->select('contract_type_id')
                    ->from('properties')
                    ->whereNotNull('contract_type_id');
            })
            ->delete();
    }
};

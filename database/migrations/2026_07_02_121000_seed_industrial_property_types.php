<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach ([
            ['name' => 'Промбаза', 'slug' => 'industrial_base'],
            ['name' => 'Завод', 'slug' => 'factory'],
        ] as $type) {
            DB::table('property_types')->updateOrInsert(
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
        DB::table('property_types')
            ->whereIn('slug', ['industrial_base', 'factory'])
            ->whereNotIn('id', function ($query) {
                $query->select('type_id')
                    ->from('properties')
                    ->whereNotNull('type_id');
            })
            ->delete();
    }
};

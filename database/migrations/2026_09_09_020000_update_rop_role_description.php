<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('roles', 'description')) {
            DB::table('roles')->where('slug', 'rop')->update([
                'description' => 'Руководитель отдела продаж с доступом к закреплённым группам своего филиала',
            ]);
        }
    }

    public function down(): void
    {
        // Group policy and its description remain valid during an application rollback.
    }
};

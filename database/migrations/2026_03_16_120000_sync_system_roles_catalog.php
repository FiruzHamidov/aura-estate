<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $roles = collect(config('roles.system', []))
            ->map(fn (array $role) => array_merge($role, [
                'created_at' => $now,
                'updated_at' => $now,
            ]))
            ->all();

        if ($roles === []) {
            return;
        }

        DB::table('roles')->upsert(
            $roles,
            ['slug'],
            ['name', 'description', 'updated_at']
        );
    }

    public function down(): void
    {
        // Intentionally left empty: custom and existing system roles should remain stable on rollback.
    }
};

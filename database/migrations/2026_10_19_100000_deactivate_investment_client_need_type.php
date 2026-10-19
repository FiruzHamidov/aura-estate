<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Keep the dictionary entry for existing needs, but retire it from new selections.
        DB::table('client_need_types')->where('slug', 'invest')->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // An application rollback must not reactivate a retired dictionary entry.
    }
};

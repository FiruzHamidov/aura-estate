<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('branch_groups', fn (Blueprint $table) => $table->boolean('is_archived')->default(false)->index());
        // Verified source group; retain every historical foreign key and record.
        DB::table('branch_groups')->where('id', 1)->where('name', 'Ба пеш')->update(['is_archived' => true]);
    }

    public function down(): void
    {
        Schema::table('branch_groups', fn (Blueprint $table) => $table->dropColumn('is_archived'));
    }
};

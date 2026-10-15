<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', fn (Blueprint $table) => $table->boolean('is_hidden')->default(false)->index());
        // The requested branch was verified in the public branch directory.
        \Illuminate\Support\Facades\DB::table('branches')->where('id', 3)->where('name', 'Филиал Гиссар')->update(['is_hidden' => true]);
    }

    public function down(): void
    {
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('is_hidden'));
    }
};

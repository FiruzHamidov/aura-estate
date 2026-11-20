<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'attendance_hidden_from_others')) {
            return;
        }
        Schema::table('users', fn (Blueprint $table) => $table->boolean('attendance_hidden_from_others')->default(false));

        // Confirmed account; do not hide other superadmins or unrelated users on other installations.
        DB::table('users')->where('id', 14)->where('name', 'Munis Khizmatzoda')
            ->whereIn('role_id', DB::table('roles')->where('slug', 'superadmin')->select('id'))
            ->update(['attendance_hidden_from_others' => true]);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('attendance_hidden_from_others'));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('client_phone')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('bookings')->whereNull('client_phone')->update(['client_phone' => '']);

        Schema::table('bookings', function (Blueprint $table) {
            $table->string('client_phone')->nullable(false)->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (!Schema::hasColumn('clients', 'email_normalized')) {
                $table->string('email_normalized')->nullable()->after('email')->index();
            }
        });

        DB::table('clients')
            ->whereNotNull('email')
            ->update(['email_normalized' => DB::raw('LOWER(email)')]);

        Schema::table('clients', function (Blueprint $table) {
            $table->unique(['created_by', 'phone_normalized'], 'clients_unique_phone_per_creator');
            $table->unique(['created_by', 'email_normalized'], 'clients_unique_email_per_creator');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique('clients_unique_phone_per_creator');
            $table->dropUnique('clients_unique_email_per_creator');

            if (Schema::hasColumn('clients', 'email_normalized')) {
                $table->dropColumn('email_normalized');
            }
        });
    }
};

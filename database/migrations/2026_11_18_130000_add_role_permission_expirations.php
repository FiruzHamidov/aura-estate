<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('roles', 'permission_expirations')) Schema::table('roles', function (Blueprint $table) {
            $table->json('permission_expirations')->nullable();
        });
        if (! Schema::hasColumn('role_permission_audits', 'before_expirations')) Schema::table('role_permission_audits', function (Blueprint $table) {
            $table->json('before_expirations')->nullable();
            $table->json('after_expirations')->nullable();
        });
    }

    public function down(): void
    {
        // Keep access deadlines and their audit trail; dropping them would make grants permanent.
    }
};

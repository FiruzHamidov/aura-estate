<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (!Schema::hasColumn('clients', 'status_id')) {
                $table->foreignId('status_id')
                    ->nullable()
                    ->after('status')
                    ->constrained('client_need_statuses')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'status_id')) {
                $table->dropConstrainedForeignId('status_id');
            }
        });
    }
};


<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('crm_client_id')
                ->nullable()
                ->after('client_id')
                ->constrained('clients')
                ->nullOnDelete();
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->foreignId('owner_client_id')
                ->nullable()
                ->after('owner_name')
                ->constrained('clients')
                ->nullOnDelete();

            $table->foreignId('buyer_client_id')
                ->nullable()
                ->after('buyer_phone')
                ->constrained('clients')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('crm_client_id');
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_client_id');
            $table->dropConstrainedForeignId('buyer_client_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (!Schema::hasColumn('properties', 'external_agent_id')) {
                $table->foreignId('external_agent_id')
                    ->nullable()
                    ->after('agent_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('properties', 'external_property_request_id')) {
                $table->foreignId('external_property_request_id')
                    ->nullable()
                    ->after('external_agent_id')
                    ->unique()
                    ->constrained('external_property_requests')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('properties', 'source_type')) {
                $table->string('source_type', 40)
                    ->nullable()
                    ->after('external_property_request_id')
                    ->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (Schema::hasColumn('properties', 'external_property_request_id')) {
                $table->dropConstrainedForeignId('external_property_request_id');
            }

            if (Schema::hasColumn('properties', 'external_agent_id')) {
                $table->dropConstrainedForeignId('external_agent_id');
            }

            if (Schema::hasColumn('properties', 'source_type')) {
                $table->dropColumn('source_type');
            }
        });
    }
};

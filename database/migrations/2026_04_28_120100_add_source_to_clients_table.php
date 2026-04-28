<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('source_id')
                ->nullable()
                ->after('client_type_id')
                ->constrained('client_sources')
                ->nullOnDelete();

            $table->text('source_comment')
                ->nullable()
                ->after('source_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_id');
            $table->dropColumn('source_comment');
        });
    }
};


<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->foreignId('document_type_id')
                ->nullable()
                ->after('contract_type_id')
                ->constrained('document_types')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_type_id');
        });

        Schema::dropIfExists('document_types');
    }
};

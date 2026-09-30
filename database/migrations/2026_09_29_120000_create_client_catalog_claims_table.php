<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_catalog_claims', function (Blueprint $table) {
            $table->id();
            // Durable ledger: deleting/transferring a client must not reset the daily quota.
            $table->unsignedBigInteger('client_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->date('claimed_on');
            $table->timestamp('created_at');
            $table->unique(['user_id', 'claimed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_catalog_claims');
    }
};

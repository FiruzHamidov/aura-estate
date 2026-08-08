<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->decimal('effective_price', 15, 2)
                ->storedAs('COALESCE(discount_price, price)');
            $table->index('effective_price', 'properties_effective_price_idx');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex('properties_effective_price_idx');
            $table->dropColumn('effective_price');
        });
    }
};

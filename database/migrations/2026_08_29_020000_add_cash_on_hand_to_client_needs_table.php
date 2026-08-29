<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_needs', function (Blueprint $table) {
            $table->boolean('has_cash_on_hand')->default(false)->after('budget_cash');
            $table->decimal('cash_on_hand_amount', 15, 2)->nullable()->after('has_cash_on_hand');
        });
    }

    public function down(): void
    {
        Schema::table('client_needs', function (Blueprint $table) {
            $table->dropColumn(['has_cash_on_hand', 'cash_on_hand_amount']);
        });
    }
};

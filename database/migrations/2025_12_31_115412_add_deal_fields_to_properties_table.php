<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {

            // Фактическая продажа
            $table->decimal('actual_sale_price', 15, 2)->nullable()->after('sold_at');
            $table->enum('actual_sale_currency', ['TJS', 'USD'])
                ->default('TJS')
                ->after('actual_sale_price');

            // Комиссия компании
            $table->decimal('company_commission_amount', 15, 2)->nullable()->after('actual_sale_currency');
            $table->enum('company_commission_currency', ['TJS', 'USD'])
                ->default('TJS')
                ->after('company_commission_amount');

            // У кого деньги
            $table->enum('money_holder', [
                'company',
                'agent',
                'owner',
                'developer',
                'client'
            ])->nullable()->after('company_commission_currency');

            // Даты
            $table->timestamp('money_received_at')->nullable()->after('money_holder');
            $table->timestamp('contract_signed_at')->nullable()->after('money_received_at');

            // Залог
            $table->decimal('deposit_amount', 15, 2)->nullable()->after('contract_signed_at');
            $table->enum('deposit_currency', ['TJS', 'USD'])
                ->default('TJS')
                ->after('deposit_amount');
            $table->timestamp('deposit_received_at')->nullable()->after('deposit_currency');
            $table->timestamp('deposit_taken_at')->nullable()->after('deposit_received_at');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn([
                'actual_sale_price',
                'actual_sale_currency',
                'company_commission_amount',
                'company_commission_currency',
                'money_holder',
                'money_received_at',
                'contract_signed_at',
                'deposit_amount',
                'deposit_currency',
                'deposit_received_at',
                'deposit_taken_at',
            ]);
        });
    }
};

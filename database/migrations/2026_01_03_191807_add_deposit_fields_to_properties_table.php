<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {

            // покупатель
            $table->string('buyer_full_name')->nullable();
            $table->string('buyer_phone')->nullable();

            // договор
            $table->timestamp('planned_contract_signed_at')->nullable();

            // комиссия компании (на этапе залога)
            $table->decimal('company_expected_income', 15, 2)->nullable();
            $table->string('company_expected_income_currency', 3)->nullable();
        });

        DB::statement("
            ALTER TABLE properties
            MODIFY moderation_status ENUM (
                'pending',
                'approved',
                'deposit',
                'rejected',
                'draft',
                'deleted',
                'sold',
                'rented',
                'sold_by_owner',
                'denied'
            ) NOT NULL DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn([
                'buyer_full_name',
                'buyer_phone',
                'planned_contract_signed_at',
                'company_expected_income',
                'company_expected_income_currency',
            ]);
        });

        DB::statement("
            ALTER TABLE properties
            MODIFY moderation_status ENUM (
                'pending',
                'approved',
                'rejected',
                'draft',
                'deleted',
                'sold',
                'rented',
                'sold_by_owner',
                'denied'
            ) NOT NULL DEFAULT 'pending'
        ");
    }
};

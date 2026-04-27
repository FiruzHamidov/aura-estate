<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('converted_client_id')
                ->nullable()
                ->after('client_id')
                ->constrained('clients')
                ->nullOnDelete();
            $table->foreignId('converted_deal_id')
                ->nullable()
                ->after('converted_client_id')
                ->constrained('crm_deals')
                ->nullOnDelete();
            $table->foreignId('client_need_id')
                ->nullable()
                ->after('converted_deal_id')
                ->constrained('client_needs')
                ->nullOnDelete();
            $table->decimal('budget', 15, 2)->nullable()->after('status');
            $table->string('currency', 3)->nullable()->after('budget');

        });

        Schema::table('crm_deals', function (Blueprint $table) {
            $table->foreignId('client_need_id')
                ->nullable()
                ->after('lead_id')
                ->constrained('client_needs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crm_deals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_need_id');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_need_id');
            $table->dropConstrainedForeignId('converted_deal_id');
            $table->dropConstrainedForeignId('converted_client_id');
            $table->dropColumn(['budget', 'currency']);
        });
    }
};

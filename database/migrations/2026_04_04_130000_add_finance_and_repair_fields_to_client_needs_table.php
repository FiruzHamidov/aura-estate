<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_needs', function (Blueprint $table) {
            $table->foreignId('repair_type_id')
                ->nullable()
                ->after('property_type_id')
                ->constrained('repair_types')
                ->nullOnDelete();

            $table->decimal('budget_total', 15, 2)->nullable()->after('budget_to');
            $table->decimal('budget_cash', 15, 2)->nullable()->after('budget_total');
            $table->decimal('budget_mortgage', 15, 2)->nullable()->after('budget_cash');
            $table->boolean('wants_mortgage')->default(false)->after('responsible_agent_id');
        });
    }

    public function down(): void
    {
        Schema::table('client_needs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('repair_type_id');
            $table->dropColumn([
                'budget_total',
                'budget_cash',
                'budget_mortgage',
                'wants_mortgage',
            ]);
        });
    }
};

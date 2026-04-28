<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_need_repair_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_need_id')->constrained('client_needs')->cascadeOnDelete();
            $table->foreignId('repair_type_id')->constrained('repair_types')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['client_need_id', 'repair_type_id'], 'client_need_repair_type_unique');
        });

        $rows = DB::table('client_needs')
            ->select(['id as client_need_id', 'repair_type_id'])
            ->whereNotNull('repair_type_id')
            ->get()
            ->map(fn ($row) => [
                'client_need_id' => (int) $row->client_need_id,
                'repair_type_id' => (int) $row->repair_type_id,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all();

        if ($rows !== []) {
            DB::table('client_need_repair_type')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_need_repair_type');
    }
};


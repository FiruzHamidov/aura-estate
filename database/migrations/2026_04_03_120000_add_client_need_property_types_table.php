<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_need_property_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_need_id')->constrained('client_needs')->cascadeOnDelete();
            $table->foreignId('property_type_id')->constrained('property_types')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['client_need_id', 'property_type_id']);
        });

        $now = now();

        $rows = DB::table('client_needs')
            ->whereNotNull('property_type_id')
            ->select(['id as client_need_id', 'property_type_id'])
            ->get()
            ->map(fn ($row) => [
                'client_need_id' => $row->client_need_id,
                'property_type_id' => $row->property_type_id,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($rows !== []) {
            DB::table('client_need_property_type')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_need_property_type');
    }
};

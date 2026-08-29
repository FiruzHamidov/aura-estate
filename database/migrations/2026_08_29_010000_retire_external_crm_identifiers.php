<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retired_integration_identifiers', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('entity_type', 32);
            $table->unsignedBigInteger('entity_id');
            $table->string('identifier_type', 32);
            $table->string('identifier_value');
            $table->timestamps();
            $table->unique(
                ['provider', 'entity_type', 'entity_id', 'identifier_type'],
                'retired_integration_identifier_unique'
            );
        });

        $this->archiveColumn('clients', 'bitrix_contact_id', 'client', 'contact_id');
        $this->archiveColumn('bookings', 'bitrix_activity_id', 'booking', 'activity_id');
    }

    public function down(): void
    {
        $this->restoreColumn('clients', 'bitrix_contact_id', 'client', 'contact_id', false);
        $this->restoreColumn('bookings', 'bitrix_activity_id', 'booking', 'activity_id', true);
        Schema::dropIfExists('retired_integration_identifiers');
    }

    private function archiveColumn(
        string $tableName,
        string $column,
        string $entityType,
        string $identifierType
    ): void {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, $column)) {
            return;
        }

        DB::table('retired_integration_identifiers')->insertOrIgnore([
            'provider' => 'bitrix24',
            'entity_type' => $entityType,
            'entity_id' => 0,
            'identifier_type' => 'schema_'.$identifierType,
            'identifier_value' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table($tableName)
            ->whereNotNull($column)
            ->select(['id', $column])
            ->orderBy('id')
            ->chunkById(500, function ($records) use ($column, $entityType, $identifierType): void {
                $timestamp = now();
                $rows = $records->map(fn ($record) => [
                    'provider' => 'bitrix24',
                    'entity_type' => $entityType,
                    'entity_id' => $record->id,
                    'identifier_type' => $identifierType,
                    'identifier_value' => (string) $record->{$column},
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])->all();

                if ($rows !== []) {
                    DB::table('retired_integration_identifiers')->insertOrIgnore($rows);
                }
            });

        Schema::table($tableName, function (Blueprint $table) use ($column): void {
            $table->dropIndex([$column]);
            $table->dropColumn($column);
        });
    }

    private function restoreColumn(
        string $tableName,
        string $column,
        string $entityType,
        string $identifierType,
        bool $stringValue
    ): void {
        if (
            ! Schema::hasTable($tableName)
            || Schema::hasColumn($tableName, $column)
            || ! DB::table('retired_integration_identifiers')
                ->where('provider', 'bitrix24')
                ->where('entity_type', $entityType)
                ->where('entity_id', 0)
                ->where('identifier_type', 'schema_'.$identifierType)
                ->exists()
        ) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column, $stringValue): void {
            $definition = $stringValue
                ? $table->string($column)->nullable()
                : $table->unsignedBigInteger($column)->nullable();
            $definition->index();
        });

        DB::table('retired_integration_identifiers')
            ->where('provider', 'bitrix24')
            ->where('entity_type', $entityType)
            ->where('identifier_type', $identifierType)
            ->select(['id', 'entity_id', 'identifier_value'])
            ->orderBy('id')
            ->chunkById(500, function ($records) use ($tableName, $column, $stringValue): void {
                foreach ($records as $record) {
                    DB::table($tableName)
                        ->where('id', $record->entity_id)
                        ->update([
                            $column => $stringValue
                                ? $record->identifier_value
                                : (int) $record->identifier_value,
                        ]);
                }
            });
    }
};

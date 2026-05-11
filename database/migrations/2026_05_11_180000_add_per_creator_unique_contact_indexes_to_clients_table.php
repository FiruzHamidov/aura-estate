<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (!Schema::hasColumn('clients', 'email_normalized')) {
                $table->string('email_normalized')->nullable()->after('email')->index();
            }
        });

        DB::table('clients')
            ->whereNotNull('email')
            ->update(['email_normalized' => DB::raw('LOWER(email)')]);

        // Keep the earliest client and clear conflicting normalized contacts on duplicates.
        DB::statement('
            UPDATE clients c
            SET c.phone_normalized = NULL
            WHERE c.created_by IS NOT NULL
              AND c.phone_normalized IS NOT NULL
              AND EXISTS (
                    SELECT 1
                    FROM clients c2
                    WHERE c2.created_by = c.created_by
                      AND c2.phone_normalized = c.phone_normalized
                      AND c2.id < c.id
              )
        ');

        DB::statement('
            UPDATE clients c
            SET c.email_normalized = NULL
            WHERE c.created_by IS NOT NULL
              AND c.email_normalized IS NOT NULL
              AND EXISTS (
                    SELECT 1
                    FROM clients c2
                    WHERE c2.created_by = c.created_by
                      AND c2.email_normalized = c.email_normalized
                      AND c2.id < c.id
              )
        ');

        Schema::table('clients', function (Blueprint $table) {
            if (!$this->hasIndex('clients', 'clients_unique_phone_per_creator')) {
                $table->unique(['created_by', 'phone_normalized'], 'clients_unique_phone_per_creator');
            }

            if (!$this->hasIndex('clients', 'clients_unique_email_per_creator')) {
                $table->unique(['created_by', 'email_normalized'], 'clients_unique_email_per_creator');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if ($this->hasIndex('clients', 'clients_unique_phone_per_creator')) {
                $table->dropUnique('clients_unique_phone_per_creator');
            }

            if ($this->hasIndex('clients', 'clients_unique_email_per_creator')) {
                $table->dropUnique('clients_unique_email_per_creator');
            }

            if (Schema::hasColumn('clients', 'email_normalized')) {
                $table->dropColumn('email_normalized');
            }
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $databaseName = DB::getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $databaseName)
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }
};

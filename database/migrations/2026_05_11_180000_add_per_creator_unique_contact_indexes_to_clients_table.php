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
            INNER JOIN (
                SELECT id
                FROM (
                    SELECT c1.id
                    FROM clients c1
                    INNER JOIN (
                        SELECT created_by, phone_normalized, MIN(id) AS keep_id
                        FROM clients
                        WHERE created_by IS NOT NULL
                          AND phone_normalized IS NOT NULL
                        GROUP BY created_by, phone_normalized
                        HAVING COUNT(*) > 1
                    ) d ON d.created_by = c1.created_by
                       AND d.phone_normalized = c1.phone_normalized
                    WHERE c1.id <> d.keep_id
                ) dup_phone_ids
            ) to_clear ON to_clear.id = c.id
            SET c.phone_normalized = NULL
        ');

        DB::statement('
            UPDATE clients c
            INNER JOIN (
                SELECT id
                FROM (
                    SELECT c1.id
                    FROM clients c1
                    INNER JOIN (
                        SELECT created_by, email_normalized, MIN(id) AS keep_id
                        FROM clients
                        WHERE created_by IS NOT NULL
                          AND email_normalized IS NOT NULL
                        GROUP BY created_by, email_normalized
                        HAVING COUNT(*) > 1
                    ) d ON d.created_by = c1.created_by
                       AND d.email_normalized = c1.email_normalized
                    WHERE c1.id <> d.keep_id
                ) dup_email_ids
            ) to_clear ON to_clear.id = c.id
            SET c.email_normalized = NULL
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

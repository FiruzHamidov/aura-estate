<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('branch_group_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('branch_groups')
                ->nullOnDelete();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('branch_group_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('branch_groups')
                ->nullOnDelete();
        });

        DB::table('clients')
            ->select(['id', 'responsible_agent_id', 'created_by'])
            ->orderBy('id')
            ->chunkById(500, function ($clients): void {
                foreach ($clients as $client) {
                    $responsibleAgentGroupId = $client->responsible_agent_id
                        ? DB::table('users')->where('id', $client->responsible_agent_id)->value('branch_group_id')
                        : null;

                    $creatorGroupId = $client->created_by
                        ? DB::table('users')->where('id', $client->created_by)->value('branch_group_id')
                        : null;

                    $branchGroupId = $responsibleAgentGroupId ?: $creatorGroupId;

                    if ($branchGroupId === null) {
                        continue;
                    }

                    DB::table('clients')
                        ->where('id', $client->id)
                        ->update(['branch_group_id' => $branchGroupId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_group_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_group_id');
        });
    }
};

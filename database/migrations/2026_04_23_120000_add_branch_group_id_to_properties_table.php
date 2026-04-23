<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('properties', 'branch_group_id')) {
            return;
        }

        Schema::table('properties', function (Blueprint $table) {
            $table->foreignId('branch_group_id')
                ->nullable()
                ->after('agent_id')
                ->constrained('branch_groups')
                ->nullOnDelete();
        });

        DB::table('properties')
            ->select(['id', 'agent_id', 'created_by'])
            ->orderBy('id')
            ->chunkById(500, function ($properties): void {
                foreach ($properties as $property) {
                    $agentBranchGroupId = $property->agent_id
                        ? User::query()->whereKey($property->agent_id)->value('branch_group_id')
                        : null;

                    $creatorBranchGroupId = $property->created_by
                        ? User::query()->whereKey($property->created_by)->value('branch_group_id')
                        : null;

                    $branchGroupId = $agentBranchGroupId ?: $creatorBranchGroupId;

                    if ($branchGroupId === null) {
                        continue;
                    }

                    DB::table('properties')
                        ->where('id', $property->id)
                        ->update(['branch_group_id' => $branchGroupId]);
                }
            });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('properties', 'branch_group_id')) {
            return;
        }

        Schema::table('properties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_group_id');
        });
    }
};

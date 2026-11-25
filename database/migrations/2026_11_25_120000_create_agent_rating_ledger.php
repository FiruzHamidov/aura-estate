<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_rating_sources', function (Blueprint $t) {
            $t->id();
            $t->string('source_key')->unique();
            $t->unsignedBigInteger('property_id')->nullable()->index();
            $t->unsignedBigInteger('deal_id')->nullable()->unique();
            $t->unsignedBigInteger('canonical_source_id')->nullable()->index();
            $t->text('link_reason')->nullable();
            $t->unsignedBigInteger('linked_by')->nullable();
            $t->dateTime('linked_at')->nullable();
            $t->unsignedBigInteger('source_version')->default(0);
            $t->string('kind', 12);
            $t->string('state', 16)->default('closed');
            $t->dateTime('closed_at')->nullable()->index();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('branch_group_id')->nullable();
            $t->unsignedBigInteger('approved_revision_id')->nullable();
            $t->unsignedBigInteger('pending_revision_id')->nullable();
            $t->json('financials')->nullable();
            $t->timestamps();
        });
        Schema::create('agent_rating_revisions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id')->index();
            $t->string('event_key', 100)->unique();
            $t->string('request_hash', 64);
            $t->unsignedBigInteger('source_version');
            $t->unsignedBigInteger('author_id');
            $t->json('payload');
            $t->text('reason');
            $t->timestamp('created_at');
        });
        Schema::create('agent_rating_approvals', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('revision_id')->unique();
            $t->unsignedBigInteger('actor_id');
            $t->timestamp('created_at');
        });
        Schema::create('agent_rating_periods', function (Blueprint $t) {
            $t->id();
            $t->string('month', 7);
            $t->unsignedInteger('version');
            $t->json('revision_ids');
            $t->unsignedBigInteger('actor_id');
            $t->text('reason');
            $t->timestamp('created_at');
            $t->unique(['month', 'version']);
        });
        Schema::create('agent_rating_snapshot_contents', function (Blueprint $t) {
            $t->string('hash', 64)->primary();
            $t->json('payload');
        });
        Schema::create('agent_rating_snapshots', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('actor_id')->index();
            $t->string('access_hash', 64);
            $t->string('comparison_key', 64)->index();
            $t->string('month', 7)->index();
            $t->unsignedInteger('elapsed_minute');
            $t->string('content_hash', 64)->index();
            $t->timestamp('created_at')->index();
        });
        Schema::create('agent_rating_state', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->unsignedBigInteger('value')->default(0);
        });
        \Illuminate\Support\Facades\DB::table('agent_rating_state')->insert([['key' => 'facts', 'value' => 0], ['key' => 'synced_at', 'value' => 0]]);
    }

    public function down(): void
    {
        foreach (['agent_rating_snapshots', 'agent_rating_snapshot_contents', 'agent_rating_periods', 'agent_rating_approvals', 'agent_rating_revisions', 'agent_rating_sources', 'agent_rating_state'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

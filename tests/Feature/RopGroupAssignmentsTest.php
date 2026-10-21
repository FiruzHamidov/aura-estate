<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Support\ClientAccess;
use App\Support\RopGroupAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RopGroupAssignmentsTest extends TestCase
{
    private User $admin;
    private User $rop;
    private Branch $branch;
    private BranchGroup $a;
    private BranchGroup $b;
    private BranchGroup $c;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('slug'); $t->timestamps();
        });
        Schema::create('branches', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->boolean('is_hidden')->default(false); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('phone'); $t->unsignedBigInteger('role_id');
            $t->unsignedBigInteger('branch_id')->nullable(); $t->unsignedBigInteger('branch_group_id')->nullable();
            $t->string('status')->default('active'); $t->timestamp('deleted_at')->nullable(); $t->timestamps();
        });
        (require database_path('migrations/2026_03_09_120000_create_branch_groups_table.php'))->up();
        (require database_path('migrations/2026_03_07_100000_create_clients_table.php'))->up();
        Schema::table('clients', fn (Blueprint $t) => $t->unsignedBigInteger('branch_group_id')->nullable());
        (require database_path('migrations/2026_09_08_120000_create_rop_group_access.php'))->up();
        foreach (['rop', 'admin', 'agent', 'mop', 'branch_director', 'marketing'] as $slug) {
            Role::create(['name' => $slug, 'slug' => $slug]);
        }
        $this->branch = Branch::create(['name' => 'One']);
        $this->a = BranchGroup::create(['branch_id' => $this->branch->id, 'name' => 'A']);
        $this->b = BranchGroup::create(['branch_id' => $this->branch->id, 'name' => 'B']);
        $this->c = BranchGroup::create(['branch_id' => $this->branch->id, 'name' => 'C']);
        $this->admin = $this->user('admin');
        $this->rop = $this->user('rop', $this->a);
    }

    private function mergeRights(): void
    {
        Schema::table('roles', fn (Blueprint $t) => $t->json('permissions')->nullable());
        $this->admin->role->forceFill(['permissions' => ['branch_groups.merge', 'branch_groups.scope.all', 'branch_groups.manage.all']])->save();
        Sanctum::actingAs($this->admin->fresh());
    }

    public function test_group_merge_and_archive_preserves_historical_records_and_assignments(): void
    {
        $this->mergeRights();
        (require database_path('migrations/2026_10_16_050000_add_branch_group_archive.php'))->up();
        $agent = $this->user('agent', $this->a);
        $inactive = $this->user('agent', $this->a); $inactive->update(['status' => 'inactive']);
        $this->rop->supervisedGroups()->attach($this->a->id);
        $make = fn ($status) => Client::create(['full_name' => 'Fixture', 'status' => $status,
            'branch_id' => $this->branch->id, 'branch_group_id' => $this->a->id, 'responsible_agent_id' => $agent->id]);
        $active = $make('active'); $history = $make('inactive');
        foreach (['kpi_plans', 'kpi_rop_plans', 'notifications', 'attendance_daily_summaries'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id(); $t->foreignId('branch_group_id')->constrained('branch_groups')->restrictOnDelete();
            });
            DB::table($table)->insert(['branch_group_id' => $this->a->id]);
        }
        $url = '/api/branch-groups/'.$this->a->id;
        $preview = $this->postJson($url.'/merge-preview', ['target_group_id' => $this->b->id])->assertOk()->json();
        $payload = ['target_group_id' => $this->b->id, 'revision' => $preview['revision'],
            'reason' => 'Retire former group', 'archive_source' => true];
        $this->postJson($url.'/merge', $payload)->assertOk()->assertJsonPath('source_archived', true);
        $this->assertDatabaseHas('branch_groups', ['id' => $this->a->id, 'is_archived' => true]);
        $this->assertDatabaseHas('branch_groups', ['id' => $this->b->id, 'is_archived' => false]);
        $this->assertSame($this->b->id, $agent->fresh()->branch_group_id);
        $this->assertSame($this->a->id, $inactive->fresh()->branch_group_id);
        $this->assertSame($this->b->id, $active->fresh()->branch_group_id);
        $this->assertSame($agent->id, $active->fresh()->responsible_agent_id);
        $this->assertSame($this->a->id, $history->fresh()->branch_group_id);
        foreach (['kpi_plans', 'kpi_rop_plans', 'notifications', 'attendance_daily_summaries'] as $table) {
            $this->assertDatabaseHas($table, ['branch_group_id' => $this->a->id]);
            $this->assertDatabaseCount($table, 1);
        }
        $this->assertDatabaseHas('rop_branch_groups', ['rop_id' => $this->rop->id, 'branch_group_id' => $this->a->id]);
        $this->assertDatabaseHas('group_access_audit_logs', ['event' => 'branch_group_archived', 'subject_id' => $this->a->id]);
        $this->getJson('/api/branch-groups')->assertOk()->assertJsonMissing(['name' => $this->a->name]);
        $this->postJson($url.'/merge', $payload)->assertNotFound();
    }

    public function test_archive_only_merge_accepts_history_without_active_work_but_checks_revision(): void
    {
        $this->mergeRights();
        (require database_path('migrations/2026_10_16_050000_add_branch_group_archive.php'))->up();
        $this->rop->update(['status' => 'inactive']);
        $url = '/api/branch-groups/'.$this->a->id;
        $preview = $this->postJson($url.'/merge-preview', ['target_group_id' => $this->b->id])->assertOk()->json();
        $payload = ['target_group_id' => $this->b->id, 'revision' => $preview['revision'], 'reason' => 'Retire inactive team'];
        $this->postJson($url.'/merge', $payload)->assertUnprocessable();
        $this->postJson($url.'/merge', [...$payload, 'archive_source' => true, 'revision' => str_repeat('0', 64)])->assertConflict();
        $this->assertDatabaseHas('branch_groups', ['id' => $this->a->id, 'is_archived' => false]);
        $this->postJson($url.'/merge', [...$payload, 'archive_source' => true])->assertOk();
        $this->assertDatabaseHas('branch_groups', ['id' => $this->a->id, 'is_archived' => true]);
        $this->assertSame($this->a->id, $this->rop->fresh()->branch_group_id);
    }

    public function test_archive_failure_rolls_back_group_transfer_and_audit(): void
    {
        $this->mergeRights();
        (require database_path('migrations/2026_10_16_050000_add_branch_group_archive.php'))->up();
        $agent = $this->user('agent', $this->a);
        $url = '/api/branch-groups/'.$this->a->id;
        $preview = $this->postJson($url.'/merge-preview', ['target_group_id' => $this->b->id])->assertOk()->json();
        DB::unprepared("CREATE TRIGGER refuse_archive BEFORE UPDATE OF is_archived ON branch_groups WHEN NEW.is_archived = 1 BEGIN SELECT RAISE(ABORT, 'archive refused'); END");
        $this->withoutExceptionHandling();
        try {
            $this->postJson($url.'/merge', ['target_group_id' => $this->b->id, 'revision' => $preview['revision'],
                'reason' => 'Retire team atomically', 'archive_source' => true]);
            $this->fail('The archive trigger must reject the transaction.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertStringContainsString('archive refused', $exception->getMessage());
        }
        $this->assertSame($this->a->id, $agent->fresh()->branch_group_id);
        $this->assertDatabaseHas('branch_groups', ['id' => $this->a->id, 'is_archived' => false]);
        $this->assertDatabaseCount('group_access_audit_logs', 0);
    }

    public function test_group_merge_preserves_history_owners_and_supervisor_assignments(): void
    {
        $this->mergeRights();
        $agent = $this->user('agent', $this->a);
        $inactive = $this->user('agent', $this->a); $inactive->update(['status' => 'inactive']);
        $this->rop->supervisedGroups()->attach($this->a->id);
        $make = fn ($status) => Client::create(['full_name' => 'Fixture', 'status' => $status,
            'branch_id' => $this->branch->id, 'branch_group_id' => $this->a->id, 'responsible_agent_id' => $agent->id]);
        $active = $make('active'); $history = $make('inactive');
        $url = '/api/branch-groups/'.$this->a->id;
        $preview = $this->postJson($url.'/merge-preview', ['target_group_id' => $this->b->id])->assertOk()
            ->assertJsonPath('counts.clients', 1)->assertJsonPath('blockers', [])->json();
        $this->assertSame($this->a->id, $agent->fresh()->branch_group_id);
        $payload = ['target_group_id' => $this->b->id, 'revision' => $preview['revision'], 'reason' => 'Consolidate teams'];
        $this->postJson($url.'/merge', $payload)->assertOk();
        $this->assertSame($this->b->id, $agent->fresh()->branch_group_id);
        $this->assertSame($this->a->id, $inactive->fresh()->branch_group_id);
        $this->assertSame($this->b->id, $active->fresh()->branch_group_id);
        $this->assertSame($agent->id, $active->fresh()->responsible_agent_id);
        $this->assertSame($this->a->id, $history->fresh()->branch_group_id);
        $this->assertDatabaseHas('rop_branch_groups', ['rop_id' => $this->rop->id, 'branch_group_id' => $this->a->id]);
        $this->assertDatabaseMissing('rop_branch_groups', ['rop_id' => $this->rop->id, 'branch_group_id' => $this->b->id]);
        $this->assertDatabaseHas('group_access_audit_logs', ['event' => 'branch_groups_merged', 'subject_id' => $this->a->id]);
        $this->assertDatabaseHas('branch_groups', ['id' => $this->a->id]);
        $this->postJson($url.'/merge', $payload)->assertConflict();
    }

    public function test_group_merge_rejects_stale_plan_and_revoked_permission(): void
    {
        $this->mergeRights(); $this->user('agent', $this->a);
        $url = '/api/branch-groups/'.$this->a->id;
        $preview = $this->postJson($url.'/merge-preview', ['target_group_id' => $this->b->id])->assertOk()->json();
        $this->user('agent', $this->a);
        $payload = ['target_group_id' => $this->b->id, 'revision' => $preview['revision'], 'reason' => 'Consolidate teams'];
        $this->postJson($url.'/merge', $payload)->assertConflict();
        $this->admin->role->forceFill(['permissions' => ['branch_groups.scope.all', 'branch_groups.manage.all']])->save();
        Sanctum::actingAs($this->admin->fresh());
        $this->postJson($url.'/merge', $payload)->assertForbidden();
        $this->postJson($url.'/merge-preview', ['target_group_id' => $this->b->id])->assertForbidden();
        $this->assertDatabaseCount('group_access_audit_logs', 0);
    }

    public function test_group_merge_rejects_cross_branch_same_group_and_assigned_scope(): void
    {
        $this->mergeRights();
        $other = Branch::create(['name' => 'Other']);
        $foreign = BranchGroup::create(['name' => 'Foreign', 'branch_id' => $other->id]);
        $url = '/api/branch-groups/'.$this->a->id.'/merge-preview';
        foreach ([$this->a->id, $foreign->id] as $id) $this->postJson($url, ['target_group_id' => $id])->assertUnprocessable();
        $this->rop->role->forceFill(['permissions' => [...$this->rop->role->permissionKeys(), 'branch_groups.merge', 'branch_groups.manage.all', 'branch_groups.scope.all']])->save();
        Sanctum::actingAs($this->rop->fresh());
        $this->postJson($url, ['target_group_id' => $this->b->id])->assertForbidden();
    }

    public function test_group_merge_moves_disputed_work_without_reassigning_outside_employees(): void
    {
        $this->mergeRights();
        $agent = $this->user('agent', $this->a);
        $outside = $this->user('agent', $this->c);
        $make = fn ($group, $owner, $status = 'active') => Client::withoutEvents(fn () => Client::create([
            'full_name' => 'Disputed work', 'status' => $status, 'branch_id' => $this->branch->id,
            'branch_group_id' => $group->id, 'responsible_agent_id' => $owner->id]));
        $foreign = $make($this->c, $agent);
        $outsideOwned = $make($this->a, $outside);
        $unrelated = $make($this->c, $outside);
        $history = $make($this->c, $agent, 'inactive');
        $url = '/api/branch-groups/'.$this->a->id;
        $preview = $this->postJson($url.'/merge-preview', ['target_group_id' => $this->b->id])->assertOk()
            ->assertJsonPath('blockers', [])->assertJsonPath('counts.clients', 2)->json();
        $this->assertCount(2, $preview['warnings']);
        $this->postJson($url.'/merge', ['target_group_id' => $this->b->id, 'revision' => $preview['revision'],
            'reason' => 'Consolidate disputed work'])->assertOk();
        $this->assertSame($this->b->id, $agent->fresh()->branch_group_id);
        $this->assertSame($this->c->id, $outside->fresh()->branch_group_id);
        foreach ([$foreign, $outsideOwned] as $client) {
            $this->assertSame($this->b->id, $client->fresh()->branch_group_id);
            $this->assertSame($client->responsible_agent_id, $client->fresh()->responsible_agent_id);
            $this->assertDatabaseHas('group_access_audit_logs', ['event' => 'record_group_merged', 'subject_id' => $client->id]);
        }
        $this->assertSame($this->c->id, $unrelated->fresh()->branch_group_id);
        $this->assertSame($this->c->id, $history->fresh()->branch_group_id);
    }

    public function test_group_merge_still_blocks_cross_branch_records_without_partial_changes(): void
    {
        $this->mergeRights(); $agent = $this->user('agent', $this->a);
        $branch = Branch::create(['name' => 'Other']);
        $foreign = Client::withoutEvents(fn () => Client::create(['full_name' => 'Foreign branch', 'status' => 'active',
            'branch_id' => $branch->id, 'branch_group_id' => $this->c->id, 'responsible_agent_id' => $agent->id]));
        $url = '/api/branch-groups/'.$this->a->id;
        $preview = $this->postJson($url.'/merge-preview', ['target_group_id' => $this->b->id])->assertOk()->json();
        $this->assertNotEmpty($preview['blockers']);
        $this->postJson($url.'/merge', ['target_group_id' => $this->b->id, 'revision' => $preview['revision'],
            'reason' => 'Consolidate teams'])->assertUnprocessable();
        $this->assertSame($this->a->id, $agent->fresh()->branch_group_id);
        $this->assertSame($this->c->id, $foreign->fresh()->branch_group_id);
        $this->assertDatabaseCount('group_access_audit_logs', 0);
    }

    public function test_group_merge_moves_active_tasks_with_parent_and_keeps_completed_history(): void
    {
        $this->mergeRights(); $agent = $this->user('agent', $this->a);
        Schema::create('crm_tasks', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_group_id')->nullable(); $t->unsignedBigInteger('assignee_id')->nullable();
            $t->string('related_entity_type')->nullable(); $t->unsignedBigInteger('related_entity_id')->nullable();
            $t->string('status')->nullable(); $t->timestamp('completed_at')->nullable(); $t->timestamps();
        });
        $client = Client::create(['full_name' => 'Active', 'status' => 'active', 'branch_id' => $this->branch->id,
            'branch_group_id' => $this->a->id, 'responsible_agent_id' => $agent->id]);
        foreach ([['id' => 1, 'completed_at' => null, 'status' => 'todo'], ['id' => 2, 'completed_at' => now(), 'status' => 'done']] as $task) {
            DB::table('crm_tasks')->insert($task + ['branch_group_id' => $this->a->id, 'assignee_id' => null,
                'related_entity_type' => 'client', 'related_entity_id' => $client->id]);
        }
        $url = '/api/branch-groups/'.$this->a->id;
        $preview = $this->postJson($url.'/merge-preview', ['target_group_id' => $this->b->id])->assertOk()->assertJsonPath('counts.crm_tasks', 1)->json();
        $this->postJson($url.'/merge', ['target_group_id' => $this->b->id, 'revision' => $preview['revision'], 'reason' => 'Merge teams'])->assertOk();
        $this->assertDatabaseHas('crm_tasks', ['id' => 1, 'branch_group_id' => $this->b->id, 'assignee_id' => null]);
        $this->assertDatabaseHas('crm_tasks', ['id' => 2, 'branch_group_id' => $this->a->id]);
    }

    public function test_group_merge_branch_manager_cannot_merge_foreign_groups(): void
    {
        $this->mergeRights();
        $director = $this->user('branch_director');
        $director->role->forceFill(['permissions' => ['branch_groups.merge', 'branch_groups.scope.branch', 'branch_groups.manage.branch']])->save();
        Sanctum::actingAs($director->fresh());
        $this->postJson('/api/branch-groups/'.$this->a->id.'/merge-preview', ['target_group_id' => $this->b->id])->assertOk();
        $other = Branch::create(['name' => 'Other']);
        $foreign = BranchGroup::create(['name' => 'Foreign', 'branch_id' => $other->id]);
        $this->postJson('/api/branch-groups/'.$this->a->id.'/merge-preview', ['target_group_id' => $foreign->id])->assertForbidden();
    }

    public function test_group_merge_keeps_closed_roots_and_hr_work_in_place(): void
    {
        $this->mergeRights(); $agent = $this->user('agent', $this->a);
        foreach (['properties' => 'agent_id', 'leads' => 'responsible_agent_id', 'crm_deals' => 'responsible_agent_id', 'bookings' => 'agent_id'] as $table => $field) {
            Schema::create($table, function (Blueprint $t) use ($table, $field) {
                $t->id(); $t->unsignedBigInteger('branch_group_id')->nullable(); $t->unsignedBigInteger('branch_id')->nullable(); $t->unsignedBigInteger($field)->nullable();
                if ($table === 'properties') {$t->string('moderation_status')->nullable(); $t->integer('moderation_version')->default(0);}
                if (in_array($table, ['leads', 'crm_deals'])) $t->timestamp('closed_at')->nullable();
                if ($table === 'crm_deals') $t->unsignedBigInteger('pipeline_id')->nullable();
                if ($table === 'bookings') $t->timestamp('end_time');
                $t->softDeletes(); $t->timestamps();
            });
            foreach ([1, 2] as $id) {
                $state = match ($table) {
                    'properties' => ['moderation_status' => $id === 1 ? 'approved' : 'sold'],
                    'bookings' => ['end_time' => $id === 1 ? now()->addDay() : now()->subDay()],
                    default => ['closed_at' => $id === 1 ? null : now()],
                };
                DB::table($table)->insert(['id' => $id, 'branch_group_id' => $this->a->id, 'branch_id' => $this->branch->id, $field => $agent->id] + $state);
            }
        }
        Schema::create('crm_deal_pipelines', function (Blueprint $t) {$t->id(); $t->string('code');});
        DB::table('crm_deal_pipelines')->insert(['id' => 1, 'code' => 'hr_recruitment']);
        DB::table('crm_deals')->insert(['id' => 3, 'pipeline_id' => 1, 'responsible_agent_id' => $agent->id]);
        Schema::create('daily_reports', function (Blueprint $t) {$t->id(); $t->unsignedBigInteger('branch_group_id');});
        DB::table('daily_reports')->insert(['id' => 1, 'branch_group_id' => $this->a->id]);
        $url = '/api/branch-groups/'.$this->a->id;
        $preview = $this->postJson($url.'/merge-preview', ['target_group_id' => $this->b->id])->assertOk()->json();
        $this->postJson($url.'/merge', ['target_group_id' => $this->b->id, 'revision' => $preview['revision'], 'reason' => 'Merge teams'])->assertOk();
        foreach (['properties', 'leads', 'crm_deals', 'bookings'] as $table) {
            $this->assertDatabaseHas($table, ['id' => 1, 'branch_group_id' => $this->b->id]);
            $this->assertDatabaseHas($table, ['id' => 2, 'branch_group_id' => $this->a->id]);
        }
        $this->assertDatabaseHas('crm_deals', ['id' => 3, 'branch_group_id' => null]);
        $this->assertDatabaseHas('daily_reports', ['id' => 1, 'branch_group_id' => $this->a->id]);
        $this->assertDatabaseHas('properties', ['id' => 1, 'moderation_version' => 1]);
    }

    private function createPropertyControlTables(): void
    {
        Schema::create('properties', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_group_id')->nullable(); $t->unsignedBigInteger('agent_id')->nullable();
            $t->string('moderation_status')->nullable(); $t->integer('moderation_version')->default(0);
            $t->softDeletes(); $t->timestamps();
        });
        Schema::create('crm_deals', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_group_id')->nullable(); $t->unsignedBigInteger('responsible_agent_id')->nullable();
            $t->string('control_kind')->nullable(); $t->unsignedBigInteger('primary_property_id')->nullable();
            $t->timestamp('closed_at')->nullable(); $t->softDeletes(); $t->timestamps();
        });
        Schema::create('crm_tasks', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_group_id')->nullable(); $t->unsignedBigInteger('assignee_id')->nullable();
            $t->string('related_entity_type')->nullable(); $t->unsignedBigInteger('related_entity_id')->nullable();
            $t->timestamp('completed_at')->nullable(); $t->timestamps();
        });
    }

    public function test_merge_keeps_historical_property_controls_and_tasks_but_moves_active_work(): void
    {
        $this->mergeRights();
        $this->createPropertyControlTables();
        $agent = $this->user('agent', $this->a);
        $states = ['approved', 'archived', 'sold', 'rented', 'sold_by_owner', 'deleted', 'soft_deleted'];
        foreach ($states as $index => $state) {
            $id = $index + 1;
            DB::table('properties')->insert(['id' => $id, 'branch_group_id' => $this->a->id, 'agent_id' => $agent->id,
                'moderation_status' => $state === 'soft_deleted' ? 'approved' : $state,
                'deleted_at' => $state === 'soft_deleted' ? now() : null]);
            DB::table('crm_deals')->insert(['id' => $id, 'branch_group_id' => $this->a->id,
                'responsible_agent_id' => $agent->id, 'control_kind' => 'security_property_closure', 'primary_property_id' => $id]);
            DB::table('crm_tasks')->insert(['id' => $id, 'branch_group_id' => $this->a->id, 'assignee_id' => $agent->id,
                'related_entity_type' => 'deal', 'related_entity_id' => $id]);
        }
        $url = '/api/branch-groups/'.$this->a->id;
        $preview = $this->postJson($url.'/merge-preview', ['target_group_id' => $this->b->id])->assertOk()
            ->assertJsonPath('blockers', [])->assertJsonPath('counts.properties', 1)
            ->assertJsonPath('counts.crm_deals', 1)->assertJsonPath('counts.crm_tasks', 1)->json();
        $this->postJson($url.'/merge', ['target_group_id' => $this->b->id, 'revision' => $preview['revision'],
            'reason' => 'Consolidate current work'])->assertOk();
        $this->assertSame($this->b->id, $agent->fresh()->branch_group_id);
        foreach ($states as $index => $state) {
            $id = $index + 1;
            $group = $id === 1 ? $this->b->id : $this->a->id;
            foreach (['properties', 'crm_deals', 'crm_tasks'] as $table) {
                $this->assertDatabaseHas($table, ['id' => $id, 'branch_group_id' => $group]);
                if ($id > 1) $this->assertDatabaseMissing('group_access_audit_logs', [
                    'event' => 'record_group_merged', 'subject_type' => $table, 'subject_id' => $id]);
            }
            $this->assertDatabaseHas('crm_deals', ['id' => $id, 'closed_at' => null, 'responsible_agent_id' => $agent->id]);
        }
    }

    public function test_merge_still_blocks_controls_with_missing_or_foreign_properties(): void
    {
        $this->mergeRights();
        $this->createPropertyControlTables();
        $agent = $this->user('agent', $this->a);
        DB::table('properties')->insert(['id' => 1, 'branch_group_id' => $this->c->id, 'moderation_status' => 'archived']);
        foreach ([1, 999] as $id) DB::table('crm_deals')->insert(['id' => $id,
            'branch_group_id' => $this->a->id, 'control_kind' => 'security_property_closure', 'primary_property_id' => $id]);
        $url = '/api/branch-groups/'.$this->a->id;
        $preview = $this->postJson($url.'/merge-preview', ['target_group_id' => $this->b->id])->assertOk()->json();
        $this->assertCount(2, $preview['blockers']);
        $this->postJson($url.'/merge', ['target_group_id' => $this->b->id, 'revision' => $preview['revision'],
            'reason' => 'Consolidate current work'])->assertUnprocessable();
        $this->assertSame($this->a->id, $agent->fresh()->branch_group_id);
        $this->assertDatabaseCount('group_access_audit_logs', 0);
    }

    public function test_archived_groups_disappear_from_choices_but_keep_history_and_can_be_restored(): void
    {
        $this->mergeRights();
        (require database_path('migrations/2026_10_16_050000_add_branch_group_archive.php'))->up();
        $employee = $this->user('agent', $this->a);
        $client = Client::create(['full_name' => 'Historical client', 'status' => 'inactive',
            'branch_id' => $this->branch->id, 'branch_group_id' => $this->a->id, 'responsible_agent_id' => $employee->id]);
        $url = '/api/branch-groups/'.$this->a->id;
        $this->patchJson($url, ['is_archived' => true])->assertOk()->assertJsonPath('is_archived', true);
        $this->getJson('/api/branch-groups')->assertOk()->assertJsonMissing(['name' => 'A']);
        $this->assertNull(BranchGroup::find($this->a->id));
        $this->assertSame('A', $client->fresh()->branchGroup->name);
        $this->assertSame('A', $employee->fresh()->branchGroup->name);
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'branch_group_id' => $this->a->id]);
        $this->assertDatabaseHas('group_access_audit_logs', ['event' => 'branch_group_archived', 'subject_id' => $this->a->id]);
        $this->getJson('/api/branch-groups?include_archived=1')->assertOk()->assertJsonFragment(['name' => 'A', 'is_archived' => true]);
        $this->postJson($url.'/merge-preview', ['target_group_id' => $this->b->id])->assertUnprocessable();
        $this->patchJson($url, ['is_archived' => false])->assertOk()->assertJsonPath('is_archived', false);
        $this->getJson('/api/branch-groups')->assertOk()->assertJsonFragment(['name' => 'A']);
        Sanctum::actingAs($employee);
        $this->patchJson($url, ['is_archived' => true])->assertForbidden();
        $this->getJson('/api/branch-groups?include_archived=1')->assertForbidden();
    }

    public function test_archive_migration_targets_only_verified_ba_pesh_group_without_deleting_data(): void
    {
        $this->a->update(['name' => 'Ба пеш']);
        $employee = $this->user('agent', $this->a);
        $migration = require database_path('migrations/2026_10_16_050000_add_branch_group_archive.php');
        $migration->up();
        $this->assertDatabaseHas('branch_groups', ['id' => $this->a->id, 'is_archived' => true]);
        $this->assertDatabaseHas('branch_groups', ['id' => $this->b->id, 'is_archived' => false]);
        $this->assertDatabaseHas('users', ['id' => $employee->id, 'branch_group_id' => $this->a->id]);
    }

    private function user(string $role, ?BranchGroup $group = null): User
    {
        return User::create(['name' => $role, 'phone' => (string) random_int(900000000, 999999999),
            'role_id' => Role::where('slug', $role)->value('id'), 'branch_id' => $group?->branch_id ?? $this->branch->id,
            'branch_group_id' => $group?->id, 'status' => 'active']);
    }

    public function test_configured_assignment_reader_cannot_write_until_granted_and_revocation_takes_effect(): void
    {
        Schema::table('roles', fn (Blueprint $t) => $t->json('permissions')->nullable());
        $actor = $this->user('marketing');
        $role = $actor->role;
        $role->forceFill(['permissions' => ['rop_groups.scope.branch']])->save();
        Sanctum::actingAs($actor->fresh());
        $this->getJson($this->url())->assertOk();
        $payload = ['branch_group_ids' => [$this->a->id], 'version' => 0];
        $this->putJson($this->url(), $payload)->assertForbidden();
        $role->forceFill(['permissions' => ['rop_groups.scope.branch', 'rop_groups.manage']])->save();
        Sanctum::actingAs($actor->fresh());
        $this->putJson($this->url(), $payload)->assertOk()->assertJsonPath('version', 1);
        $this->assertDatabaseCount('group_access_audit_logs', 1);
        $role->forceFill(['permissions' => []])->save();
        Sanctum::actingAs($actor->fresh());
        $this->getJson($this->url())->assertForbidden();
        $this->putJson($this->url(), ['branch_group_ids' => [], 'version' => 1])->assertForbidden();
        $this->assertDatabaseCount('rop_branch_groups', 1);
    }

    public function test_review_queue_grants_are_branch_scoped_and_revocable(): void
    {
        Schema::table('roles', fn (Blueprint $t) => $t->json('permissions')->nullable());
        (require database_path('migrations/2026_09_09_030000_create_group_access_review_items.php'))->up();
        $other = Branch::create(['name' => 'Other']);
        foreach ([$this->branch->id, $other->id] as $id) DB::table('group_access_review_items')->insert([
            'source_table' => 'clients', 'source_id' => $id, 'branch_id' => $id, 'reason' => 'unclassified', 'detected_at' => now(),
        ]);
        $actor = $this->user('marketing');
        $role = $actor->role;
        $role->forceFill(['permissions' => ['group_access_review.scope.branch']])->save();
        Sanctum::actingAs($actor->fresh());
        $this->getJson('/api/group-access/review')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.branch_id', $this->branch->id);
        $role->forceFill(['permissions' => ['group_access_review.scope.all']])->save();
        Sanctum::actingAs($actor->fresh());
        $this->getJson('/api/group-access/review')->assertOk()->assertJsonCount(2, 'data');
        $role->forceFill(['permissions' => []])->save();
        Sanctum::actingAs($actor->fresh());
        $this->getJson('/api/group-access/review')->assertForbidden();
    }

    public function test_rop_can_edit_employee_details_only_in_assigned_groups(): void
    {
        Role::create(['name' => 'intern', 'slug' => 'intern']);
        $this->rop->supervisedGroups()->attach([$this->a->id, $this->b->id]);
        Sanctum::actingAs($this->rop);
        foreach (['agent', 'mop', 'intern'] as $role) {
            foreach ([$this->a, $this->b] as $group) {
                $employee = $this->user($role, $group);
                $this->patchJson('/api/user/'.$employee->id, ['name' => 'Updated employee',
                    'role_id' => $employee->role_id, 'branch_id' => $employee->branch_id,
                    'branch_group_id' => $employee->branch_group_id])->assertOk();
                $this->assertSame('Updated employee', $employee->fresh()->name);
            }
        }
        foreach ([$this->user('agent', $this->c), $this->admin, $this->rop] as $foreign) {
            $this->patchJson('/api/user/'.$foreign->id, ['name' => 'Forbidden'])->assertNotFound();
            $this->assertNotSame('Forbidden', $foreign->fresh()->name);
        }
    }

    public function test_rop_profile_edit_cannot_change_organization_or_bypass_dismissal(): void
    {
        $this->rop->supervisedGroups()->attach([$this->a->id, $this->b->id]);
        $employee = $this->user('agent', $this->a);
        Sanctum::actingAs($this->rop);
        foreach ([['role_id' => $this->admin->role_id],
            ['branch_group_id' => $this->b->id], ['branch_group_id' => null], ['status' => 'inactive']] as $changes) {
            $this->patchJson('/api/user/'.$employee->id, ['name' => 'Forbidden'] + $changes)->assertForbidden();
            $this->assertSame('agent', $employee->fresh()->name);
        }
        $employee->update(['status' => 'inactive']);
        $this->patchJson('/api/user/'.$employee->id, ['name' => 'Inactive employee'])->assertOk();
        $this->rop->supervisedGroups()->detach();
        $this->patchJson('/api/user/'.$employee->id, ['name' => 'Revoked'])->assertNotFound();
    }

    public function test_rop_changes_team_roles_and_transfers_employee_with_reviewed_records(): void
    {
        Role::create(['name' => 'Intern', 'slug' => 'intern']);
        $this->rop->supervisedGroups()->attach($this->a->id);
        $receiver = $this->user('rop', $this->b);
        $receiver->supervisedGroups()->attach($this->b->id);
        $employee = $this->user('intern', $this->a);
        $replacement = $this->user('agent', $this->a);
        Sanctum::actingAs($this->rop);
        foreach (['agent', 'mop', 'intern', 'agent'] as $role) {
            $this->patchJson('/api/user/'.$employee->id, ['role_id' => Role::where('slug', $role)->value('id')])->assertOk();
            $this->assertSame($role, $employee->fresh()->role->slug);
        }
        $make = fn ($name, $status = 'active') => Client::create(['full_name' => $name, 'status' => $status,
            'branch_id' => $this->branch->id, 'branch_group_id' => $this->a->id, 'responsible_agent_id' => $employee->id]);
        $move = $make('Move'); $retain = $make('Retain'); $history = $make('History', 'inactive');
        $url = '/api/users/'.$employee->id.'/group-transfer';
        $preview = $this->getJson($url)->assertOk()->json();
        $this->getJson('/api/employee-transfer-destinations')->assertOk()->assertJsonCount(2, 'data');
        $payload = ['branch_group_id' => $this->b->id, 'revision' => $preview['revision'], 'reason' => 'Transfer to another ROP',
            'records' => [['type' => 'clients', 'id' => $move->id, 'action' => 'move'],
                ['type' => 'clients', 'id' => $retain->id, 'action' => 'retain', 'responsible_user_id' => $replacement->id]]];
        $this->postJson($url, [...$payload, 'branch_group_id' => $this->c->id])->assertForbidden();
        $this->postJson($url, [...$payload, 'records' => []])->assertUnprocessable();
        $this->assertSame($this->a->id, (int) $employee->fresh()->branch_group_id);
        $this->postJson($url, $payload)->assertOk();
        $this->assertSame($this->b->id, (int) $move->fresh()->branch_group_id);
        $this->assertSame($replacement->id, (int) $retain->fresh()->responsible_agent_id);
        $this->assertSame($this->a->id, (int) $history->fresh()->branch_group_id);
        $this->patchJson('/api/user/'.$employee->id, ['name' => 'Forbidden'])->assertNotFound();
        $this->getJson($url)->assertNotFound();
        Sanctum::actingAs($receiver);
        $this->patchJson('/api/user/'.$employee->id, ['name' => 'New team'])->assertOk();
    }

    public function test_rop_can_transfer_intern_but_not_mop_or_foreign_employee(): void
    {
        Role::create(['name' => 'Intern', 'slug' => 'intern']);
        $this->rop->supervisedGroups()->attach($this->a->id);
        $receiver = $this->user('rop', $this->b);
        $receiver->supervisedGroups()->attach($this->b->id);
        Sanctum::actingAs($this->rop);
        foreach (['intern', 'mop'] as $role) {
            $employee = $this->user($role, $this->a);
            $url = '/api/users/'.$employee->id.'/group-transfer';
            $preview = $this->getJson($url)->assertOk()->json();
            $result = $this->postJson($url, ['branch_group_id' => $this->b->id, 'revision' => $preview['revision'], 'reason' => 'Transfer employee', 'records' => []]);
            $role === 'intern' ? $result->assertOk() : $result->assertForbidden();
        }
        $foreign = $this->user('agent', $this->b);
        $this->getJson('/api/users/'.$foreign->id.'/group-transfer')->assertNotFound();
        $this->rop->supervisedGroups()->detach();
        $own = $this->user('agent', $this->a);
        $this->getJson('/api/users/'.$own->id.'/group-transfer')->assertNotFound();
    }

    public function test_custom_role_can_use_assigned_groups_and_removing_policy_restores_module_scope(): void
    {
        Schema::table('roles', fn (Blueprint $t) => $t->json('permissions')->nullable());
        $role = Role::create(['name' => 'Team reviewer', 'slug' => 'team_reviewer']);
        $rights = ['data.scope.assigned_groups', 'clients.scope.all', 'users.group_transfer'];
        $role->forceFill(['permissions' => $rights])->save();
        $actor = $this->user('team_reviewer');
        $own = Client::create(['full_name' => 'Own', 'branch_id' => $this->branch->id, 'branch_group_id' => $this->a->id]);
        $foreign = Client::create(['full_name' => 'Foreign', 'branch_id' => $this->branch->id, 'branch_group_id' => $this->b->id]);
        $access = app(ClientAccess::class);
        $this->assertSame(0, $access->visibleQuery($actor->fresh())->count());
        Sanctum::actingAs($this->admin);
        $url = '/api/users/'.$actor->id.'/supervised-groups';
        $this->putJson($url, ['branch_group_ids' => [$this->a->id], 'version' => 0])->assertOk();
        $this->assertSame([$own->id], $access->visibleQuery($actor->fresh())->pluck('clients.id')->all());
        Sanctum::actingAs($actor->fresh());
        $this->getJson('/api/employee-transfer-destinations')->assertOk()->assertJsonCount(1, 'data');
        $this->putJson($url, ['branch_group_ids' => [$this->a->id, $this->b->id], 'version' => 1])->assertForbidden();
        $role->forceFill(['permissions' => ['clients.scope.all', 'users.group_transfer']])->save();
        $this->assertEqualsCanonicalizing([$own->id, $foreign->id], $access->visibleQuery($actor->fresh())->pluck('clients.id')->all());
        $this->assertFalse(Role::query()->usingAssignedGroups()->whereKey($role->id)->exists());
        $role->forceFill(['permissions' => $rights])->save();
        $this->assertTrue(Role::query()->usingAssignedGroups()->whereKey($role->id)->exists());
        $this->assertSame([$own->id], $access->visibleQuery($actor->fresh())->pluck('clients.id')->all());
        $this->assertDatabaseHas('rop_branch_groups', ['rop_id' => $actor->id, 'branch_group_id' => $this->a->id]);
    }

    public function test_custom_team_employee_can_be_edited_and_transferred_only_with_target_policies(): void
    {
        Schema::table('roles', fn (Blueprint $t) => $t->json('permissions')->nullable());
        $role = Role::create(['name' => 'Custom employee', 'slug' => 'custom_employee']);
        $rights = ['users.subject.team', 'users.subject.group_transfer'];
        $role->forceFill(['permissions' => $rights])->save();
        $employee = $this->user('custom_employee', $this->a);
        $this->rop->supervisedGroups()->attach($this->a->id);
        $receiver = $this->user('rop', $this->b);
        $receiver->supervisedGroups()->attach($this->b->id);
        Sanctum::actingAs($this->rop);
        $this->patchJson('/api/user/'.$employee->id, ['name' => 'Edited custom employee', 'role_id' => $role->id])->assertOk();
        $url = '/api/users/'.$employee->id.'/group-transfer';
        $preview = $this->getJson($url)->assertOk()->json();
        $plan = ['branch_group_id' => $this->b->id, 'revision' => $preview['revision'], 'reason' => 'Reviewed transfer', 'records' => []];
        $this->postJson($url, $plan)->assertForbidden();
        $role->forceFill(['permissions' => []])->save();
        $this->patchJson('/api/user/'.$employee->id, ['name' => 'Denied'])->assertNotFound();
        $this->postJson($url, $plan)->assertUnprocessable();
        $this->assertSame($this->a->id, $employee->fresh()->branch_group_id);
        $role->forceFill(['permissions' => [...$rights, 'users.subject.group_transfer_across']])->save();
        $this->postJson($url, $plan)->assertOk();
        $this->assertSame($this->b->id, $employee->fresh()->branch_group_id);
    }

    private function url(): string
    {
        return '/api/users/'.$this->rop->id.'/supervised-groups';
    }

    public function test_rop_write_error_responses_and_deadlocks_roll_back_the_entire_request(): void
    {
        $this->rop->supervisedGroups()->attach($this->a->id);
        Sanctum::actingAs($this->rop);
        $before = $this->a->name;
        foreach ([403, 404, 422, 409] as $status) {
            \Route::post('/api/test-rop-rollback-'.$status, function () use ($status) {
                DB::table('branch_groups')->where('id', $this->a->id)->update(['name' => 'Partial write']);
                if ($status === 409) throw new \Illuminate\Database\DeadlockException('Synthetic concurrency conflict');
                return response()->json(['code' => 'TEST_REJECTION'], $status);
            })->middleware('api');
            $response = $this->postJson('/api/test-rop-rollback-'.$status)->assertStatus($status);
            if ($status === 409) $response->assertJsonPath('code', 'CONCURRENT_GROUP_CHANGE')->assertJsonStructure(['trace_id']);
            $this->assertSame($before, $this->a->fresh()->name);
            $this->assertSame(0, DB::transactionLevel());
        }
    }

    public function test_rop_chat_requires_own_session_even_for_a_supervised_employee(): void
    {
        (require database_path('migrations/2025_10_01_000001_create_chat_sessions_table.php'))->up();
        (require database_path('migrations/2025_10_01_000002_create_chat_messages_table.php'))->up();
        $this->rop->supervisedGroups()->attach($this->a->id);
        $employee = $this->user('agent', $this->a);
        $own = \App\Models\ChatSession::create(['user_id' => $this->rop->id]);
        $foreign = \App\Models\ChatSession::create(['user_id' => $employee->id]);
        foreach ([$own, $foreign] as $session) {
            \App\Models\ChatMessage::create(['chat_session_id' => $session->id, 'role' => 'user', 'content' => 'Private '.$session->id]);
        }
        Sanctum::actingAs($this->rop);
        $this->getJson('/api/chat/history?session_id='.$own->session_uuid)->assertOk()->assertJsonCount(1, 'messages');
        $this->getJson('/api/chat/history?session_id='.$foreign->session_uuid)->assertNotFound();
        $this->getJson('/api/chat/history?session_id=missing-session')->assertNotFound();
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $this->postJson('/api/chat', ['message' => 'Read history', 'session_id' => $foreign->session_uuid])->assertNotFound();
        $this->assertDatabaseCount('chat_messages', 2);
        // Removing authentication must not expose a registered user's history.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/chat/history?session_id='.$foreign->session_uuid)->assertNotFound();
        $guest = \App\Models\ChatSession::create([]);
        $this->getJson('/api/chat/history?session_id='.$guest->session_uuid)->assertOk();
    }

    public function test_assignment_import_dry_run_and_apply_use_the_same_atomic_validation(): void
    {
        $second = $this->user('rop');
        $service = app(\App\Services\GroupAccess\RopGroupAssignments::class);
        $rows = [
            ['rop_id' => $this->rop->id, 'branch_group_ids' => [$this->a->id, $this->b->id], 'version' => 0],
            ['rop_id' => $second->id, 'branch_group_ids' => [$this->b->id, $this->c->id], 'version' => 0],
        ];
        $expected = [['rop_id' => $this->rop->id, 'version' => 1], ['rop_id' => $second->id, 'version' => 1]];
        $this->assertSame($expected, $service->import($this->admin, $rows, false));
        $this->assertDatabaseCount('rop_branch_groups', 0);
        $this->assertDatabaseCount('group_access_audit_logs', 0);
        $this->assertSame(0, (int) $this->rop->fresh()->access_scope_version);
        $this->assertSame($expected, $service->import($this->admin, $rows, true));
        $this->assertDatabaseCount('rop_branch_groups', 4);
        $this->assertDatabaseCount('group_access_audit_logs', 2);
        $this->assertSame(1, (int) $second->fresh()->access_scope_version);
    }

    public function test_invalid_later_import_row_rolls_back_assignments_versions_and_audit(): void
    {
        $second = $this->user('rop');
        $foreignBranch = Branch::create(['name' => 'Foreign']);
        $foreign = BranchGroup::create(['branch_id' => $foreignBranch->id, 'name' => 'Foreign']);
        $service = app(\App\Services\GroupAccess\RopGroupAssignments::class);
        foreach ([false, true] as $apply) {
            foreach ([
                ['branch_group_ids' => [$foreign->id], 'version' => 0, 'status' => 403],
                ['branch_group_ids' => [$this->c->id, $this->c->id], 'version' => 0, 'status' => 422],
                ['branch_group_ids' => [999999], 'version' => 0, 'status' => 422],
                ['branch_group_ids' => [$this->c->id], 'version' => 7, 'status' => 409],
            ] as $invalid) {
                try {
                    $service->import($this->admin, [
                        ['rop_id' => $this->rop->id, 'branch_group_ids' => [$this->a->id], 'version' => 0],
                        ['rop_id' => $second->id, 'branch_group_ids' => $invalid['branch_group_ids'], 'version' => $invalid['version']],
                    ], $apply);
                    $this->fail('Invalid import was accepted');
                } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
                    $this->assertSame($invalid['status'], $exception->getStatusCode());
                }
                $this->assertDatabaseCount('rop_branch_groups', 0);
                $this->assertDatabaseCount('group_access_audit_logs', 0);
                $this->assertSame(0, (int) $this->rop->fresh()->access_scope_version);
                $this->assertSame(0, (int) $second->fresh()->access_scope_version);
                $this->assertSame(0, DB::transactionLevel());
            }
        }
    }

    public function test_import_rejects_duplicate_subjects_and_malformed_group_ids_before_writing(): void
    {
        $row = ['rop_id' => $this->rop->id, 'branch_group_ids' => [$this->a->id], 'version' => 0];
        foreach ([[$row, $row], [array_replace($row, ['branch_group_ids' => ['1oops']])],
            [array_replace($row, ['branch_group_ids' => ['unexpected' => $this->a->id]])]] as $rows) {
            try {
                app(\App\Services\GroupAccess\RopGroupAssignments::class)->import($this->admin, $rows, true);
                $this->fail('Invalid format was accepted');
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
            $this->assertDatabaseCount('rop_branch_groups', 0);
            $this->assertDatabaseCount('group_access_audit_logs', 0);
        }
    }

    public function test_unclassified_internal_story_and_motivation_routes_are_closed_before_binding(): void
    {
        Sanctum::actingAs($this->rop);
        $tested = 0;
        foreach (\Route::getRoutes() as $route) {
            $action = $route->getActionName();
            $controller = explode('@', class_basename($action))[0];
            if (! in_array($controller, ['StoryController', 'AdminStoryController', 'MotivationController'], true)) continue;
            if (in_array(explode('@', $action)[1] ?? '', ['feed', 'show', 'trackView', 'rules'], true)) continue;
            $url = '/'.preg_replace('/\{[^}]+\}/', '999999', $route->uri());
            $this->json($route->methods()[0], $url)->assertForbidden()->assertJsonPath('code', 'FORBIDDEN_ACTION');
            $tested++;
        }
        $this->assertSame(16, $tested);
        $this->postJson('/api/chat/feedback')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN_ACTION');
        foreach (['/api/kpi/integrations/status', '/api/kpi/ops/integrations/status', '/api/kpi/telegram-reports/config', '/api/kpi/ops/telegram/config'] as $path) {
            $this->getJson($path)->assertForbidden()->assertJsonPath('code', 'FORBIDDEN_ACTION');
        }
        foreach (['/api/kpi/telegram-reports/config', '/api/kpi/ops/telegram/config'] as $path) {
            $this->patchJson($path, ['daily_enabled' => true])->assertForbidden()->assertJsonPath('code', 'FORBIDDEN_ACTION');
        }

    }

    public function test_rop_cannot_mutate_role_or_branch_catalogs_even_in_own_scope(): void
    {
        $this->rop->supervisedGroups()->attach($this->a->id);
        Sanctum::actingAs($this->rop);
        foreach (['roles' => $this->rop->role_id, 'branches' => $this->branch->id] as $catalog => $id) {
            $this->getJson('/api/'.$catalog)->assertOk();
            foreach (['POST' => '/api/'.$catalog, 'PATCH' => '/api/'.$catalog.'/'.$id, 'DELETE' => '/api/'.$catalog.'/'.$id] as $method => $url) {
                $this->json($method, $url, ['name' => 'Changed', 'slug' => 'admin'])->assertForbidden()->assertJsonPath('code', 'FORBIDDEN_ACTION');
            }
        }
        $this->assertSame('rop', $this->rop->fresh()->role->slug);
        $this->assertSame('One', $this->branch->fresh()->name);
        $this->assertDatabaseCount('rop_branch_groups', 1);
    }

    public function test_branch_deletion_cannot_cascade_away_groups_with_historical_data(): void
    {
        $otherBranch = Branch::create(['name' => 'Historical branch']);
        $group = BranchGroup::create(['name' => 'History', 'branch_id' => $otherBranch->id]);
        Schema::create('attendance_events', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('branch_group_id')->nullable();
        });
        DB::table('attendance_events')->insert(['branch_group_id' => $group->id]);
        Sanctum::actingAs($this->admin);
        $this->deleteJson('/api/branches/'.$otherBranch->id)->assertConflict()->assertJsonPath('code', 'GROUP_HAS_ASSIGNED_DATA');
        $this->assertDatabaseHas('branches', ['id' => $otherBranch->id]);
        $this->assertDatabaseHas('branch_groups', ['id' => $group->id, 'branch_id' => $otherBranch->id]);
        $this->assertDatabaseCount('attendance_events', 1);
    }

    public function test_deferred_assignments_release_never_grants_groups_or_bypasses_classification(): void
    {
        (require database_path('migrations/2026_09_09_030000_create_group_access_review_items.php'))->up();
        $this->artisan('rop-groups:prepare', ['--check' => true])->assertFailed();
        $this->artisan('rop-groups:prepare', ['--check' => true, '--allow-unassigned-rops' => true])->assertSuccessful();
        $this->assertDatabaseCount('rop_branch_groups', 0);
        $this->assertSame([], app(\App\Support\RopGroupAccess::class)->groupIds($this->rop));
        DB::table('clients')->insert(['full_name' => 'Unclassified', 'branch_id' => $this->branch->id]);
        $this->artisan('rop-groups:prepare', ['--check' => true, '--allow-unassigned-rops' => true])->assertFailed();
        $this->assertDatabaseCount('rop_branch_groups', 0);
    }

    public function test_preparation_review_queue_is_explicit_branch_scoped_and_preserves_resolution_history(): void
    {
        (require database_path('migrations/2026_09_09_030000_create_group_access_review_items.php'))->up();
        $own = DB::table('clients')->insertGetId(['full_name' => 'Private unresolved', 'branch_id' => $this->branch->id]);
        $otherBranch = Branch::create(['name' => 'Other']);
        $other = DB::table('clients')->insertGetId(['full_name' => 'Other private', 'branch_id' => $otherBranch->id]);
        $this->artisan('rop-groups:prepare', ['--check' => true])->assertFailed();
        $this->assertDatabaseCount('group_access_review_items', 0);
        // Publishing the queue cannot substitute missing active ROP assignments.
        $this->rop->supervisedGroups()->detach();
        $this->artisan('rop-groups:prepare', ['--queue-review' => true, '--check' => true])->assertFailed();
        $this->rop->supervisedGroups()->attach($this->a->id);
        $this->artisan('rop-groups:prepare', ['--queue-review' => true, '--check' => true])->assertSuccessful();
        $this->assertDatabaseCount('group_access_review_items', 2);
        Sanctum::actingAs($this->rop);
        $this->getJson('/api/group-access/review')->assertForbidden();
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/group-access/review')->assertOk()->assertJsonPath('total', 2)->assertDontSee('Private unresolved');
        $director = $this->user('branch_director', $this->a);
        Sanctum::actingAs($director);
        $this->getJson('/api/group-access/review')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.source_id', $own);
        DB::table('clients')->where('id', $own)->update(['branch_group_id' => $this->a->id]);
        $this->artisan('rop-groups:prepare', ['--queue-review' => true, '--check' => true])->assertSuccessful();
        $this->getJson('/api/group-access/review')->assertOk()->assertJsonPath('total', 0);
        $this->assertDatabaseCount('group_access_review_items', 2);
        $this->assertNotNull(DB::table('group_access_review_items')->where('source_id', $own)->value('resolved_at'));
        $this->assertDatabaseHas('clients', ['id' => $other, 'branch_group_id' => null]);
    }

    public function test_preparation_inherits_control_group_from_property_and_preserves_conflicts(): void
    {
        Schema::create('properties', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id')->nullable(); $t->unsignedBigInteger('branch_group_id')->nullable();
            $t->unsignedBigInteger('agent_id')->nullable();
        });
        Schema::create('crm_deals', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id')->nullable(); $t->unsignedBigInteger('branch_group_id')->nullable();
            $t->unsignedBigInteger('responsible_agent_id')->nullable(); $t->unsignedBigInteger('primary_property_id')->nullable();
            $t->string('control_kind')->nullable();
        });
        $property = DB::table('properties')->insertGetId(['branch_id' => $this->branch->id, 'branch_group_id' => $this->a->id]);
        $control = DB::table('crm_deals')->insertGetId(['primary_property_id' => $property, 'control_kind' => 'security_property_closure', 'responsible_agent_id' => $this->admin->id]);
        $conflict = DB::table('crm_deals')->insertGetId(['primary_property_id' => $property, 'control_kind' => 'security_property_closure', 'branch_group_id' => $this->b->id]);
        $unknown = DB::table('crm_deals')->insertGetId(['control_kind' => 'security_property_closure', 'responsible_agent_id' => $this->user('agent', $this->a)->id]);
        $this->artisan('rop-groups:prepare')->assertSuccessful();
        $this->assertDatabaseHas('crm_deals', ['id' => $control, 'branch_group_id' => null]);
        $this->assertDatabaseCount('group_access_audit_logs', 0);
        $this->artisan('rop-groups:prepare', ['--apply' => true])->assertSuccessful();
        $this->assertDatabaseHas('crm_deals', ['id' => $control, 'branch_group_id' => $this->a->id, 'branch_id' => $this->branch->id, 'responsible_agent_id' => $this->admin->id]);
        $this->assertDatabaseHas('crm_deals', ['id' => $conflict, 'branch_group_id' => $this->b->id]);
        $this->assertDatabaseHas('crm_deals', ['id' => $unknown, 'branch_group_id' => null]);
        $this->assertDatabaseCount('group_access_audit_logs', 1);
        $this->artisan('rop-groups:prepare', ['--apply' => true])->assertSuccessful();
        $this->assertDatabaseCount('group_access_audit_logs', 1);
    }

    public function test_daily_auto_metrics_and_debug_ids_are_limited_to_the_report_group(): void
    {
        Schema::create('properties', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id')->nullable(); $t->unsignedBigInteger('branch_group_id')->nullable();
            $t->unsignedBigInteger('created_by'); $t->unsignedBigInteger('agent_id'); $t->unsignedBigInteger('sale_user_id');
            $t->string('moderation_status'); $t->timestamp('sold_at'); $t->timestamps();
        });
        Schema::create('bookings', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('agent_id'); $t->unsignedBigInteger('branch_group_id')->nullable(); $t->timestamp('start_time');
        });
        Schema::create('crm_task_types', function (Blueprint $t) { $t->id(); $t->string('code'); });
        Schema::create('crm_tasks', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('assignee_id'); $t->unsignedBigInteger('task_type_id');
            $t->unsignedBigInteger('branch_group_id')->nullable(); $t->string('status'); $t->timestamp('completed_at');
        });
        $employee = $this->user('agent', $this->a);
        $this->rop->supervisedGroups()->attach([$this->a->id, $this->b->id]);
        $types = [];
        foreach (['CALL', 'AD_CREATE'] as $code) $types[] = DB::table('crm_task_types')->insertGetId(['code' => $code]);
        foreach ([$this->a->id, $this->b->id, $this->c->id, null] as $group) {
            DB::table('properties')->insert(['branch_id' => $this->branch->id, 'branch_group_id' => $group,
                'created_by' => $employee->id, 'agent_id' => $employee->id, 'sale_user_id' => $employee->id,
                'moderation_status' => 'sold', 'sold_at' => '2026-05-01 10:00:00', 'created_at' => '2026-05-01 10:00:00']);
            DB::table('clients')->insert(['full_name' => 'Metric client', 'branch_id' => $this->branch->id,
                'branch_group_id' => $group, 'created_by' => $employee->id, 'created_at' => '2026-05-01 10:00:00']);
            DB::table('bookings')->insert(['agent_id' => $employee->id, 'branch_group_id' => $group, 'start_time' => '2026-05-01 10:00:00']);
            foreach ($types as $type) DB::table('crm_tasks')->insert(['assignee_id' => $employee->id,
                'branch_group_id' => $group, 'task_type_id' => $type, 'status' => 'done', 'completed_at' => '2026-05-01 10:00:00']);
        }
        $service = app(\App\Services\DailyReportService::class);
        Sanctum::actingAs($this->rop);
        foreach ($service->autoMetrics($employee, '2026-05-01') as $metric => $value) $this->assertEquals(1, $value, $metric);
        $debug = $service->autoMetricsDebug($employee, '2026-05-01');
        foreach (['object_ids', 'booking_ids', 'sales_property_ids'] as $key) $this->assertSame([1], $debug[$key]);
        $this->rop->supervisedGroups()->detach();
        foreach ($service->autoMetrics($employee, '2026-05-01') as $value) $this->assertEquals(0, $value);
        Sanctum::actingAs($this->admin);
        foreach ($service->autoMetrics($employee, '2026-05-01') as $metric => $value) $this->assertEquals(4, $value, $metric);
        Schema::drop('crm_tasks');
        Schema::create('crm_audit_logs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('actor_id'); $t->string('event'); $t->timestamps();
        });
        DB::table('crm_audit_logs')->insert(['actor_id' => $employee->id, 'event' => 'call', 'created_at' => '2026-05-01 10:00:00']);
        $this->assertSame(1, $service->autoMetrics($employee, '2026-05-01')['calls_count']);
        $this->rop->supervisedGroups()->attach($this->a->id);
        Sanctum::actingAs($this->rop);
        $this->assertSame(0, $service->autoMetrics($employee, '2026-05-01')['calls_count']);

    }

    public function test_task_summaries_keep_historical_groups_and_include_sunday_evening(): void
    {
        Schema::create('crm_task_types', function (Blueprint $table) { $table->id(); $table->string('code'); });
        Schema::create('crm_tasks', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('assignee_id'); $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->string('status'); $table->timestamps();
        });
        $employee = $this->user('agent', $this->c);
        $this->rop->supervisedGroups()->attach($this->a->id);
        $newRop = $this->user('rop', $this->c);
        $newRop->supervisedGroups()->attach($this->c->id);
        foreach ([[$this->a->id, '2026-05-10 23:59:59'], [$this->a->id, '2026-05-11 00:00:00'],
            [$this->c->id, '2026-05-10 12:00:00'], [null, '2026-05-10 12:00:00']] as [$group, $date]) {
            DB::table('crm_tasks')->insert(['assignee_id' => $employee->id, 'branch_group_id' => $group,
                'status' => 'done', 'created_at' => $date, 'updated_at' => $date]);
        }
        Sanctum::actingAs($this->rop);
        $filter = '&assignee_id='.$employee->id.'&branch_group_id='.$this->a->id;
        $this->getJson('/api/crm/tasks/kpi-daily-summary?date=2026-05-10'.$filter)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.tasks_total', 1)->assertJsonPath('data.0.done_total', 1);
        $this->getJson('/api/crm/tasks/kpi-weekly-summary?year=2026&week=19'.$filter)->assertOk()
            ->assertJsonPath('data.0.tasks_total', 1)->assertJsonPath('data.0.overdue_total', 0);
        $this->getJson('/api/crm/tasks?assignee_id='.$employee->id)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/crm/tasks/kpi-daily-summary?date=2026-05-10&branch_group_id='.$this->c->id)->assertForbidden();
        $unrelated = $this->user('agent', $this->c);
        $this->getJson('/api/crm/tasks/kpi-daily-summary?date=2026-05-10&assignee_id='.$unrelated->id)->assertForbidden();
        $this->rop->supervisedGroups()->attach($this->b->id);
        $this->getJson('/api/crm/tasks?branch_group_id='.$this->b->id)->assertOk()->assertJsonCount(0, 'data');
        Sanctum::actingAs($newRop);
        $this->getJson('/api/crm/tasks/kpi-daily-summary?date=2026-05-10&assignee_id='.$employee->id)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.tasks_total', 1);
    }

    public function test_legacy_favorites_cannot_expose_or_mutate_foreign_group_properties(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('branch_id'); $table->unsignedBigInteger('branch_group_id');
            $table->string('moderation_status'); $table->string('title');
            $table->unsignedBigInteger('agent_id')->nullable(); $table->unsignedBigInteger('created_by')->nullable();
        });
        Schema::create('property_photos', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('property_id'); $table->integer('position')->default(0);
        });
        Schema::create('property_types', function (Blueprint $table) { $table->id(); });
        (require database_path('migrations/2025_06_24_151549_create_favorites_table.php'))->up();
        (require database_path('migrations/2026_08_28_150000_expand_favorites_for_residential_objects.php'))->up();
        $this->rop->supervisedGroups()->attach($this->a->id);
        foreach ([$this->a, $this->c] as $group) {
            DB::table('properties')->insert(['id' => $group->id, 'branch_id' => $this->branch->id,
                'branch_group_id' => $group->id, 'moderation_status' => \App\Models\Property::PUBLIC_MODERATION_STATUS,
                'title' => $group->name]);
            \App\Models\Favorite::create(['user_id' => $this->rop->id, 'property_id' => $group->id]);
        }
        Sanctum::actingAs($this->rop);
        $this->getJson('/api/favorites')->assertOk()->assertJsonCount(1)->assertJsonPath('0.property.id', $this->a->id);
        $this->postJson('/api/favorites', ['property_id' => $this->c->id])->assertNotFound();
        $this->deleteJson('/api/favorites/'.$this->c->id)->assertNotFound();
        $this->assertDatabaseCount('favorites', 2);
        $this->postJson('/api/favorites', ['property_id' => $this->a->id])->assertCreated();
        $this->deleteJson('/api/favorites/'.$this->a->id)->assertOk();
        $this->assertDatabaseCount('favorites', 1);
        $favorite = \App\Models\Favorite::firstOrFail()->load('property');
        $this->assertNull($favorite->toArray()['property']);
    }

    public function test_selections_use_assigned_groups_and_filter_nested_properties(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('branch_id'); $table->unsignedBigInteger('branch_group_id');
            $table->unsignedBigInteger('agent_id')->nullable(); $table->unsignedBigInteger('created_by')->nullable();
        });
        (require database_path('migrations/2025_10_07_051637_create_selections.php'))->up();
        (require database_path('migrations/2026_09_08_150000_add_selection_group.php'))->up();
        $agent = $this->user('agent', $this->a);
        $this->rop->supervisedGroups()->attach([$this->a->id, $this->b->id]);
        foreach ([$this->a, $this->c] as $group) {
            DB::table('properties')->insert(['id' => $group->id, 'branch_id' => $this->branch->id,
                'branch_group_id' => $group->id, 'agent_id' => $agent->id]);
        }
        $visible = \App\Models\Selection::create(['created_by' => $agent->id, 'branch_group_id' => $this->a->id,
            'property_ids' => [$this->a->id, $this->c->id], 'selection_hash' => 'visible', 'selection_url' => 'https://aura.tj/s/visible',
            'contact_id' => 99, 'deal_id' => 88, 'meta' => ['events' => [['payload' => ['property_id' => $this->c->id]]]]]);
        $foreign = \App\Models\Selection::create(['created_by' => $this->rop->id, 'branch_group_id' => $this->c->id,
            'property_ids' => [$this->c->id], 'selection_hash' => 'foreign', 'selection_url' => 'https://aura.tj/s/foreign']);
        Sanctum::actingAs($this->rop);
        $this->getJson('/api/selections')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->id);
        $this->getJson('/api/selections/'.$visible->id)->assertOk()->assertJsonPath('property_ids', [$this->a->id])
            ->assertJsonPath('meta.events', [])->assertJsonMissingPath('contact_id')->assertJsonMissingPath('deal_id');
        $this->getJson('/api/selections/'.$foreign->id)->assertNotFound();
        $this->postJson('/api/selections', ['property_ids' => [$this->a->id]])->assertUnprocessable();
        $this->postJson('/api/selections', ['property_ids' => [$this->c->id], 'branch_group_id' => $this->a->id])->assertNotFound();
        $this->postJson('/api/selections', ['property_ids' => [$this->a->id], 'branch_group_id' => $this->a->id])
            ->assertCreated()->assertJsonPath('selection.branch_group_id', $this->a->id);
        $this->assertDatabaseCount('selections', 3);
        $this->getJson('/api/selections?per_page=101')->assertUnprocessable();
        $this->getJson('/api/selections?deal_id=88')->assertUnprocessable();
        $this->getJson('/api/selections?branch_group_id='.$this->b->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/selections?branch_group_id='.$this->c->id)->assertForbidden();
        DB::table('properties')->insert(['id' => $this->b->id, 'branch_id' => $this->branch->id,
            'branch_group_id' => $this->b->id, 'agent_id' => $agent->id]);
        for ($i = 0; $i < 4; $i++) {
            \App\Models\Selection::create(['created_by' => $agent->id, 'branch_group_id' => $this->a->id,
                'property_ids' => [$this->b->id, $this->a->id, $this->c->id], 'selection_hash' => 'batch-'.$i,
                'selection_url' => 'https://aura.tj/s/batch-'.$i]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/selections?per_page=100')->assertOk()->assertJsonCount(6, 'data')
            ->assertJsonPath('data.0.property_ids', [$this->b->id, $this->a->id]);
        $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'from "properties"'));
        DB::disableQueryLog();
        $this->assertCount(1, $queries, 'A selection page must resolve nested property visibility in one query.');

    }

    public function test_assignment_replacement_is_versioned_audited_and_revocable(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson($this->url(), ['branch_group_ids' => [$this->a->id, $this->b->id], 'version' => 0])
            ->assertOk()->assertJsonPath('version', 1)->assertJsonCount(2, 'groups');
        $this->assertDatabaseCount('group_access_audit_logs', 1);
        $this->putJson($this->url(), ['branch_group_ids' => [$this->c->id], 'version' => 0])
            ->assertStatus(409)->assertJsonPath('code', 'ACCESS_SCOPE_VERSION_CONFLICT');
        $this->assertDatabaseCount('rop_branch_groups', 2);
        $this->assertDatabaseCount('group_access_audit_logs', 1);

        Sanctum::actingAs($this->rop);
        $this->getJson('/api/me/access-scope')->assertOk()->assertJsonPath('branch_group_ids', [$this->a->id, $this->b->id]);
        Sanctum::actingAs($this->admin);
        $this->putJson($this->url(), ['branch_group_ids' => [], 'version' => 1])->assertOk()->assertJsonPath('version', 2);
        Sanctum::actingAs($this->rop);
        $this->getJson('/api/me/access-scope')->assertOk()->assertJsonPath('branch_group_ids', []);
    }

    public function test_scope_version_and_private_cache_headers_are_present_on_denied_and_invalid_requests(): void
    {
        $this->rop->forceFill(['access_scope_version' => 7])->save();
        $this->rop->supervisedGroups()->attach($this->a->id);
        Sanctum::actingAs($this->rop);
        $this->getJson('/api/me/access-scope?branch_group_id='.$this->c->id)->assertForbidden()
            ->assertHeader('X-Access-Scope-Version', '7')->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/me/access-scope?branch_group_id=invalid')->assertUnprocessable()
            ->assertHeader('X-Access-Scope-Version', '7')->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/clients/999999')->assertNotFound()->assertHeader('X-Access-Scope-Version', '7');
        $this->postJson('/api/crm/tasks', [])->assertUnprocessable()->assertHeader('X-Access-Scope-Version', '7');
        DB::table('users')->where('id', $this->rop->id)->update(['access_scope_version' => 8]);
        $this->postJson('/api/crm/tasks', [])->assertConflict()
            ->assertJsonPath('code', 'ACCESS_SCOPE_VERSION_CONFLICT')->assertHeader('X-Access-Scope-Version', '8');
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/me/access-scope')->assertOk()->assertHeaderMissing('X-Access-Scope-Version');
    }

    public function test_rop_cannot_grant_itself_access_and_own_working_group_is_not_a_grant(): void
    {
        Sanctum::actingAs($this->rop);
        $this->getJson('/api/me/access-scope')->assertOk()->assertJsonPath('branch_group_ids', []);
        $this->putJson($this->url(), ['branch_group_ids' => [$this->a->id], 'version' => 0])->assertForbidden();
        $this->assertDatabaseCount('rop_branch_groups', 0);
    }

    public function test_director_can_manage_own_branch_only_and_cross_branch_groups_are_rejected_atomically(): void
    {
        $otherBranch = Branch::create(['name' => 'Other']);
        $other = BranchGroup::create(['branch_id' => $otherBranch->id, 'name' => 'Other']);
        Sanctum::actingAs($this->user('branch_director', $other));
        $this->getJson($this->url())->assertForbidden();
        Sanctum::actingAs($this->user('branch_director', $this->a));
        $this->putJson($this->url(), ['branch_group_ids' => [$this->a->id, $other->id], 'version' => 0])->assertForbidden();
        $this->assertDatabaseCount('rop_branch_groups', 0);
        $this->putJson($this->url(), ['branch_group_ids' => [$this->a->id], 'version' => 0])->assertOk();
    }

    public function test_duplicates_and_non_rop_targets_are_rejected(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson($this->url(), ['branch_group_ids' => [$this->a->id, $this->a->id], 'version' => 0])->assertUnprocessable();
        $agent = $this->user('agent', $this->a);
        $this->putJson('/api/users/'.$agent->id.'/supervised-groups', ['branch_group_ids' => [], 'version' => 0])->assertUnprocessable();
    }

    public function test_employee_scope_includes_only_agents_and_mops_in_assigned_groups(): void
    {
        $agent = $this->user('agent', $this->a);
        $mop = $this->user('mop', $this->b);
        $this->user('agent', $this->c);
        $this->user('marketing', $this->a);
        $this->rop->supervisedGroups()->attach([$this->a->id, $this->b->id]);
        $this->assertEqualsCanonicalizing([$agent->id, $mop->id], app(RopGroupAccess::class)->employees($this->rop)->pluck('id')->all());
        $this->rop->branch_id = null;
        $this->rop->save();
        $this->assertSame([], app(RopGroupAccess::class)->employees($this->rop)->pluck('id')->all());
    }

    public function test_persisted_inactive_status_closes_scope_even_for_previously_loaded_actor(): void
    {
        $this->rop->supervisedGroups()->attach($this->a->id);
        $employee = $this->user('agent', $this->a);
        $access = app(RopGroupAccess::class);
        $this->assertSame([$this->a->id], $access->groupIds($this->rop));
        $this->assertTrue($access->employees($this->rop)->whereKey($employee->id)->exists());
        DB::table('users')->where('id', $this->rop->id)->update(['status' => User::STATUS_INACTIVE]);
        $this->assertSame(User::STATUS_ACTIVE, $this->rop->status);
        $this->assertSame([], $access->groupIds($this->rop));
        $this->assertFalse($access->employees($this->rop)->exists());
        // Blocking does not silently destroy the administrator's assignments.
        $this->assertSame(1, $this->rop->supervisedGroups()->count());
    }

    public function test_mobile_client_filters_only_offer_active_managed_employees(): void
    {
        $this->rop->supervisedGroups()->sync([$this->a->id, $this->b->id]);
        $a = $this->user('agent', $this->a);
        $b = $this->user('mop', $this->b);
        $this->user('agent', $this->c);
        $inactive = $this->user('agent', $this->a);
        DB::table('users')->where('id', $inactive->id)->update(['status' => 'inactive']);
        $this->user('branch_director', $this->a);
        Sanctum::actingAs($this->rop);
        $body = $this->getJson('/api/mobile/clients/filters')->assertOk()->json();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($body['responsible_agents'], 'id'));
        $this->assertEqualsCanonicalizing([$this->a->id, $this->b->id], array_column($body['branch_groups'], 'id'));
        $this->rop->supervisedGroups()->detach();
        $this->getJson('/api/mobile/clients/filters')->assertOk()->assertJsonPath('responsible_agents', []);
    }

    public function test_clients_use_record_group_without_authorship_or_branch_fallback(): void
    {
        $this->rop->supervisedGroups()->attach($this->a->id);
        foreach ([$this->a->id, $this->c->id, null] as $group) {
            DB::table('clients')->insert(['full_name' => 'Contact', 'branch_id' => $this->branch->id,
                'branch_group_id' => $group, 'created_by' => $this->rop->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $query = app(ClientAccess::class)->visibleQuery($this->rop);
        $this->assertSame(1, $query->count());
        $this->assertSame($this->a->id, (int) $query->value('branch_group_id'));
        $foreign = Client::where('branch_group_id', $this->c->id)->firstOrFail();
        $this->assertFalse(app(RopGroupAccess::class)->allows($this->rop, $foreign));
    }

    public function test_changing_role_revokes_assignments_and_does_not_restore_them_when_role_returns(): void
    {
        $this->rop->supervisedGroups()->attach($this->a->id);
        $service = app(\App\Services\GroupAccess\UserOrganizationService::class);
        $rop = $service->update($this->admin, $this->rop, ['role_id' => Role::where('slug', 'agent')->value('id')]);
        $this->assertDatabaseCount('rop_branch_groups', 0);
        $this->assertSame(1, (int) $rop->access_scope_version);
        $rop = $service->update($this->admin, $rop, ['role_id' => Role::where('slug', 'rop')->value('id')]);
        $this->assertDatabaseCount('rop_branch_groups', 0);
        $this->assertSame(2, (int) $rop->access_scope_version);
    }

    public function test_organization_update_rechecks_group_branch_before_revoking_assignments(): void
    {
        $this->rop->supervisedGroups()->attach($this->a->id);
        $otherBranch = Branch::create(['name' => 'Other branch']);
        // Simulate a destination moved after the caller prepared its employee payload.
        $this->b->update(['branch_id' => $otherBranch->id]);
        try {
            app(\App\Services\GroupAccess\UserOrganizationService::class)->update($this->admin, $this->rop, [
                'role_id' => Role::where('slug', 'agent')->value('id'),
                'branch_id' => $this->branch->id, 'branch_group_id' => $this->b->id,
            ]);
            $this->fail('Stale destination branch must be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
            $this->assertSame('GROUP_BRANCH_MISMATCH', $error->getMessage());
        }
        $this->assertSame('rop', $this->rop->fresh()->role->slug);
        $this->assertSame(0, (int) $this->rop->fresh()->access_scope_version);
        $this->assertDatabaseHas('rop_branch_groups', ['rop_id' => $this->rop->id, 'branch_group_id' => $this->a->id]);
        $this->assertDatabaseCount('group_access_audit_logs', 0);
    }

    public function test_client_observer_uses_actor_role_reloaded_under_write_lock(): void
    {
        Sanctum::actingAs($this->admin);
        DB::table('users')->where('id', $this->admin->id)->update(['role_id' => Role::where('slug', 'rop')->value('id')]);
        try {
            DB::transaction(function () {
                app(\App\Services\GroupAccess\GroupRecordWriteLock::class)->acquire($this->admin, null, null, $this->c->id);
                Client::create(['full_name' => 'Denied', 'branch_id' => $this->branch->id, 'branch_group_id' => $this->c->id]);
            });
            $this->fail('Observer must not use the former administrator role.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame('rop', auth()->user()->role->slug);
        $this->assertDatabaseCount('clients', 0);
    }

    public function test_client_write_rejects_stale_ownership_after_a_record_transfer(): void
    {
        $agentA = $this->user('agent', $this->a);
        $agentB = $this->user('agent', $this->b);
        $stale = Client::create(['full_name' => 'Before transfer', 'branch_id' => $this->branch->id,
            'branch_group_id' => $this->a->id, 'responsible_agent_id' => $agentA->id]);
        DB::table('clients')->where('id', $stale->id)->update([
            'branch_group_id' => $this->b->id, 'responsible_agent_id' => $agentB->id,
        ]);
        try {
            DB::transaction(fn () => app(\App\Services\GroupAccess\GroupRecordWriteLock::class)
                ->acquire($this->admin, $stale, null, null));
            $this->fail('A stale client write must reload ownership.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
            $this->assertSame('RECORD_OWNERSHIP_CHANGED', $error->getMessage());
        }
        $this->assertSame($this->b->id, (int) $stale->fresh()->branch_group_id);
        $this->assertSame($agentB->id, (int) $stale->fresh()->responsible_agent_id);
        $this->assertSame('Before transfer', $stale->fresh()->full_name);
    }

    public function test_raw_property_group_is_used_instead_of_agent_accessor_fallback(): void
    {
        $this->rop->supervisedGroups()->attach($this->a->id);
        $agent = $this->user('agent', $this->a);
        $property = new \App\Models\Property(['branch_id' => $this->branch->id, 'branch_group_id' => null, 'agent_id' => $agent->id]);
        $property->setRelation('agent', $agent);
        $this->assertSame($this->a->id, $property->branch_group_id);
        $this->assertFalse(app(RopGroupAccess::class)->allows($this->rop, $property));
    }

    public function test_saving_own_client_cannot_move_it_to_another_group_even_when_both_are_allowed(): void
    {
        $this->rop->supervisedGroups()->attach([$this->a->id, $this->b->id]);
        $client = Client::create(['full_name' => 'Owned', 'branch_id' => $this->branch->id, 'branch_group_id' => $this->a->id]);
        Sanctum::actingAs($this->rop);
        try {
            $client->update(['branch_group_id' => $this->b->id]);
            $this->fail('Ordinary update must require an explicit transfer.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
            $this->assertSame('GROUP_TRANSFER_REQUIRED', $error->getMessage());
        }
        $this->assertSame($this->a->id, (int) $client->fresh()->branch_group_id);
    }

    public function test_custom_group_profile_policy_controls_nested_fields_without_exposing_foreign_users(): void
    {
        $role = Role::create(['name' => 'Custom employee', 'slug' => 'custom_group_profile']);
        $local = $this->user($role->slug, $this->a);
        $foreign = $this->user($role->slug, $this->c);
        $this->rop->supervisedGroups()->attach($this->a->id);
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $projection = app(\App\Services\GroupAccess\GroupDataProjection::class);
        foreach ([[], ['users.subject.group_profile'], []] as $rights) {
            $role->forceFill(['permissions' => $rights])->save();
            $record = new Client();
            $record->setRelation('responsibleAgent', $local->fresh());
            $record->setRelation('creator', $foreign->fresh());
            $result = $projection->relations($record, $this->rop->fresh());
            $this->assertSame($rights !== [], array_key_exists('phone', $result['responsible_agent']));
            $this->assertSame($local->id, $result['responsible_agent']['id']);
            $this->assertSame(['id' => $foreign->id, 'name' => $foreign->name], $result['creator']);
        }
    }

    public function test_group_projection_removes_foreign_card_and_preserves_only_author_label(): void
    {
        $this->rop->supervisedGroups()->attach($this->a->id);
        Sanctum::actingAs($this->rop);
        $booking = new \App\Models\Booking(['branch_group_id' => $this->a->id,
            'crm_client_id' => 999, 'client_name' => 'secret', 'client_phone' => 'secret']);
        $booking->setRelation('client', new Client(['full_name' => 'Hidden', 'phone' => 'secret',
            'branch_id' => $this->branch->id, 'branch_group_id' => $this->c->id]));
        $author = new User(['name' => 'Author', 'phone' => 'secret', 'branch_id' => $this->branch->id, 'branch_group_id' => $this->c->id]);
        $author->id = 999;
        $booking->setRelation('agent', $author);
        $payload = $booking->toArray();
        $this->assertNull($payload['client']);
        $this->assertSame(['id' => 999, 'name' => 'Author'], $payload['agent']);
        $this->assertStringNotContainsString('secret', json_encode($payload));
    }

    public function test_explicit_filter_checks_every_employee_field(): void
    {
        $this->rop->supervisedGroups()->attach($this->a->id);
        $allowed = $this->user('agent', $this->a);
        $foreign = $this->user('agent', $this->c);
        Sanctum::actingAs($this->rop);
        $this->getJson('/api/me/access-scope?agent_id='.$allowed->id.'&user_id='.$foreign->id)
            ->assertForbidden()->assertJsonPath('code', 'RBAC_GROUP_SCOPE_VIOLATION');
    }

    public function test_historical_task_keeps_own_group_but_hides_foreign_parent_snapshots_without_n_plus_one(): void
    {
        $this->rop->supervisedGroups()->attach($this->a->id);
        $client = Client::create(['full_name' => 'Hidden', 'branch_id' => $this->branch->id,
            'branch_group_id' => $this->c->id, 'created_by' => $this->admin->id]);
        $tasks = collect(range(1, 20))->map(fn ($id) => new \App\Models\CrmTask([
            'branch_group_id' => $this->a->id, 'related_entity_type' => 'client', 'related_entity_id' => $client->id,
            'title' => 'Private copied contact', 'description' => 'Private copied phone', 'status' => 'done',
        ]));
        Sanctum::actingAs($this->rop);
        DB::enableQueryLog();
        try {
            app(\App\Services\GroupAccess\GroupDataProjection::class)->prepareTasks($tasks, $this->rop);
            $payload = $tasks->toArray();
            $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'from "clients"'));
            $this->assertCount(1, $queries);
        } finally {
            DB::disableQueryLog(); DB::flushQueryLog();
        }
        $this->assertSame('done', $payload[0]['status']);
        $this->assertSame($this->a->id, $payload[0]['branch_group_id']);
        $this->assertNull($payload[0]['related_entity_id']);
        $this->assertStringNotContainsString('Private copied', json_encode($payload));
        $this->assertSame('Private copied phone', $tasks->first()->description);
        Sanctum::actingAs($this->admin);
        $this->assertSame('Private copied phone', $tasks->first()->toArray()['description']);
    }
    public function test_moderation_scope_denies_foreign_null_groups_and_revoked_membership(): void
    {
        Schema::create('properties', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('branch_group_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
        });
        foreach ([$this->a->id, $this->b->id, $this->c->id, null] as $index => $group) {
            DB::table('properties')->insert(['id' => $index + 1, 'branch_id' => $this->branch->id,
                'branch_group_id' => $group, 'created_by' => $this->rop->id]);
        }
        $this->rop->supervisedGroups()->attach([$this->a->id, $this->b->id]);
        $access = app(\App\Services\PropertyModeration\PropertyModerationAccess::class);
        $query = fn () => $access->scopeModeratable(\App\Models\Property::query(), $this->rop)->pluck('id')->all();
        $this->assertSame([1, 2], $query());
        foreach ([1 => true, 2 => true, 3 => false, 4 => false] as $id => $allowed) {
            $property = \App\Models\Property::findOrFail($id);
            $this->assertSame($allowed, $access->canModerate($this->rop, $property));
            $this->assertSame($allowed, $access->canEdit($this->rop, $property));
        }
        $this->rop->supervisedGroups()->detach($this->b->id);
        $this->assertSame([1], $query());
        $this->rop->supervisedGroups()->detach();
        $this->assertSame([], $query());
        $this->assertCount(4, $access->scopeModeratable(\App\Models\Property::query(), $this->admin)->pluck('id'));
    }

    public function test_moderation_case_does_not_serialize_foreign_duplicate_snapshots(): void
    {
        $this->rop->supervisedGroups()->attach($this->a->id);
        Sanctum::actingAs($this->rop);
        $candidate = new \App\Models\PropertyDuplicateCandidate(['candidate_property_id' => 99,
            'candidate_snapshot' => ['owner_phone' => 'foreign-secret']]);
        $candidate->setRelation('candidateProperty', new \App\Models\Property([
            'branch_id' => $this->branch->id, 'branch_group_id' => $this->c->id,
        ]));
        $case = new \App\Models\PropertyModerationCase;
        $case->setRelation('duplicateCandidates', collect([$candidate]));
        $this->assertSame([], $case->toArray()['duplicate_candidates']);
        Sanctum::actingAs($this->admin);
        $this->assertCount(1, $case->toArray()['duplicate_candidates']);
    }

    public function test_notification_list_count_and_delivery_follow_revocation_and_snapshot_group(): void
    {
        (require database_path('migrations/2025_06_23_004510_create_notifications_table.php'))->up();
        (require database_path('migrations/2026_04_04_180000_expand_notifications_table.php'))->up();
        (require database_path('migrations/2026_09_08_130000_add_notification_group_snapshot.php'))->up();
        $this->rop->supervisedGroups()->attach([$this->a->id, $this->b->id]);
        $client = Client::create(['full_name' => 'Visible', 'branch_id' => $this->branch->id, 'branch_group_id' => $this->a->id]);
        $make = fn ($group) => \App\Models\Notification::create(['user_id' => $this->rop->id,
            'subject_type' => Client::class, 'subject_id' => $client->id, 'branch_group_id' => $group,
            'type' => 'lead_new', 'title' => 'Private', 'body' => 'Private snapshot', 'category' => 'crm',
            'status' => 'unread', 'priority' => 1, 'last_occurred_at' => now()]);
        $own = $make($this->a->id);
        $make($this->c->id);
        $make(null);
        $access = app(\App\Services\GroupAccess\NotificationGroupAccess::class);
        $service = app(\App\Services\NotificationService::class);
        $this->assertSame([$own->id], $access->scope(\App\Models\Notification::query(), $this->rop)->pluck('id')->all());
        $this->assertSame(1, $service->unreadCount($this->rop));
        Sanctum::actingAs($this->rop);
        $this->getJson('/api/notifications')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('unread_count', 1);
        $this->rop->supervisedGroups()->detach($this->a->id);
        $this->assertSame(0, $service->unreadCount($this->rop));
        $this->assertSame(0, $service->markAllAsRead($this->rop));
        $this->getJson('/api/notifications')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/notifications/read-all')->assertOk()->assertJsonPath('updated', 0);
        $this->patchJson('/api/notifications/'.$own->id.'/read')->assertNotFound();
        try {
            $service->markAsRead($own, $this->rop);
            $this->fail('Revoked notification cannot be returned by mark-read.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $firebase = \Mockery::mock(\App\Services\FirebasePushService::class);
        $firebase->shouldNotReceive('send');
        (new \App\Jobs\SendFirebasePushNotification($own->id))->handle($firebase);
        DB::table('clients')->where('id', $client->id)->update(['branch_group_id' => $this->b->id]);
        $this->assertFalse($access->allows($own, $this->rop), 'Transfer must not relabel an old private notification snapshot.');
        $this->assertTrue($access->subjectAllowed($this->admin, $client));
    }

    public function test_inactive_chat_recipient_cannot_receive_queued_push_with_stale_actor(): void
    {
        (require database_path('migrations/2025_06_23_004510_create_notifications_table.php'))->up();
        (require database_path('migrations/2026_04_04_180000_expand_notifications_table.php'))->up();
        (require database_path('migrations/2026_09_08_130000_add_notification_group_snapshot.php'))->up();
        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('user_id');
        });
        $access = app(\App\Services\GroupAccess\NotificationGroupAccess::class);
        foreach ([$this->rop, $this->admin] as $recipient) {
            DB::table('conversation_participants')->insert(['conversation_id' => 901, 'user_id' => $recipient->id]);
            $notification = \App\Models\Notification::create(['user_id' => $recipient->id,
                'subject_type' => \App\Models\Conversation::class, 'subject_id' => 901,
                'type' => 'chat_message', 'title' => 'Private chat', 'body' => 'Private message',
                'category' => 'chat', 'status' => 'unread', 'priority' => 1, 'last_occurred_at' => now()]);
            $this->assertTrue($access->allows($notification, $recipient));
            DB::table('users')->where('id', $recipient->id)->update(['status' => User::STATUS_INACTIVE]);
            $this->assertSame(User::STATUS_ACTIVE, $recipient->status);
            $this->assertFalse($access->allows($notification, $recipient));
            $firebase = \Mockery::mock(\App\Services\FirebasePushService::class);
            $firebase->shouldNotReceive('send');
            (new \App\Jobs\SendFirebasePushNotification($notification->id))->handle($firebase);
        }
    }

    public function test_selection_notifications_follow_selection_and_event_property_groups(): void
    {
        foreach (['2025_06_23_004510_create_notifications_table.php', '2026_04_04_180000_expand_notifications_table.php',
            '2026_09_08_130000_add_notification_group_snapshot.php', '2025_10_07_051637_create_selections.php',
            '2026_09_08_150000_add_selection_group.php'] as $migration) (require database_path('migrations/'.$migration))->up();
        Schema::create('properties', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id'); $t->unsignedBigInteger('branch_group_id'); $t->timestamps();
        });
        $this->rop->supervisedGroups()->attach($this->a->id);
        $ownId = DB::table('properties')->insertGetId(['branch_id' => $this->branch->id, 'branch_group_id' => $this->a->id]);
        $foreignId = DB::table('properties')->insertGetId(['branch_id' => $this->branch->id, 'branch_group_id' => $this->c->id]);
        $selection = \App\Models\Selection::create(['created_by' => $this->rop->id, 'branch_group_id' => $this->a->id,
            'property_ids' => [$ownId, $foreignId], 'selection_hash' => 'private-hash', 'selection_url' => '/s/private-hash']);
        \Illuminate\Support\Facades\Queue::fake();
        $service = app(\App\Services\NotificationService::class);
        $service->handleSelectionEvent($selection, 'opened', ['property_id' => $foreignId]);
        $this->assertDatabaseCount('notifications', 0);
        $service->handleSelectionEvent($selection, 'opened', ['property_id' => $ownId, 'secret' => 'must-not-copy']);
        $this->assertDatabaseCount('notifications', 2);
        foreach (\App\Models\Notification::all() as $row) {
            $this->assertSame($this->a->id, (int) $row->branch_group_id);
            $this->assertSame('/profile/clients', $row->action_url);
            $this->assertSame(['selection_id' => $selection->id, 'payload' => ['property_id' => $ownId]], $row->data);
        }
        Sanctum::actingAs($this->rop);
        $response = $this->getJson('/api/notifications')->assertOk()->assertJsonCount(2, 'data');
        $this->assertStringNotContainsString('private-hash', $response->getContent());
        DB::table('properties')->where('id', $ownId)->update(['branch_group_id' => $this->c->id]);
        $this->getJson('/api/notifications')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(0, $service->unreadCount($this->rop));
        $service->handleSelectionEvent($selection, 'viewed');
        $this->assertSame(2, $service->unreadCount($this->rop));
        $this->rop->supervisedGroups()->detach();
        $this->assertSame(0, $service->unreadCount($this->rop));
        $service->handleSelectionEvent($selection, 'requested_showing', ['property_id' => $ownId]);
        $this->assertDatabaseCount('notifications', 3);
    }

    public function test_notification_aggregation_preserves_original_group_after_transfer(): void
    {
        (require database_path('migrations/2025_06_23_004510_create_notifications_table.php'))->up();
        (require database_path('migrations/2026_04_04_180000_expand_notifications_table.php'))->up();
        (require database_path('migrations/2026_09_08_130000_add_notification_group_snapshot.php'))->up();
        $this->rop->supervisedGroups()->attach([$this->a->id, $this->b->id]);
        $client = Client::create(['full_name' => 'Moving client', 'branch_id' => $this->branch->id, 'branch_group_id' => $this->a->id]);
        $service = app(\App\Services\NotificationService::class);
        $method = new \ReflectionMethod($service, 'createOrAggregate');
        $notify = fn ($body) => $method->invoke($service, $this->rop, 'lead_new', 'Client event', $body, $client, null,
            ['channels' => [], 'quiet_window_minutes' => 60, 'dedupe_key' => 'same-event']);
        $first = $notify('A event');
        $repeat = $notify('A repeated event');
        $this->assertSame($first->id, $repeat->id);
        $this->assertSame(2, $repeat->occurrences_count);
        $before = $first->fresh()->getAttributes();
        DB::table('clients')->where('id', $client->id)->update(['branch_group_id' => $this->b->id]);
        $client->refresh();
        $second = $notify('B event');
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($before, $first->fresh()->getAttributes());
        $this->assertSame($this->b->id, (int) $second->branch_group_id);
        $this->assertSame(1, $second->occurrences_count);
        $this->assertSame(1, $service->unreadCount($this->rop));
        Sanctum::actingAs($this->rop);
        $this->getJson('/api/notifications')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second->id);
        $this->rop->supervisedGroups()->detach($this->b->id);
        $this->assertSame(0, $service->unreadCount($this->rop));
    }

    public function test_moderation_notification_is_created_only_for_assigned_rops(): void
    {
        (require database_path('migrations/2025_06_23_004510_create_notifications_table.php'))->up();
        (require database_path('migrations/2026_04_04_180000_expand_notifications_table.php'))->up();
        (require database_path('migrations/2026_09_08_130000_add_notification_group_snapshot.php'))->up();
        $this->rop->supervisedGroups()->attach($this->a->id);
        $otherRop = $this->user('rop', $this->a);
        $otherRop->supervisedGroups()->attach($this->c->id);
        $agent = $this->user('agent', $this->a);
        $property = new \App\Models\Property(['branch_id' => $this->branch->id,
            'branch_group_id' => $this->a->id, 'agent_id' => $agent->id, 'created_by' => $agent->id]);
        $property->id = 999;
        $property->setRelation('agent', $agent)->setRelation('creator', $agent);
        app(\App\Services\PropertyModeration\PropertyModerationNotifier::class)
            ->moderationEvent($property, 'property_sent_to_moderation', $agent);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->rop->id, 'branch_group_id' => $this->a->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $otherRop->id]);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->admin->id]);
    }

    public function test_personal_chat_notifications_require_participation_even_without_groups(): void
    {
        (require database_path('migrations/2025_06_23_004510_create_notifications_table.php'))->up();
        (require database_path('migrations/2026_04_04_180000_expand_notifications_table.php'))->up();
        (require database_path('migrations/2026_09_08_130000_add_notification_group_snapshot.php'))->up();
        Schema::create('conversation_participants', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('conversation_id'); $t->unsignedBigInteger('user_id');
        });
        DB::table('conversation_participants')->insert(['conversation_id' => 12, 'user_id' => $this->rop->id]);
        $notification = \App\Models\Notification::create(['user_id' => $this->rop->id,
            'subject_type' => \App\Models\Conversation::class, 'subject_id' => 12,
            'type' => 'chat_new_message', 'title' => 'Personal', 'body' => 'Private']);
        $access = app(\App\Services\GroupAccess\NotificationGroupAccess::class);
        $this->assertTrue($access->allows($notification, $this->rop));
        DB::table('conversation_participants')->where('conversation_id', 12)->delete();
        $this->assertFalse($access->allows($notification, $this->rop));
    }

    public function test_delayed_facts_use_audited_group_and_leave_unknown_history_unclassified(): void
    {
        $user = $this->user('agent', $this->b);
        $today = \Carbon\CarbonImmutable::now('Asia/Dushanbe')->startOfDay();
        $resolver = app(\App\Services\GroupAccess\HistoricalUserGroup::class);
        $this->assertNull($resolver->at($user, $today->subDay()->addHours(9))['branch_group_id']);
        foreach ([
            [$today->subDay()->addHour(), null, $this->a->id],
            [$today->addHours(12), $this->a->id, $this->b->id],
        ] as [$at, $before, $after]) {
            DB::table('group_access_audit_logs')->insert(['actor_id' => $this->admin->id,
                'subject_type' => 'user', 'subject_id' => $user->id, 'event' => 'user_organization_changed',
                'old_values' => json_encode(['branch_id' => $this->branch->id, 'branch_group_id' => $before]),
                'new_values' => json_encode(['branch_id' => $this->branch->id, 'branch_group_id' => $after]),
                'created_at' => $at->setTimezone(config('app.timezone'))]);
        }
        $this->assertSame($this->a->id, $resolver->at($user, $today->subDay()->addHours(9))['branch_group_id']);
        $this->assertSame($this->a->id, $resolver->at($user, $today->addHours(9))['branch_group_id']);
        $this->assertSame($this->b->id, $resolver->at($user, $today->addHours(13))['branch_group_id']);
        $this->assertNull($resolver->at($user, $today->subDays(2))['branch_group_id']);
    }

    public function test_employee_inventory_does_not_skip_properties_with_unknown_status(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id');
            $table->string('moderation_status')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        $employee = $this->user('agent', $this->a);
        foreach ([null, 'pending', 'approved', 'sold', 'rented', 'sold_by_owner', 'deleted', 'archived'] as $status) {
            DB::table('properties')->insert(['agent_id' => $employee->id, 'moderation_status' => $status]);
        }
        DB::table('properties')->insert(['agent_id' => $employee->id, 'moderation_status' => null, 'deleted_at' => now()]);
        $organization = app(\App\Services\GroupAccess\UserOrganizationService::class);
        $this->assertSame([1, 2, 3], $organization->activeQueries($employee->id)['properties']->orderBy('id')->pluck('id')->all());
        DB::table('properties')->where('id', '!=', 1)->delete();
        $this->assertTrue($organization->hasActiveRecords($employee->id));
    }

    public function test_transfer_with_only_disputed_records_keeps_parent_and_tasks_and_rejects_stale_plan(): void
    {
        (require database_path('migrations/2026_09_09_030000_create_group_access_review_items.php'))->up();
        Schema::create('crm_tasks', function (Blueprint $t) {
            $t->id(); $t->string('title'); $t->unsignedBigInteger('assignee_id');
            $t->unsignedBigInteger('branch_group_id')->nullable(); $t->string('status')->nullable();
            $t->string('related_entity_type')->nullable(); $t->unsignedBigInteger('related_entity_id')->nullable();
            $t->timestamp('completed_at')->nullable(); $t->timestamps();
        });
        $employee = $this->user('agent', $this->a);
        $director = $this->user('branch_director', $this->a);
        $otherBranch = Branch::create(['name' => 'Other']);
        $foreign = BranchGroup::create(['name' => 'Foreign', 'branch_id' => $otherBranch->id]);
        $client = Client::create(['full_name' => 'Client', 'status' => 'active', 'branch_id' => $this->branch->id,
            'branch_group_id' => $this->a->id, 'responsible_agent_id' => $employee->id]);
        DB::table('crm_tasks')->insert(['id' => 1, 'title' => 'Disputed task', 'assignee_id' => $employee->id,
            'branch_group_id' => $foreign->id, 'related_entity_type' => 'client', 'related_entity_id' => $client->id]);
        Sanctum::actingAs($director);
        $url = '/api/users/'.$employee->id.'/group-transfer';
        $preview = $this->getJson($url)->assertOk()->assertJsonCount(0, 'records')->assertJsonCount(2, 'deferred_records')->json();
        $payload = ['branch_group_id' => $this->b->id, 'revision' => $preview['revision'], 'reason' => 'Team reassignment', 'records' => []];
        DB::table('crm_tasks')->where('id', 1)->update(['title' => 'Changed']);
        $this->postJson($url, $payload)->assertConflict();
        $this->assertDatabaseCount('group_access_review_items', 0);
        $payload['revision'] = $this->getJson($url)->assertOk()->json('revision');
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('deferred_records_count', 2);
        $this->assertSame($this->b->id, $employee->fresh()->branch_group_id);
        $this->assertSame($this->a->id, $client->fresh()->branch_group_id);
        $this->assertDatabaseHas('crm_tasks', ['id' => 1, 'branch_group_id' => $foreign->id, 'assignee_id' => $employee->id]);
        $this->assertDatabaseCount('group_access_review_items', 2);
        Sanctum::actingAs($this->admin);
        $recordUrl = '/api/group-transfers/clients/'.$client->id;
        $revision = $this->getJson($recordUrl)->assertOk()->json('revision');
        $this->postJson($recordUrl, ['branch_group_id' => $this->b->id, 'responsible_user_id' => $employee->id,
            'revision' => $revision, 'reason' => 'Resolve parent and task'])->assertOk();
        $this->assertDatabaseHas('crm_tasks', ['id' => 1, 'branch_group_id' => $this->b->id]);
        $this->assertSame(0, DB::table('group_access_review_items')->whereNull('resolved_at')->count());
    }

    public function test_director_transfer_defers_foreign_records_and_admin_can_resolve_them(): void
    {
        (require database_path('migrations/2026_09_09_030000_create_group_access_review_items.php'))->up();
        $employee = $this->user('agent', $this->a);
        $director = $this->user('branch_director', $this->a);
        $otherBranch = Branch::create(['name' => 'Other']);
        $foreign = BranchGroup::create(['name' => 'Foreign', 'branch_id' => $otherBranch->id]);
        $make = fn ($name, $status = 'active') => Client::create(['full_name' => $name, 'status' => $status,
            'branch_id' => $this->branch->id, 'branch_group_id' => $this->a->id, 'responsible_agent_id' => $employee->id]);
        $move = $make('Move'); $dispute = $make('Private foreign contact'); $history = $make('History', 'inactive');
        DB::table('clients')->where('id', $dispute->id)->update(['branch_id' => $otherBranch->id, 'branch_group_id' => $foreign->id]);
        $original = $dispute->fresh()->getAttributes();
        Sanctum::actingAs($director);
        $url = '/api/users/'.$employee->id.'/group-transfer';
        $preview = $this->getJson($url)->assertOk()->assertJsonCount(1, 'records')->assertJsonCount(1, 'deferred_records')
            ->assertJsonMissing(['title' => 'Private foreign contact'])->json();
        $payload = ['branch_group_id' => $this->b->id, 'revision' => $preview['revision'], 'reason' => 'Team reassignment',
            'records' => [['type' => 'clients', 'id' => $move->id, 'action' => 'move']]];
        $tampered = $payload;
        $tampered['records'][] = ['type' => 'clients', 'id' => $dispute->id, 'action' => 'move'];
        $this->postJson($url, $tampered)->assertUnprocessable();
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('deferred_records_count', 1)->assertJsonPath('transferred_records', 1);
        $this->assertSame($this->b->id, $employee->fresh()->branch_group_id);
        $this->assertSame($this->b->id, $move->fresh()->branch_group_id);
        $this->assertSame($original, $dispute->fresh()->getAttributes());
        $this->assertSame($this->a->id, $history->fresh()->branch_group_id);
        $this->getJson('/api/group-access/review?transfers_only=1')->assertOk()->assertJsonCount(0, 'data');
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/group-access/review?transfers_only=1')->assertOk()->assertJsonCount(1, 'data');
        app(\App\Services\GroupAccess\GroupAccessReviewQueue::class)->publish(['issues' => [], 'unclassified_history' => [], 'unclassified_attendance_context' => []]);
        $this->getJson('/api/group-access/review?transfers_only=1')->assertOk()->assertJsonCount(1, 'data');
        $recordUrl = '/api/group-transfers/clients/'.$dispute->id;
        $record = $this->getJson($recordUrl)->assertOk()->json();
        $this->postJson($recordUrl, ['branch_group_id' => $this->b->id, 'responsible_user_id' => $employee->id,
            'revision' => $record['revision'], 'reason' => 'Admin resolved dispute'])->assertOk();
        $this->getJson('/api/group-access/review?transfers_only=1')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame($this->b->id, $dispute->fresh()->branch_group_id);
    }

    public function test_employee_transfer_requires_all_active_records_and_preserves_inactive_history(): void
    {
        $employee = $this->user('agent', $this->a);
        $replacement = $this->user('agent', $this->a);
        $make = fn ($name, $status = 'active') => Client::create(['full_name' => $name, 'status' => $status,
            'branch_id' => $this->branch->id, 'branch_group_id' => $this->a->id, 'responsible_agent_id' => $employee->id]);
        $move = $make('Move'); $retain = $make('Retain'); $history = $make('Historical', 'inactive');
        Sanctum::actingAs($this->admin);
        $url = '/api/users/'.$employee->id.'/group-transfer';
        $preview = $this->getJson($url)->assertOk()->assertJsonCount(2, 'records')->json();
        $payload = ['branch_group_id' => $this->b->id, 'revision' => $preview['revision'], 'reason' => 'Team reassignment',
            'records' => [['type' => 'clients', 'id' => $move->id, 'action' => 'move']]];
        $this->postJson($url, $payload)->assertUnprocessable();
        $this->assertSame($this->a->id, (int) $employee->fresh()->branch_group_id);
        $payload['records'][] = ['type' => 'clients', 'id' => $retain->id, 'action' => 'retain', 'responsible_user_id' => $replacement->id];
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('transferred_records', 2);
        $this->assertSame($this->b->id, (int) $employee->fresh()->branch_group_id);
        $this->assertSame($this->b->id, (int) $move->fresh()->branch_group_id);
        $this->assertSame($employee->id, (int) $move->fresh()->responsible_agent_id);
        $this->assertSame($this->a->id, (int) $retain->fresh()->branch_group_id);
        $this->assertSame($replacement->id, (int) $retain->fresh()->responsible_agent_id);
        $this->assertSame($this->a->id, (int) $history->fresh()->branch_group_id);
        $this->assertDatabaseHas('group_access_audit_logs', ['subject_id' => $employee->id, 'event' => 'employee_group_transferred']);
    }

    public function test_custom_assignment_group_policy_is_checked_for_properties_and_new_tasks(): void
    {
        $role = Role::create(['name' => 'Custom assignee', 'slug' => 'custom_group_assignee']);
        $employee = $this->user($role->slug, $this->a);
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $controller = app(\App\Http\Controllers\PropertyController::class);
        $lock = new \ReflectionMethod($controller, 'lockPropertyWrite');
        foreach ([[], ['users.subject.assignment_group_required'], []] as $rights) {
            $role->forceFill(['permissions' => $rights])->save();
            foreach ([$this->a, $this->b] as $group) {
                $checks = [
                    fn () => app(\App\Observers\GroupOwnedRecordObserver::class)->saving(new Client([
                        'responsible_agent_id' => $employee->id, 'branch_group_id' => $group->id,
                        'branch_id' => $this->branch->id, 'full_name' => 'New client'])),
                    fn () => $lock->invoke($controller, $this->admin, null, ['agent_id' => $employee->id, 'branch_group_id' => $group->id]),
                    fn () => app(\App\Services\GroupAccess\TaskGroupOwnership::class)->validate(
                        new \App\Models\CrmTask(['assignee_id' => $employee->id, 'branch_group_id' => $group->id, 'status' => 'pending']), $this->admin),
                ];
                foreach ($checks as $check) {
                    $expected = $rights !== [] && $group->id !== $this->a->id;
                    try {
                        \DB::transaction($check);
                        $this->assertFalse($expected, 'Cross-group assignment must be rejected.');
                    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                        $this->assertTrue($expected);
                        $this->assertSame(422, $error->getStatusCode());
                        $this->assertSame('RESPONSIBLE_GROUP_MISMATCH', $error->getMessage());
                    }
                }
            }
        }
    }

    public function test_reopening_task_still_requires_group_transfer_without_role_policy(): void
    {
        $role = Role::create(['name' => 'Custom assignee', 'slug' => 'custom_reopening_assignee']);
        $employee = $this->user($role->slug, $this->a);
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $role->forceFill(['permissions' => []])->save();
        $task = new \App\Models\CrmTask(['assignee_id' => $employee->id,
            'branch_group_id' => $this->b->id, 'status' => 'done', 'completed_at' => '2026-10-01 10:00:00']);
        $task->exists = true;
        $task->syncOriginal();
        $task->forceFill(['status' => 'pending', 'completed_at' => null]);
        try {
            \DB::transaction(fn () => app(\App\Services\GroupAccess\TaskGroupOwnership::class)->validate($task, $this->admin));
            $this->fail('Reopening must retain the mandatory group check.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
            $this->assertSame('TASK_REOPEN_REQUIRES_GROUP_TRANSFER', $error->getMessage());
        }
    }

    public function test_task_creation_requires_visible_parent_in_the_selected_group(): void
    {
        Schema::create('crm_task_types', function (Blueprint $table) {
            $table->id(); $table->string('code'); $table->string('name'); $table->timestamps();
        });
        Schema::create('crm_tasks', function (Blueprint $table) {
            $table->id(); $table->string('title'); $table->unsignedBigInteger('task_type_id');
            $table->unsignedBigInteger('assignee_id'); $table->unsignedBigInteger('creator_id');
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->string('related_entity_type')->nullable(); $table->unsignedBigInteger('related_entity_id')->nullable();
            $table->string('status'); $table->string('source'); $table->timestamps();
        });
        $taskType = \App\Models\CrmTaskType::create(['code' => 'FOLLOW_UP', 'name' => 'Follow up']);
        $employee = $this->user('agent', $this->a);
        $clients = [];
        foreach ([$this->a, $this->b, $this->c] as $group) {
            $clients[] = Client::create(['full_name' => $group->name, 'branch_id' => $this->branch->id,
                'branch_group_id' => $group->id]);
        }
        $this->rop->supervisedGroups()->attach([$this->a->id, $this->b->id]);
        Sanctum::actingAs($this->rop);
        $payload = ['title' => 'Follow up', 'task_type_id' => $taskType->id,
            'branch_group_id' => $this->a->id, 'assignee_id' => $employee->id,
            'related_entity_type' => 'client', 'related_entity_id' => $clients[2]->id];
        $this->postJson('/api/crm/tasks', $payload)->assertNotFound();
        $payload['related_entity_id'] = $clients[1]->id;
        $this->postJson('/api/crm/tasks', $payload)->assertUnprocessable()->assertJsonPath('code', 'TASK_MUST_FOLLOW_PARENT_GROUP');
        $payload['related_entity_id'] = 99999;
        $this->postJson('/api/crm/tasks', $payload)->assertNotFound();
        $this->assertDatabaseCount('crm_tasks', 0);
        $payload['related_entity_id'] = $clients[0]->id;
        $this->postJson('/api/crm/tasks', $payload)->assertCreated()->assertJsonPath('branch_group_id', $this->a->id);
        $this->assertDatabaseCount('crm_tasks', 1);
    }

    public function test_employee_can_redistribute_active_records_in_the_current_group_before_dismissal(): void
    {
        $employee = $this->user('agent', $this->a);
        $replacement = $this->user('mop', $this->a);
        $client = Client::create(['full_name' => 'Active record', 'status' => 'active',
            'branch_id' => $this->branch->id, 'branch_group_id' => $this->a->id, 'responsible_agent_id' => $employee->id]);
        Sanctum::actingAs($this->admin);
        $url = '/api/users/'.$employee->id.'/group-transfer';
        $preview = $this->getJson($url)->assertOk()->json();
        $payload = ['branch_group_id' => $this->a->id, 'revision' => $preview['revision'], 'reason' => 'Redistribute before dismissal',
            'records' => [['type' => 'clients', 'id' => $client->id, 'action' => 'move']]];
        $this->postJson($url, $payload)->assertUnprocessable();
        $this->assertSame($employee->id, $client->fresh()->responsible_agent_id);
        $payload['records'][0] = ['type' => 'clients', 'id' => $client->id, 'action' => 'retain', 'responsible_user_id' => $replacement->id];
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('transferred_records', 1);
        $this->assertSame($this->a->id, (int) $employee->fresh()->branch_group_id);
        $this->assertSame($this->a->id, (int) $client->fresh()->branch_group_id);
        $this->assertSame($replacement->id, $client->fresh()->responsible_agent_id);
        $this->assertFalse(app(\App\Services\GroupAccess\UserOrganizationService::class)->hasActiveRecords($employee->id));
    }

    public function test_status_or_role_patch_cannot_bypass_required_active_record_handover(): void
    {
        $employee = $this->user('agent', $this->a);
        $client = Client::create(['full_name' => 'Active record', 'status' => 'active',
            'branch_id' => $this->branch->id, 'branch_group_id' => $this->a->id, 'responsible_agent_id' => $employee->id]);
        $service = app(\App\Services\GroupAccess\UserOrganizationService::class);
        foreach ([['status' => User::STATUS_INACTIVE], ['role_id' => $this->admin->role_id]] as $patch) {
            try {
                $service->update($this->admin, $employee, $patch);
                $this->fail('Active records require handover before disabling their responsible.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
            $this->assertSame(User::STATUS_ACTIVE, $employee->fresh()->status);
            $this->assertTrue($employee->fresh()->hasRole('agent'));
        }
        Sanctum::actingAs($employee);
        $this->postJson('/api/user/account-deletion', ['status' => 'inactive'])->assertConflict();
        $this->assertSame(User::STATUS_ACTIVE, $employee->fresh()->status);
        $client->update(['status' => 'inactive']);
        $service->update($this->admin, $employee, ['status' => User::STATUS_INACTIVE]);
        $this->assertSame(User::STATUS_INACTIVE, $employee->fresh()->status);
        $this->assertSame($employee->id, $client->fresh()->responsible_agent_id);
        $this->assertSame($this->a->id, (int) $client->fresh()->branch_group_id);
    }

    public function test_employee_transfer_keeps_task_plan_consistent_with_parent_and_preserves_completed_tasks(): void
    {
        Schema::create('crm_tasks', function (Blueprint $table) {
            $table->id(); $table->string('title'); $table->unsignedBigInteger('assignee_id');
            $table->string('status')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->string('related_entity_type')->nullable(); $table->unsignedBigInteger('related_entity_id')->nullable();
            $table->timestamp('completed_at')->nullable(); $table->timestamps();
        });
        $employee = $this->user('agent', $this->a);
        $replacement = $this->user('agent', $this->a);
        $client = Client::create(['full_name' => 'Parent', 'branch_id' => $this->branch->id,
            'branch_group_id' => $this->a->id, 'responsible_agent_id' => $employee->id]);
        $makeTask = fn ($completed) => \App\Models\CrmTask::create(['title' => 'Follow up',
            'assignee_id' => $employee->id, 'branch_group_id' => $this->a->id,
            'related_entity_type' => 'client', 'related_entity_id' => $client->id, 'completed_at' => $completed]);
        $active = $makeTask(null);
        $history = $makeTask(now()->subDay());
        $closedSnapshots = [];
        foreach (['done', 'canceled'] as $status) {
            $closed = $makeTask(null);
            DB::table('crm_tasks')->where('id', $closed->id)->update(['status' => $status]);
            $closedSnapshots[$closed->id] = (array) DB::table('crm_tasks')->find($closed->id);
        }
        Sanctum::actingAs($this->admin);
        $url = '/api/users/'.$employee->id.'/group-transfer';
        $preview = $this->getJson($url)->assertOk()->assertJsonCount(2, 'records')->json();
        $payload = ['branch_group_id' => $this->b->id, 'revision' => $preview['revision'], 'reason' => 'Move active workload',
            'records' => [['type' => 'clients', 'id' => $client->id, 'action' => 'move'],
                ['type' => 'tasks', 'id' => $active->id, 'action' => 'retain', 'responsible_user_id' => $replacement->id]]];
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonPath('code', 'TASK_MUST_FOLLOW_PARENT_GROUP');
        $this->assertSame($this->a->id, (int) $client->fresh()->branch_group_id);
        $this->assertSame($this->a->id, (int) $active->fresh()->branch_group_id);
        $this->assertSame($this->a->id, (int) $employee->fresh()->branch_group_id);
        $this->assertDatabaseCount('group_access_audit_logs', 0);
        $payload['records'][1] = ['type' => 'tasks', 'id' => $active->id, 'action' => 'move'];
        $this->postJson($url, $payload)->assertOk();
        $this->assertSame($this->b->id, (int) $client->fresh()->branch_group_id);
        $this->assertSame($this->b->id, (int) $active->fresh()->branch_group_id);
        $this->assertSame($this->a->id, (int) $history->fresh()->branch_group_id);
        $this->assertSame($employee->id, (int) $history->fresh()->assignee_id);
        foreach ($closedSnapshots as $id => $snapshot) {
            $this->assertSame($snapshot, (array) DB::table('crm_tasks')->find($id), 'Closed task without completion date must retain its history.');
            $this->assertDatabaseMissing('group_access_audit_logs', ['subject_type' => 'crm_tasks', 'subject_id' => $id]);
        }
        $taskAudit = DB::table('group_access_audit_logs')->where('subject_type', 'crm_tasks')->where('subject_id', $active->id)->get();
        $this->assertTrue($taskAudit->contains(fn ($entry) =>
            (json_decode($entry->old_values, true)['branch_group_id'] ?? null) === $this->a->id
            && (json_decode($entry->new_values, true)['branch_group_id'] ?? null) === $this->b->id
        ), 'Task audit must record the actual old group before the parent cascade.');
    }

    public function test_employee_transfer_rolls_back_every_change_on_invalid_replacement_and_rejects_stale_preview(): void
    {
        $employee = $this->user('agent', $this->a);
        $invalid = $this->user('agent', $this->c);
        $clients = collect(['First', 'Second'])->map(fn ($name) => Client::create(['full_name' => $name,
            'branch_id' => $this->branch->id, 'branch_group_id' => $this->a->id, 'responsible_agent_id' => $employee->id]));
        Sanctum::actingAs($this->admin);
        $url = '/api/users/'.$employee->id.'/group-transfer';
        $preview = $this->getJson($url)->assertOk()->json();
        $payload = ['branch_group_id' => $this->b->id, 'revision' => $preview['revision'], 'reason' => 'Team reassignment',
            'records' => [['type' => 'clients', 'id' => $clients[0]->id, 'action' => 'move'],
                ['type' => 'clients', 'id' => $clients[1]->id, 'action' => 'retain', 'responsible_user_id' => $invalid->id]]];
        $this->postJson($url, $payload)->assertUnprocessable();
        $this->assertSame($this->a->id, (int) $clients[0]->fresh()->branch_group_id);
        $this->assertSame($this->a->id, (int) $employee->fresh()->branch_group_id);
        $this->assertDatabaseCount('group_access_audit_logs', 0);
        $clients[0]->update(['full_name' => 'Changed after preview']);
        $this->postJson($url, $payload)->assertConflict();
        Sanctum::actingAs($this->rop);
        $this->getJson($url)->assertNotFound();
    }

    public static function globalPipelineTransferActors(): array
    {
        return [['superadmin', false], ['rop', true], ['rop_ungrouped', false]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('globalPipelineTransferActors')]
    public function test_employee_transfer_preserves_global_sales_pipeline(string $role, bool $grouped): void
    {
        Schema::create('crm_deal_pipelines', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('code')->nullable(); $t->string('type')->nullable();
            $t->unsignedBigInteger('branch_id')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps();
        });
        Schema::create('crm_deal_stages', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('pipeline_id'); $t->string('name');
            $t->boolean('is_active')->default(true); $t->boolean('is_closed')->default(false); $t->timestamps();
        });
        Schema::create('crm_deals', function (Blueprint $t) {
            $t->id(); $t->string('title'); $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('branch_group_id')->nullable(); $t->unsignedBigInteger('responsible_agent_id');
            $t->unsignedBigInteger('pipeline_id'); $t->unsignedBigInteger('stage_id');
            $t->timestamp('closed_at')->nullable(); $t->softDeletes(); $t->timestamps();
        });
        if ($role === 'rop_ungrouped') {
            Schema::table('roles', fn (Blueprint $t) => $t->json('permissions')->nullable());
            $this->rop->role->forceFill(['permissions' => [...array_values(array_diff($this->rop->role->permissionKeys(),
                ['users.group_transfer', 'records.transfer'])), 'users.ungrouped.view', 'users.ungrouped.assign']])->save();
            $role = 'rop';
        }
        Role::firstOrCreate(['slug' => $role], ['name' => $role]);
        $actor = $role === 'rop' ? $this->rop : $this->user($role);
        $this->rop->supervisedGroups()->attach($this->a->id);
        $receiver = $this->user('rop', $this->b);
        $receiver->supervisedGroups()->attach($this->b->id);
        if ($role === 'rop' && ! $grouped) $this->rop->supervisedGroups()->attach($this->b->id);
        $employee = $this->user('agent', $grouped ? $this->a : null);
        $client = Client::create(['full_name' => 'Active client', 'status' => 'active',
            'branch_id' => $this->branch->id, 'branch_group_id' => $employee->branch_group_id,
            'responsible_agent_id' => $employee->id]);
        $pipeline = \App\Models\DealPipeline::create(['name' => 'Global sales', 'type' => 'sales', 'is_active' => true]);
        $stage = \App\Models\DealStage::create(['name' => 'New', 'pipeline_id' => $pipeline->id, 'is_active' => true, 'is_closed' => false]);
        $dealId = DB::table('crm_deals')->insertGetId(['title' => 'Active deal', 'branch_id' => $this->branch->id,
            'branch_group_id' => $employee->branch_group_id, 'responsible_agent_id' => $employee->id,
            'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($actor);
        $url = '/api/users/'.$employee->id.'/group-transfer';
        $preview = $this->getJson($url)->assertOk()->json();
        $payload = ['branch_group_id' => $this->b->id, 'revision' => $preview['revision'], 'reason' => 'Move to another team',
            'records' => [['type' => 'clients', 'id' => $client->id, 'action' => 'move'],
                ['type' => 'deals', 'id' => $dealId, 'action' => 'move']]];

        // Invalid pipelines must still reject the entire operation, including earlier client updates.
        foreach ([['branch_id' => 999], ['is_active' => false], ['code' => 'hr_recruitment'], ['type' => 'property_control']] as $invalid) {
            $invalidPipeline = \App\Models\DealPipeline::create(['name' => 'Invalid target', 'type' => 'sales', 'is_active' => true, ...$invalid]);
            $invalidStage = \App\Models\DealStage::create(['name' => 'New', 'pipeline_id' => $invalidPipeline->id, 'is_active' => true, 'is_closed' => false]);
            $invalidPayload = $payload;
            $invalidPayload['records'][1] += ['pipeline_id' => $invalidPipeline->id, 'stage_id' => $invalidStage->id];
            $this->postJson($url, $invalidPayload)->assertUnprocessable()->assertJsonPath('code', 'TRANSFER_PIPELINE_REQUIRED')
                ->assertJsonStructure(['details' => ['errors' => ['records.deals:'.$dealId]]]);
            $this->assertSame($grouped ? $this->a->id : null, $employee->fresh()->branch_group_id);
            $this->assertSame($grouped ? $this->a->id : null, $client->fresh()->branch_group_id);
            $this->assertDatabaseCount('group_access_audit_logs', 0);
        }
        $pipeline->forceFill(['branch_id' => null, 'is_active' => true, 'code' => null, 'type' => 'sales'])->save();
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('transferred_records', 2);
        $this->assertSame($this->b->id, $employee->fresh()->branch_group_id);
        $this->assertSame($this->b->id, $client->fresh()->branch_group_id);
        $this->assertDatabaseHas('crm_deals', ['id' => $dealId, 'branch_group_id' => $this->b->id,
            'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'responsible_agent_id' => $employee->id]);
    }

    public function test_rop_sees_ungrouped_team_in_own_branch_and_can_filter_them(): void
    {
        $this->rop->supervisedGroups()->attach($this->a->id);
        $ungrouped = $this->user('agent');
        $grouped = $this->user('agent', $this->a);
        $foreign = $this->user('agent');
        $foreign->forceFill(['branch_id' => Branch::create(['name' => 'Other'])->id])->save();
        Sanctum::actingAs($this->rop);
        $ids = $this->getJson('/api/user?per_page=100')->assertOk()->json('data.*.id');
        $this->assertContains($ungrouped->id, $ids);
        $this->assertContains($grouped->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
        $filtered = $this->getJson('/api/user?without_group=1&per_page=100')->assertOk()->json('data.*.id');
        $this->assertSame([$ungrouped->id], $filtered);
        $this->getJson('/api/users/'.$ungrouped->id.'/group-transfer')->assertNotFound();
    }

    public function test_ungrouped_permissions_are_separate_and_limited_to_own_branch_and_groups(): void
    {
        Schema::table('roles', fn (Blueprint $t) => $t->json('permissions')->nullable());
        $this->rop->supervisedGroups()->attach($this->a->id);
        $defaults = array_values(array_diff($this->rop->role->permissionKeys(), ['users.ungrouped.view']));
        $employee = $this->user('agent');
        $otherGroup = $this->user('agent', $this->b);
        $otherBranch = $this->user('agent');
        $otherBranch->forceFill(['branch_id' => Branch::create(['name' => 'Other'])->id])->save();
        $inactive = $this->user('agent'); $inactive->forceFill(['status' => 'inactive'])->save();
        $admin = $this->user('admin');
        $client = Client::create(['full_name' => 'Unclassified', 'status' => 'active',
            'branch_id' => $this->branch->id, 'responsible_agent_id' => $employee->id]);
        $url = '/api/users/'.$employee->id.'/group-transfer';
        $grant = function (array $extra) use ($defaults) {
            $this->rop->role->forceFill(['permissions' => array_values(array_unique([...$defaults, ...$extra]))])->save();
            Sanctum::actingAs($this->rop->fresh());
        };
        $grant([]);
        $this->getJson('/api/user/'.$employee->id)->assertNotFound();
        $grant(['users.ungrouped.assign']);
        $this->getJson($url)->assertNotFound();
        $grant(['users.ungrouped.view']);
        $ids = $this->getJson('/api/user?per_page=100')->assertOk()->json('data.*.id');
        $this->assertContains($employee->id, $ids);
        foreach ([$otherGroup, $otherBranch, $inactive, $admin] as $hidden) {
            $this->assertNotContains($hidden->id, $ids);
            $this->getJson('/api/user/'.$hidden->id)->assertNotFound();
        }
        $this->getJson('/api/user/'.$employee->id)->assertOk();
        $this->getJson($url)->assertNotFound();
        $this->patchJson('/api/user/'.$employee->id, ['name' => 'Updated employee'])->assertOk();
        $this->assertSame('Updated employee', $employee->fresh()->name);
        $this->assertNull($employee->fresh()->branch_group_id);
        foreach (['branch_group_id' => $this->a->id, 'branch_id' => $otherBranch->branch_id, 'status' => 'inactive', 'role_id' => $admin->role_id] as $field => $value) {
            $this->patchJson('/api/user/'.$employee->id, [$field => $value])->assertForbidden();
        }
        $this->patchJson('/api/user/'.$otherBranch->id, ['name' => 'Forbidden'])->assertNotFound();
        $this->patchJson('/api/user/'.$otherGroup->id, ['name' => 'Forbidden'])->assertNotFound();
        $grant(['users.ungrouped.view', 'users.ungrouped.assign']);
        $preview = $this->getJson($url)->assertOk()->json();
        $this->getJson('/api/employee-transfer-destinations?initial_assignment=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->a->id);
        $payload = ['branch_group_id' => $this->b->id, 'revision' => $preview['revision'], 'reason' => 'Initial team assignment',
            'records' => [['type' => 'clients', 'id' => $client->id, 'action' => 'move']]];
        $this->postJson($url, $payload)->assertForbidden();
        $this->assertNull($employee->fresh()->branch_group_id);
        $payload['branch_group_id'] = $this->a->id;
        $grant(['users.ungrouped.view']);
        $this->postJson($url, $payload)->assertNotFound();
        $grant(['users.ungrouped.view', 'users.ungrouped.assign']);
        $this->postJson($url, [...$payload, 'records' => []])->assertUnprocessable();
        DB::table('clients')->where('id', $client->id)->update(['branch_group_id' => $this->b->id]);
        $this->getJson($url)->assertNotFound();
        DB::table('clients')->where('id', $client->id)->update(['branch_group_id' => null]);
        Schema::create('crm_tasks', function (Blueprint $t) {
            $t->id(); $t->string('title'); $t->unsignedBigInteger('assignee_id')->nullable();
            $t->unsignedBigInteger('branch_group_id')->nullable(); $t->string('status')->nullable();
            $t->timestamp('completed_at')->nullable(); $t->string('related_entity_type');
            $t->unsignedBigInteger('related_entity_id'); $t->timestamps();
        });
        $taskId = DB::table('crm_tasks')->insertGetId(['title' => 'Foreign related task',
            'assignee_id' => $otherGroup->id, 'branch_group_id' => $this->b->id,
            'related_entity_type' => 'client', 'related_entity_id' => $client->id]);
        $this->postJson($url, $payload)->assertForbidden();
        $this->assertNull($employee->fresh()->branch_group_id);
        $this->assertNull($client->fresh()->branch_group_id);
        DB::table('crm_tasks')->where('id', $taskId)->update(['assignee_id' => null, 'branch_group_id' => null]);
        $this->postJson($url, $payload)->assertOk();
        $this->assertDatabaseHas('crm_tasks', ['id' => $taskId, 'assignee_id' => $employee->id, 'branch_group_id' => $this->a->id]);
        $this->assertSame($this->a->id, $employee->fresh()->branch_group_id);
        $this->assertSame($this->a->id, $client->fresh()->branch_group_id);
        $this->assertDatabaseHas('group_access_audit_logs', ['subject_id' => $employee->id, 'event' => 'employee_group_transferred']);
    }

}

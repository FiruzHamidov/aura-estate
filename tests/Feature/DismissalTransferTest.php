<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DismissalTransferTest extends TestCase
{
    private User $actor;

    private User $employee;

    private User $first;

    private User $second;

    private BranchGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug');
            $t->timestamps();
        });
        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('branch_groups', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id');
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('phone');
            $t->unsignedBigInteger('role_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('branch_group_id')->nullable();
            $t->string('status')->default('active');
            $t->string('password')->nullable();
            $t->rememberToken();
            $t->timestamp('deleted_at')->nullable();
            foreach (['telegram_id', 'telegram_username', 'telegram_photo_url', 'telegram_chat_id', 'telegram_linked_at'] as $field) {
                $t->string($field)->nullable();
            }
            $t->timestamps();
        });
        Schema::create('properties', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->string('moderation_status');
            $t->unsignedInteger('moderation_version')->default(1);
            foreach (['agent_id', 'created_by', 'co_owner_user_id', 'branch_group_id', 'branch_id'] as $field) {
                $t->unsignedBigInteger($field)->nullable();
            }
            $t->timestamps();
        });
        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->string('full_name');
            $t->softDeletes();
            $t->string('status')->default('active');
            foreach (['responsible_agent_id', 'branch_group_id', 'branch_id'] as $field) {
                $t->unsignedBigInteger($field)->nullable();
            }
            $t->timestamps();
        });
        Schema::create('client_needs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('responsible_agent_id');
            $t->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->morphs('tokenable');
            $t->string('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        (require database_path('migrations/2026_09_08_120000_create_rop_group_access.php'))->up();
        foreach (['admin', 'agent', 'mop', 'hr', 'branch_director', 'rop', 'intern'] as $role) {
            Role::create(['name' => $role, 'slug' => $role]);
        }
        $branch = Branch::create(['name' => 'Branch']);
        $this->group = BranchGroup::create(['name' => 'Group', 'branch_id' => $branch->id]);
        $this->actor = $this->person('admin');
        $this->employee = $this->person('agent');
        $this->first = $this->person('agent');
        $this->second = $this->person('mop');
        foreach ([1, 2, 3] as $id) {
            DB::table('properties')->insert(['id' => $id, 'title' => 'Property '.$id,
                'agent_id' => $this->employee->id, 'created_by' => $this->employee->id, 'co_owner_user_id' => $this->first->id,
                'branch_group_id' => $this->group->id, 'branch_id' => $branch->id, 'moderation_status' => $id === 3 ? 'sold' : 'approved']);
        }
        DB::table('clients')->insert(['id' => 1, 'full_name' => 'Client', 'responsible_agent_id' => $this->employee->id,
            'branch_id' => $branch->id, 'branch_group_id' => $this->group->id]);
        DB::table('client_needs')->insert(['client_id' => 1, 'responsible_agent_id' => $this->employee->id]);
        Sanctum::actingAs($this->actor);
    }

    public function test_author_owned_unassigned_property_is_included_in_handover_without_reassigning_transferred_history(): void
    {
        DB::table('properties')->where('id', 1)->update(['agent_id' => null]);
        DB::table('properties')->where('id', 2)->update(['agent_id' => $this->first->id]);
        $preview = $this->preview();
        $ids = array_column(array_filter($preview['records'], fn ($r) => $r['type'] === 'properties'), 'id');
        $this->assertSame([1], $ids);
        $this->assertDatabaseHas('properties', ['id' => 1, 'agent_id' => null]);
        $plan = ['transfer_plan' => ['revision' => $preview['revision'], 'reason' => 'Assign orphaned active records',
            'records' => array_map(fn ($r) => ['type' => $r['type'], 'id' => $r['id'], 'responsible_user_id' => $this->first->id], $preview['records'])]];
        $this->deleteJson('/api/user/'.$this->employee->id, $plan)->assertOk();
        $this->assertDatabaseHas('properties', ['id' => 1, 'agent_id' => $this->first->id, 'created_by' => $this->employee->id]);
        $this->assertDatabaseHas('properties', ['id' => 3, 'agent_id' => $this->employee->id, 'moderation_status' => 'sold']);
    }

    public function test_rop_employee_transfer_moves_properties_bookings_and_tasks_with_their_owner(): void
    {
        Schema::create('crm_deals', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('primary_property_id')->nullable(); $t->string('control_kind')->nullable(); $t->timestamp('closed_at')->nullable(); $t->softDeletes();
        });
        Schema::create('bookings', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('agent_id'); $t->unsignedBigInteger('branch_group_id'); $t->timestamp('end_time'); $t->timestamps();
        });
        Schema::create('crm_tasks', function (Blueprint $t) {
            $t->id(); $t->string('title'); $t->unsignedBigInteger('assignee_id'); $t->unsignedBigInteger('branch_group_id');
            $t->string('related_entity_type')->nullable(); $t->unsignedBigInteger('related_entity_id')->nullable();
            $t->timestamp('completed_at')->nullable(); $t->string('status')->nullable(); $t->timestamps();
        });
        $rop = $this->person('rop');
        $rop->supervisedGroups()->attach($this->group->id);
        $destination = BranchGroup::create(['name' => 'New team', 'branch_id' => $this->group->branch_id]);
        $receiver = $this->person('rop');
        $receiver->supervisedGroups()->attach($destination->id);
        DB::table('bookings')->insert(['agent_id' => $this->employee->id, 'branch_group_id' => $this->group->id, 'end_time' => now()->addDay()]);
        DB::table('crm_tasks')->insert(['title' => 'Property follow-up', 'assignee_id' => $this->employee->id,
            'branch_group_id' => $this->group->id, 'related_entity_type' => 'property', 'related_entity_id' => 1]);
        Sanctum::actingAs($rop);
        DB::table('properties')->where('id', 1)->update(['agent_id' => null]);
        $url = '/api/users/'.$this->employee->id.'/group-transfer';
        $preview = $this->getJson($url)->assertOk()->json();
        $plans = array_map(fn ($row) => ['type' => $row['type'], 'id' => $row['id'], 'action' => 'move'], $preview['records']);
        $this->postJson($url, ['branch_group_id' => $destination->id, 'revision' => $preview['revision'],
            'reason' => 'Transfer employee workload', 'records' => $plans])->assertOk();
        foreach ([1, 2] as $id) {
            $this->assertDatabaseHas('properties', ['id' => $id, 'branch_group_id' => $destination->id, 'agent_id' => $this->employee->id, 'co_owner_user_id' => $this->first->id]);
        }
        $this->assertDatabaseHas('properties', ['id' => 3, 'branch_group_id' => $this->group->id]);
        $this->assertDatabaseHas('bookings', ['agent_id' => $this->employee->id, 'branch_group_id' => $destination->id]);
        $this->assertDatabaseHas('crm_tasks', ['assignee_id' => $this->employee->id, 'branch_group_id' => $destination->id]);
    }

    private function person(string $role): User
    {
        return User::create(['name' => $role, 'phone' => (string) random_int(900000000, 999999999),
            'role_id' => Role::where('slug', $role)->value('id'), 'status' => 'active',
            'branch_id' => $this->group->branch_id, 'branch_group_id' => $this->group->id]);
    }

    private function preview(): array
    {
        return $this->getJson('/api/user/'.$this->employee->id.'/dismissal-preview')->assertOk()->json();
    }

    private function plan(): array
    {
        $preview = $this->preview();

        return ['transfer_plan' => ['revision' => $preview['revision'], 'reason' => 'Employee dismissal',
            'records' => array_map(fn ($r) => ['type' => $r['type'], 'id' => $r['id'],
                'responsible_user_id' => $r['type'] === 'clients' || $r['id'] === 2 ? $this->second->id : $this->first->id], $preview['records'])]];
    }

    public function test_custom_recipient_eligibility_is_rechecked_when_applying_dismissal(): void
    {
        Schema::table('roles', fn (Blueprint $t) => $t->json('permissions')->nullable());
        $role = Role::create(['name' => 'Receiver', 'slug' => 'custom_receiver']);
        $role->forceFill(['permissions' => ['users.subject.record_recipient']])->save();
        DB::table('users')->where('id', $this->first->id)->update(['role_id' => $role->id]);
        $plan = $this->plan();
        $this->assertContains($this->first->id, $this->preview()['records'][0]['eligible_user_ids']);
        $role->forceFill(['permissions' => []])->save();
        $this->assertNotContains($this->first->id, $this->preview()['records'][0]['eligible_user_ids']);
        $this->deleteJson('/api/user/'.$this->employee->id, $plan)->assertUnprocessable();
        $this->assertDatabaseHas('users', ['id' => $this->employee->id, 'status' => 'active']);
        $this->assertDatabaseHas('properties', ['id' => 1, 'agent_id' => $this->employee->id]);
        $this->assertDatabaseCount('group_access_audit_logs', 0);
        $role->forceFill(['permissions' => ['users.subject.record_recipient']])->save();
        $this->deleteJson('/api/user/'.$this->employee->id, $plan)->assertOk();
        $this->assertDatabaseHas('properties', ['id' => 1, 'agent_id' => $this->first->id]);
    }

    public function test_revoked_recipient_policy_does_not_bypass_existing_record_handover(): void
    {
        Schema::table('roles', fn (Blueprint $t) => $t->json('permissions')->nullable());
        $role = Role::create(['name' => 'Former receiver', 'slug' => 'former_receiver']);
        $role->forceFill(['permissions' => []])->save();
        DB::table('users')->where('id', $this->employee->id)->update(['role_id' => $role->id]);
        $this->patchJson('/api/user/'.$this->employee->id, ['status' => 'inactive'])->assertStatus(409);
        $this->assertDatabaseHas('users', ['id' => $this->employee->id, 'status' => 'active']);
        $this->assertDatabaseHas('properties', ['id' => 1, 'agent_id' => $this->employee->id]);
    }

    public function test_dismissal_transfers_individual_records_clients_and_needs_atomically(): void
    {
        $token = $this->employee->createToken('employee');
        $this->deleteJson('/api/user/'.$this->employee->id, $this->plan())->assertOk()
            ->assertJsonPath('transferred_counts.properties', 2)->assertJsonPath('clients_transferred_count', 1);
        $this->assertDatabaseHas('properties', ['id' => 1, 'agent_id' => $this->first->id, 'co_owner_user_id' => null, 'moderation_version' => 2]);
        $this->assertDatabaseHas('properties', ['id' => 2, 'agent_id' => $this->second->id]);
        $this->assertDatabaseHas('properties', ['id' => 3, 'agent_id' => $this->employee->id]);
        $this->assertDatabaseHas('clients', ['id' => 1, 'responsible_agent_id' => $this->second->id]);
        $this->assertDatabaseHas('client_needs', ['client_id' => 1, 'responsible_agent_id' => $this->second->id]);
        $this->assertDatabaseHas('users', ['id' => $this->employee->id, 'status' => 'inactive']);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertDatabaseCount('group_access_audit_logs', 3);
    }

    public static function invalidPlans(): array
    {
        return array_map(fn ($case) => [$case], ['missing', 'duplicate', 'foreign', 'self', 'inactive', 'wrong_group', 'wrong_role', 'stale', 'new_record', 'empty_plan']);
    }

    #[DataProvider('invalidPlans')]
    public function test_rejected_plan_preserves_records_access_and_audit(string $case): void
    {
        $payload = $this->plan();
        $token = $this->employee->createToken('employee');
        $status = 422;
        switch ($case) {
            case 'missing': array_pop($payload['transfer_plan']['records']);
                break;
            case 'duplicate': $payload['transfer_plan']['records'][] = $payload['transfer_plan']['records'][0];
                break;
            case 'foreign': $payload['transfer_plan']['records'][2]['id'] = 999;
                break;
            case 'self': $payload['transfer_plan']['records'][2]['responsible_user_id'] = $this->employee->id;
                break;
            case 'inactive': DB::table('users')->where('id', $this->second->id)->update(['status' => 'inactive']);
                break;
            case 'wrong_group': DB::table('users')->where('id', $this->second->id)->update(['branch_group_id' => null]);
                break;
            case 'wrong_role': DB::table('users')->where('id', $this->second->id)->update(['role_id' => $this->actor->role_id]);
                break;
            case 'stale': DB::table('properties')->where('id', 1)->update(['title' => 'Changed']);
                $status = 409;
                break;
            case 'new_record': DB::table('clients')->insert(['full_name' => 'New', 'responsible_agent_id' => $this->employee->id]);
                $status = 409;
                break;
            case 'empty_plan': $payload['transfer_plan'] = [];
                break;
        }
        $this->deleteJson('/api/user/'.$this->employee->id, $payload)->assertStatus($status);
        $this->assertDatabaseHas('properties', ['id' => 1, 'agent_id' => $this->employee->id]);
        $this->assertDatabaseHas('clients', ['id' => 1, 'responsible_agent_id' => $this->employee->id]);
        $this->assertDatabaseHas('users', ['id' => $this->employee->id, 'status' => 'active']);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertDatabaseCount('group_access_audit_logs', 0);
    }

    public function test_hr_can_use_the_same_dismissal_plan(): void
    {
        Sanctum::actingAs($this->person('hr'));
        $this->deleteJson('/api/user/'.$this->employee->id, $this->plan())->assertOk();
    }

    public function test_rop_cannot_preview_or_submit_a_dismissal(): void
    {
        $plan = $this->plan();
        Sanctum::actingAs($this->person('rop'));
        $this->getJson('/api/user/'.$this->employee->id.'/dismissal-preview')->assertStatus(404);
        $this->deleteJson('/api/user/'.$this->employee->id, $plan)->assertForbidden();
    }

    private function actingAsGroupRop(): User
    {
        $rop = $this->person('rop');
        $rop->supervisedGroups()->attach($this->group->id);
        Sanctum::actingAs($rop);

        return $rop;
    }

    public static function ropEmployeeRoles(): array
    {
        return [['agent'], ['mop'], ['intern']];
    }

    #[DataProvider('ropEmployeeRoles')]
    public function test_rop_dismisses_own_group_employee_with_reviewed_transfer(string $role): void
    {
        $employeeRole = Role::firstOrCreate(['slug' => $role], ['name' => $role]);
        $this->employee->update(['role_id' => $employeeRole->id]);
        $this->actingAsGroupRop();
        $this->test_dismissal_transfers_individual_records_clients_and_needs_atomically();
    }

    public function test_rop_can_dismiss_employee_without_records(): void
    {
        $this->actingAsGroupRop();
        $this->employee = $this->person('intern');
        $this->deleteJson('/api/user/'.$this->employee->id, $this->plan())->assertOk();
        $this->assertSame('inactive', $this->employee->fresh()->status);
    }

    public function test_rop_preview_hides_unassigned_group_recipients_and_rejects_tampered_plan(): void
    {
        $other = BranchGroup::create(['name' => 'Other', 'branch_id' => $this->group->branch_id]);
        $this->second->update(['branch_group_id' => $other->id]);
        $this->actingAsGroupRop();
        $preview = $this->preview();
        $this->assertSame([$this->first->id], array_column($preview['recipients'], 'id'));
        $this->deleteJson('/api/user/'.$this->employee->id, $this->plan())->assertForbidden();
        $this->assertSame('active', $this->employee->fresh()->status);
        $this->assertDatabaseHas('properties', ['id' => 1, 'agent_id' => $this->employee->id]);
    }

    public function test_rop_cannot_dismiss_foreign_group_employee_or_peer_or_self(): void
    {
        $rop = $this->actingAsGroupRop();
        $peer = $this->person('rop');
        $foreign = $this->person('agent');
        $other = BranchGroup::create(['name' => 'Other', 'branch_id' => $this->group->branch_id]);
        $foreign->update(['branch_group_id' => $other->id]);
        foreach ([$rop, $peer, $foreign] as $target) {
            $this->getJson('/api/user/'.$target->id.'/dismissal-preview')->assertNotFound();
            $this->deleteJson('/api/user/'.$target->id, ['distribute_to_agents' => true])->assertNotFound();
            $this->assertSame('active', $target->fresh()->status);
        }
    }

    public function test_rop_scope_is_rechecked_after_preview_and_legacy_dismissal_is_blocked(): void
    {
        $rop = $this->actingAsGroupRop();
        $plan = $this->plan();
        $this->deleteJson('/api/user/'.$this->employee->id, ['distribute_to_agents' => true])->assertStatus(422);
        $rop->supervisedGroups()->detach();
        $this->deleteJson('/api/user/'.$this->employee->id, $plan)->assertForbidden();
        $this->assertSame('active', $this->employee->fresh()->status);
    }

    public function test_rop_cannot_transfer_foreign_or_unclassified_records_or_edit_employee(): void
    {
        $permissions = config('permissions');
        $permissions['users.dismissal.employee_records']['roles'] = [];
        config(['permissions' => $permissions]);
        $this->actingAsGroupRop();
        $plan = $this->plan();
        $other = BranchGroup::create(['name' => 'Other', 'branch_id' => $this->group->branch_id]);
        foreach ([$other->id, null] as $groupId) {
            DB::table('clients')->where('id', 1)->update(['branch_group_id' => $groupId]);
            $preview = $this->getJson('/api/user/'.$this->employee->id.'/dismissal-preview')->assertOk()
                ->assertJsonPath('can_submit', false)->assertJsonPath('blocked_records_count', 1);
            $this->assertEmpty(array_filter($preview->json('records'), fn ($r) => $r['type'] === 'clients' && $r['id'] === 1));
            $this->deleteJson('/api/user/'.$this->employee->id, $plan)->assertForbidden();
            $this->assertSame('active', $this->employee->fresh()->status);
            $this->assertDatabaseHas('properties', ['id' => 1, 'agent_id' => $this->employee->id]);
        }
        $this->putJson('/api/user/'.$this->employee->id, ['name' => 'Changed'])->assertOk();
        $this->postJson('/api/user/'.$this->employee->id.'/restore')->assertForbidden();
    }

    public static function employeeRecordManagers(): array
    {
        return [['rop', 'agent'], ['branch_director', 'agent'], ['branch_director', 'rop']];
    }

    #[DataProvider('employeeRecordManagers')]
    public function test_manager_can_transfer_all_own_employee_records_with_dedicated_permission(string $role, string $employeeRole): void
    {
        Sanctum::actingAs($role === 'rop' ? $this->actingAsGroupRop() : $this->person($role));
        $this->employee->update(['role_id' => Role::where('slug', $employeeRole)->value('id')]);
        $otherBranch = Branch::create(['name' => 'Other branch']);
        $otherGroup = BranchGroup::create(['name' => 'Other group', 'branch_id' => $otherBranch->id]);
        DB::table('properties')->where('id', 1)->update(['agent_id' => null, 'branch_id' => $otherBranch->id, 'branch_group_id' => $otherGroup->id]);
        DB::table('properties')->where('id', 2)->update(['branch_id' => null, 'branch_group_id' => null]);
        DB::table('clients')->where('id', 1)->update(['branch_id' => $otherBranch->id, 'branch_group_id' => $otherGroup->id]);
        DB::table('clients')->insert(['id' => 2, 'full_name' => 'Unclassified', 'responsible_agent_id' => $this->employee->id]);
        DB::table('properties')->insert(['id' => 4, 'title' => 'Already transferred', 'moderation_status' => 'approved',
            'created_by' => $this->employee->id, 'agent_id' => $this->second->id,
            'branch_id' => $otherBranch->id, 'branch_group_id' => $otherGroup->id]);
        Schema::create('crm_deals', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('primary_property_id')->nullable(); $t->string('control_kind')->nullable(); $t->timestamp('closed_at')->nullable(); $t->softDeletes();
        });
        $preview = $this->preview();
        $this->assertTrue($preview['can_submit']);
        $this->assertSame(0, $preview['blocked_records_count']);
        $this->assertCount(4, $preview['records']);
        $this->assertSame('active', $this->employee->fresh()->status);
        $records = array_map(fn ($r) => ['type' => $r['type'], 'id' => $r['id'],
            'responsible_user_id' => $this->first->id, 'destination_branch_group_id' => $this->group->id], $preview['records']);
        $this->deleteJson('/api/user/'.$this->employee->id, ['transfer_plan' => [
            'revision' => $preview['revision'], 'reason' => 'Reviewed employee handover', 'records' => $records,
        ]])->assertOk();
        $this->assertDatabaseHas('properties', ['id' => 1, 'agent_id' => $this->first->id, 'branch_group_id' => $this->group->id, 'branch_id' => $this->group->branch_id]);
        $this->assertDatabaseHas('properties', ['id' => 2, 'agent_id' => $this->first->id, 'branch_group_id' => $this->group->id, 'branch_id' => $this->group->branch_id]);
        $this->assertDatabaseHas('clients', ['id' => 1, 'responsible_agent_id' => $this->first->id, 'branch_group_id' => $this->group->id]);
        $this->assertDatabaseHas('clients', ['id' => 2, 'responsible_agent_id' => $this->first->id, 'branch_group_id' => $this->group->id]);
        $this->assertDatabaseHas('client_needs', ['client_id' => 1, 'responsible_agent_id' => $this->first->id]);
        $this->assertDatabaseHas('properties', ['id' => 3, 'agent_id' => $this->employee->id, 'moderation_status' => 'sold']);
        $this->assertDatabaseHas('properties', ['id' => 4, 'agent_id' => $this->second->id, 'branch_id' => $otherBranch->id]);
        $this->assertSame('inactive', $this->employee->fresh()->status);
    }

    #[DataProvider('employeeRecordManagers')]
    public function test_extended_dismissal_permission_does_not_allow_regular_record_transfer_and_is_rechecked(string $role, string $employeeRole): void
    {
        $actor = $role === 'rop' ? $this->actingAsGroupRop() : $this->person($role);
        Sanctum::actingAs($actor);
        $this->employee->update(['role_id' => Role::where('slug', $employeeRole)->value('id')]);
        $otherBranch = Branch::create(['name' => 'Other branch']);
        DB::table('clients')->where('id', 1)->update(['branch_id' => $otherBranch->id, 'branch_group_id' => null]);
        DB::table('properties')->where('id', 1)->update(['branch_id' => $otherBranch->id, 'branch_group_id' => null]);
        $plan = $this->plan();
        foreach (['clients', 'properties'] as $type) {
            try {
                app(\App\Services\GroupAccess\GroupRecordTransfer::class)->preview($actor, $type, 1);
                $this->fail('Ordinary transfer must retain branch/group scope');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertContains($e->getStatusCode(), [403, 404]);
            }
        }
        $permissions = config('permissions');
        $permissions['users.dismissal.employee_records']['roles'] = [];
        config(['permissions' => $permissions]);
        $this->deleteJson('/api/user/'.$this->employee->id, $plan)->assertForbidden();
        $this->assertSame('active', $this->employee->fresh()->status);
        $this->assertDatabaseHas('clients', ['id' => 1, 'responsible_agent_id' => $this->employee->id, 'branch_group_id' => null]);
    }

    public function test_director_without_employee_records_permission_cannot_transfer_foreign_records(): void
    {
        $permissions = config('permissions');
        $permissions['users.dismissal.employee_records']['roles'] = ['rop'];
        config(['permissions' => $permissions]);
        Sanctum::actingAs($this->person('branch_director'));
        $plan = $this->plan();
        $otherBranch = Branch::create(['name' => 'Other']);
        DB::table('clients')->where('id', 1)->update(['branch_id' => $otherBranch->id]);
        $this->getJson('/api/user/'.$this->employee->id.'/dismissal-preview')->assertOk()
            ->assertJsonPath('can_submit', false)->assertJsonPath('blocked_records_count', 1);
        $this->deleteJson('/api/user/'.$this->employee->id, $plan)->assertForbidden();
        $this->assertDatabaseHas('properties', ['id' => 1, 'agent_id' => $this->employee->id]);
        $this->assertDatabaseHas('users', ['id' => $this->employee->id, 'status' => 'active']);
    }

    public function test_preview_excludes_self_inactive_and_other_group_recipients(): void
    {
        $inactive = $this->person('agent');
        $inactive->update(['status' => 'inactive']);
        $outside = $this->person('agent');
        DB::table('users')->where('id', $outside->id)->update(['branch_group_id' => null]);
        $preview = $this->preview();
        $this->assertCount(3, $preview['records']);
        foreach ($preview['records'] as $record) {
            $this->assertSame([$this->first->id, $this->second->id], $record['eligible_user_ids']);
        }
    }

    public function test_empty_inventory_needs_no_recipient(): void
    {
        DB::table('properties')->delete();
        DB::table('clients')->delete();
        $this->deleteJson('/api/user/'.$this->employee->id, $this->plan())->assertOk()->assertJsonPath('clients_transferred_count', 0);
    }

    public function test_unclassified_records_can_be_transferred_to_unclassified_agents(): void
    {
        DB::table('users')->update(['branch_group_id' => null]);
        DB::table('properties')->update(['branch_group_id' => null]);
        DB::table('clients')->update(['branch_group_id' => null]);
        $this->deleteJson('/api/user/'.$this->employee->id, $this->plan())->assertOk();
    }
    public function test_preview_includes_workload_location_and_eligible_co_owner(): void
    {
        DB::table('properties')->insert(['title' => 'Recipient workload', 'agent_id' => $this->first->id,
            'moderation_status' => 'approved', 'branch_id' => $this->group->branch_id, 'branch_group_id' => $this->group->id]);
        $preview = $this->preview();
        $this->assertSame($this->first->id, $preview['records'][0]['preferred_user_id']);
        $recipient = collect($preview['recipients'])->firstWhere('id', $this->first->id);
        $this->assertSame(1, $recipient['approved_properties_count']);
        $this->assertSame('Branch', $recipient['branch_name']);
        $this->assertSame('Group', $recipient['group_name']);
    }

    private function crossBranchTarget(): User
    {
        $branch = Branch::create(['name' => 'Destination']);
        $group = BranchGroup::create(['name' => 'New team', 'branch_id' => $branch->id]);
        $target = $this->person('agent');
        DB::table('users')->where('id', $target->id)->update(['branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        return $target->fresh();
    }

    public function test_cross_branch_property_requires_explicit_destination_and_allows_clients(): void
    {
        $target = $this->crossBranchTarget();
        $preview = $this->preview();
        $this->assertContains($target->id, $preview['records'][0]['eligible_user_ids']);
        $this->assertContains($target->id, collect($preview['records'])->firstWhere('type', 'clients')['eligible_user_ids']);
        $plan = $this->plan();
        $plan['transfer_plan']['records'][0]['responsible_user_id'] = $target->id;
        $this->deleteJson('/api/user/'.$this->employee->id, $plan)->assertUnprocessable();
        $this->assertDatabaseHas('properties', ['id' => 1, 'agent_id' => $this->employee->id]);
        $this->assertSame('active', $this->employee->fresh()->status);
    }

    public function test_director_and_hr_cannot_move_properties_to_another_branch(): void
    {
        $target = $this->crossBranchTarget();
        foreach (['branch_director', 'hr'] as $role) {
            Sanctum::actingAs($this->person($role));
            $preview = $this->preview();
            $this->assertNotContains($target->id, $preview['records'][0]['eligible_user_ids']);
            $plan = $this->plan();
            $plan['transfer_plan']['records'][0]['responsible_user_id'] = $target->id;
            $plan['transfer_plan']['records'][0]['destination_branch_group_id'] = $target->branch_group_id;
            $this->deleteJson('/api/user/'.$this->employee->id, $plan)->assertUnprocessable();
            $this->assertSame('active', $this->employee->fresh()->status);
        }
    }

    public function test_director_cannot_move_clients_outside_branch(): void
    {
        $target = $this->crossBranchTarget();
        Sanctum::actingAs($this->person('branch_director'));
        $preview = $this->preview();
        $this->assertNotContains($target->id, collect($preview['records'])->firstWhere('type', 'clients')['eligible_user_ids']);
        $plan = $this->plan();
        foreach ($plan['transfer_plan']['records'] as &$row) {
            if ($row['type'] === 'clients') {
                $row['responsible_user_id'] = $target->id;
                $row['destination_branch_group_id'] = $target->branch_group_id;
            }
        }
        unset($row);
        $this->deleteJson('/api/user/'.$this->employee->id, $plan)->assertUnprocessable();
        $this->assertDatabaseHas('clients', ['id' => 1, 'responsible_agent_id' => $this->employee->id]);
        $this->assertDatabaseCount('group_access_audit_logs', 0);
    }

    public function test_director_cannot_assign_a_foreign_record_to_a_recipient_in_its_foreign_branch(): void
    {
        $outside = $this->crossBranchTarget();
        Sanctum::actingAs($this->person('branch_director'));
        DB::table('clients')->where('id', 1)->update(['branch_id' => $outside->branch_id, 'branch_group_id' => $outside->branch_group_id]);
        $preview = $this->preview();
        $this->assertTrue($preview['can_submit']);
        $this->assertNotContains($outside->id, array_column($preview['recipients'], 'id'));
        $client = collect($preview['records'])->firstWhere('type', 'clients');
        $this->assertSame([$this->first->id, $this->second->id], $client['eligible_user_ids']);
        $plan = $this->plan();
        foreach ($plan['transfer_plan']['records'] as &$row) {
            if ($row['type'] === 'clients') $row['responsible_user_id'] = $outside->id;
        }
        unset($row);
        $this->deleteJson('/api/user/'.$this->employee->id, $plan)->assertUnprocessable();
        $this->assertSame('active', $this->employee->fresh()->status);
        $this->assertDatabaseHas('clients', ['id' => 1, 'responsible_agent_id' => $this->employee->id]);
        $this->assertDatabaseHas('properties', ['id' => 1, 'agent_id' => $this->employee->id]);
        $this->assertDatabaseCount('group_access_audit_logs', 0);
    }

    public static function changedDirectorParticipants(): array
    {
        return [['employee', 403], ['recipient', 422], ['director', 403]];
    }

    #[DataProvider('changedDirectorParticipants')]
    public function test_director_rechecks_participant_branch_after_preview(string $participant, int $status): void
    {
        $actor = $this->person('branch_director');
        Sanctum::actingAs($actor);
        $plan = $this->plan();
        $outside = $this->crossBranchTarget();
        $user = match ($participant) { 'employee' => $this->employee, 'recipient' => $this->first, default => $actor };
        DB::table('users')->where('id', $user->id)->update(['branch_id' => $outside->branch_id, 'branch_group_id' => $outside->branch_group_id]);
        $this->deleteJson('/api/user/'.$this->employee->id, $plan)->assertStatus($status);
        $this->assertSame('active', $this->employee->fresh()->status);
        $this->assertDatabaseHas('properties', ['id' => 1, 'agent_id' => $this->employee->id]);
        $this->assertDatabaseCount('group_access_audit_logs', 0);
    }

    public function test_director_cannot_preview_or_dismiss_a_foreign_employee(): void
    {
        $outside = $this->crossBranchTarget();
        Sanctum::actingAs($this->person('branch_director'));
        $this->getJson('/api/user/'.$outside->id.'/dismissal-preview')->assertForbidden();
        $this->deleteJson('/api/user/'.$outside->id, ['distribute_to_agents' => true])->assertForbidden();
        $this->assertSame('active', $outside->fresh()->status);
    }

    public function test_director_imports_foreign_leads_bookings_and_linked_tasks_during_dismissal(): void
    {
        $this->crossBranchTables();
        $outside = $this->crossBranchTarget();
        Sanctum::actingAs($this->person('branch_director'));
        DB::table('properties')->where('id', 1)->update(['branch_id' => $outside->branch_id, 'branch_group_id' => $outside->branch_group_id]);
        Schema::create('leads', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('responsible_agent_id'); $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('branch_group_id'); $t->timestamp('closed_at')->nullable(); $t->timestamps();
        });
        Schema::create('bookings', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('agent_id'); $t->unsignedBigInteger('branch_group_id');
            $t->timestamp('end_time'); $t->timestamps();
        });
        Schema::create('crm_tasks', function (Blueprint $t) {
            $t->id(); $t->string('title'); $t->unsignedBigInteger('assignee_id'); $t->unsignedBigInteger('branch_group_id');
            $t->string('related_entity_type')->nullable(); $t->unsignedBigInteger('related_entity_id')->nullable();
            $t->string('status')->default('pending'); $t->timestamp('completed_at')->nullable(); $t->timestamps();
        });
        foreach ([1, 2] as $id) {
            DB::table('leads')->insert(['id' => $id, 'responsible_agent_id' => $this->employee->id,
                'branch_id' => $outside->branch_id, 'branch_group_id' => $outside->branch_group_id,
                'closed_at' => $id === 2 ? now() : null]);
            DB::table('bookings')->insert(['id' => $id, 'agent_id' => $this->employee->id,
                'branch_group_id' => $outside->branch_group_id, 'end_time' => $id === 2 ? now()->subDay() : now()->addDay()]);
            DB::table('crm_tasks')->insert(['id' => $id, 'title' => 'Property follow-up', 'assignee_id' => $this->employee->id,
                'branch_group_id' => $outside->branch_group_id, 'related_entity_type' => 'property', 'related_entity_id' => 1,
                'status' => $id === 2 ? 'done' : 'pending']);
        }
        DB::table('crm_tasks')->insert(['id' => 3, 'title' => 'Standalone', 'assignee_id' => $this->employee->id,
            'branch_group_id' => $outside->branch_group_id]);
        $token = $this->employee->createToken('before-dismissal');
        $preview = $this->preview();
        $this->assertTrue($preview['can_submit']);
        $this->assertSame(0, $preview['blocked_records_count']);
        $this->assertCount(7, $preview['records']);
        foreach ($preview['records'] as $record) {
            $this->assertContains($this->first->id, $record['eligible_user_ids']);
            $this->assertNotContains($outside->id, $record['eligible_user_ids']);
        }
        $records = array_map(fn ($record) => ['type' => $record['type'], 'id' => $record['id'],
            'responsible_user_id' => $this->first->id, 'destination_branch_group_id' => $this->group->id], $preview['records']);
        $this->deleteJson('/api/user/'.$this->employee->id, ['transfer_plan' => [
            'revision' => $preview['revision'], 'reason' => 'Complete branch employee handover', 'records' => $records,
        ]])->assertOk()->assertJsonPath('transferred_counts.leads', 1)
            ->assertJsonPath('transferred_counts.bookings', 1)->assertJsonPath('transferred_counts.tasks', 2);
        foreach (['leads' => 'responsible_agent_id', 'bookings' => 'agent_id', 'crm_tasks' => 'assignee_id'] as $table => $responsible) {
            $this->assertDatabaseHas($table, ['id' => 1, $responsible => $this->first->id, 'branch_group_id' => $this->group->id]);
            $this->assertDatabaseHas($table, ['id' => 2, $responsible => $this->employee->id, 'branch_group_id' => $outside->branch_group_id]);
        }
        $this->assertDatabaseHas('crm_tasks', ['id' => 3, 'assignee_id' => $this->first->id, 'branch_group_id' => $this->group->id]);
        $this->assertDatabaseHas('leads', ['id' => 1, 'branch_id' => $this->group->branch_id]);
        $this->assertSame('inactive', $this->employee->fresh()->status);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    private function crossBranchTables(): void
    {
        Schema::create('property_logs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('property_id'); $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action'); $t->json('changes')->nullable(); $t->text('comment')->nullable(); $t->timestamps();
        });
        Schema::create('crm_deals', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('primary_property_id')->nullable();
            $t->string('control_kind')->nullable(); $t->timestamp('closed_at')->nullable(); $t->softDeletes();
        });
    }

    public function test_admin_transfers_property_to_selected_branch_and_preserves_closed_history(): void
    {
        $this->crossBranchTables();
        $target = $this->crossBranchTarget();
        $plan = $this->plan();
        $plan['transfer_plan']['records'][0]['responsible_user_id'] = $target->id;
        $plan['transfer_plan']['records'][0]['destination_branch_group_id'] = $target->branch_group_id;
        $this->deleteJson('/api/user/'.$this->employee->id, $plan)->assertOk();
        $this->assertDatabaseHas('properties', ['id' => 1, 'agent_id' => $target->id,
            'branch_id' => $target->branch_id, 'branch_group_id' => $target->branch_group_id]);
        $this->assertDatabaseHas('properties', ['id' => 3, 'agent_id' => $this->employee->id,
            'branch_id' => $this->group->branch_id, 'branch_group_id' => $this->group->id]);
        $this->assertSame('inactive', $this->employee->fresh()->status);
    }

    public static function taskTransfers(): array { return [[true], [false]]; }

    #[DataProvider('taskTransfers')]
    public function test_linked_tasks_follow_property_group_or_entire_dismissal_rolls_back(bool $valid): void
    {
        $this->crossBranchTables();
        Schema::create('crm_tasks', function (Blueprint $t) {
            $t->id(); $t->string('title'); $t->unsignedBigInteger('assignee_id'); $t->unsignedBigInteger('branch_group_id');
            $t->string('related_entity_type'); $t->unsignedBigInteger('related_entity_id');
            $t->timestamp('completed_at')->nullable(); $t->string('status')->nullable(); $t->timestamps();
        });
        $target = $this->crossBranchTarget();
        DB::table('crm_tasks')->insert(['id' => 1, 'title' => 'Follow property', 'assignee_id' => $this->employee->id,
            'branch_group_id' => $this->group->id, 'related_entity_type' => 'property', 'related_entity_id' => 1]);
        DB::table('crm_tasks')->insert(['id' => 2, 'title' => 'Closed history', 'assignee_id' => $this->employee->id,
            'branch_group_id' => $this->group->id, 'related_entity_type' => 'property', 'related_entity_id' => 1, 'status' => 'done']);
        $preview = $this->preview();
        $task = collect($preview['records'])->firstWhere('type', 'tasks');
        $this->assertSame(1, $task['follows_property_id']);
        $this->assertContains($target->id, $task['eligible_user_ids']);
        $plan = $this->plan();
        foreach ($plan['transfer_plan']['records'] as &$row) {
            if ($row['type'] === 'properties' && $row['id'] === 1) {
                $row['responsible_user_id'] = $target->id;
                $row['destination_branch_group_id'] = $target->branch_group_id;
            }
            if ($row['type'] === 'tasks') $row['responsible_user_id'] = $valid ? $target->id : $this->first->id;
        }
        unset($row);
        $this->deleteJson('/api/user/'.$this->employee->id, $plan)->assertStatus($valid ? 200 : 422);
        $this->assertDatabaseHas('crm_tasks', ['id' => 1, 'assignee_id' => $valid ? $target->id : $this->employee->id,
            'branch_group_id' => $valid ? $target->branch_group_id : $this->group->id]);
        $this->assertDatabaseHas('crm_tasks', ['id' => 2, 'assignee_id' => $this->employee->id, 'branch_group_id' => $this->group->id]);
        $this->assertDatabaseHas('properties', ['id' => 1, 'agent_id' => $valid ? $target->id : $this->employee->id]);
        $this->assertSame($valid ? 'inactive' : 'active', $this->employee->fresh()->status);
        if (! $valid) $this->assertDatabaseCount('group_access_audit_logs', 0);
    }

    public static function clientTransferRoles(): array
    {
        return [['admin', true], ['branch_director', true], ['rop', true], ['hr', false]];
    }

    #[DataProvider('clientTransferRoles')]
    public function test_clients_can_move_to_another_allowed_group_with_needs(string $role, bool $allowed): void
    {
        $other = BranchGroup::create(['name' => 'Receiving group', 'branch_id' => $this->group->branch_id]);
        $target = $this->person('agent');
        $target->update(['branch_group_id' => $other->id]);
        $actor = $role === 'rop' ? $this->actingAsGroupRop() : $this->person($role);
        if ($role === 'rop') $actor->supervisedGroups()->attach($other->id);
        Sanctum::actingAs($actor);
        $preview = $this->preview();
        $client = collect($preview['records'])->firstWhere('type', 'clients');
        $this->assertSame($allowed, in_array($target->id, $client['eligible_user_ids']));
        $plan = $this->plan();
        foreach ($plan['transfer_plan']['records'] as &$row) {
            if ($row['type'] === 'clients') {
                $row['responsible_user_id'] = $target->id;
                $row['destination_branch_group_id'] = $other->id;
            }
        }
        unset($row);
        $this->deleteJson('/api/user/'.$this->employee->id, $plan)->assertStatus($allowed ? 200 : 422);
        $this->assertDatabaseHas('clients', ['id' => 1,
            'responsible_agent_id' => $allowed ? $target->id : $this->employee->id,
            'branch_group_id' => $allowed ? $other->id : $this->group->id]);
        $this->assertDatabaseHas('client_needs', ['client_id' => 1, 'responsible_agent_id' => $allowed ? $target->id : $this->employee->id]);
    }

    public function test_cross_branch_client_requires_confirmation_and_moves_linked_tasks_atomically(): void
    {
        Schema::create('crm_tasks', function (Blueprint $t) {
            $t->id(); $t->string('title'); $t->unsignedBigInteger('assignee_id'); $t->unsignedBigInteger('branch_group_id');
            $t->string('related_entity_type'); $t->unsignedBigInteger('related_entity_id');
            $t->timestamp('completed_at')->nullable(); $t->string('status')->nullable(); $t->timestamps();
        });
        DB::table('crm_tasks')->insert(['id' => 1, 'title' => 'Follow client', 'assignee_id' => $this->employee->id,
            'branch_group_id' => $this->group->id, 'related_entity_type' => 'client', 'related_entity_id' => 1]);
        $target = $this->crossBranchTarget();
        $preview = $this->preview();
        $task = collect($preview['records'])->firstWhere('type', 'tasks');
        $this->assertSame(1, $task['follows_client_id']);
        $this->assertNull($task['follows_property_id']);
        $this->assertContains($target->id, $task['eligible_user_ids']);
        $plan = $this->plan();
        foreach ($plan['transfer_plan']['records'] as &$row) {
            if (in_array($row['type'], ['clients', 'tasks'])) $row['responsible_user_id'] = $target->id;
        }
        unset($row);
        $this->deleteJson('/api/user/'.$this->employee->id, $plan)->assertUnprocessable();
        $this->assertDatabaseHas('clients', ['id' => 1, 'responsible_agent_id' => $this->employee->id]);
        $this->assertDatabaseCount('group_access_audit_logs', 0);
        foreach ($plan['transfer_plan']['records'] as &$row) {
            if ($row['type'] === 'clients') $row['destination_branch_group_id'] = $target->branch_group_id;
        }
        unset($row);
        $this->deleteJson('/api/user/'.$this->employee->id, $plan)->assertOk();
        $this->assertDatabaseHas('clients', ['id' => 1, 'responsible_agent_id' => $target->id,
            'branch_group_id' => $target->branch_group_id, 'branch_id' => $target->branch_id]);
        $this->assertDatabaseHas('crm_tasks', ['id' => 1, 'assignee_id' => $target->id, 'branch_group_id' => $target->branch_group_id]);
    }

    public static function operationalRoles(): array { return [['admin'], ['branch_director'], ['rop']]; }

    #[DataProvider('operationalRoles')]
    public function test_all_operational_records_can_leave_an_empty_group_atomically(string $role): void
    {
        foreach (['leads', 'crm_deals'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id(); $t->string('title')->nullable(); $t->unsignedBigInteger('responsible_agent_id');
                $t->unsignedBigInteger('branch_group_id'); $t->unsignedBigInteger('branch_id');
                $t->timestamp('closed_at')->nullable(); $t->timestamps(); $t->softDeletes();
            });
            foreach ([1, 2, 3] as $id) DB::table($table)->insert(['id' => $id, 'responsible_agent_id' => $this->employee->id,
                'branch_group_id' => $this->group->id, 'branch_id' => $this->group->branch_id]);
        }
        Schema::create('bookings', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('agent_id'); $t->unsignedBigInteger('branch_group_id');
            $t->timestamp('end_time'); $t->timestamps();
        });
        DB::table('bookings')->insert(['agent_id' => $this->employee->id, 'branch_group_id' => $this->group->id, 'end_time' => now()->addDay()]);
        Schema::create('crm_tasks', function (Blueprint $t) {
            $t->id(); $t->string('title'); $t->unsignedBigInteger('assignee_id'); $t->unsignedBigInteger('branch_group_id');
            $t->string('related_entity_type')->nullable(); $t->unsignedBigInteger('related_entity_id')->nullable();
            $t->string('status')->default('pending'); $t->timestamp('completed_at')->nullable(); $t->timestamps();
        });
        DB::table('crm_tasks')->insert(['title' => 'Deal follow-up', 'assignee_id' => $this->employee->id,
            'branch_group_id' => $this->group->id, 'related_entity_type' => 'deal', 'related_entity_id' => 1]);
        DB::table('crm_tasks')->insert(['title' => 'Standalone', 'assignee_id' => $this->employee->id, 'branch_group_id' => $this->group->id]);
        $other = BranchGroup::create(['name' => 'Receiving group', 'branch_id' => $this->group->branch_id]);
        $target = $this->person('agent'); $target->update(['branch_group_id' => $other->id]);
        if ($role === 'rop') {
            $actor = $this->actingAsGroupRop(); $actor->supervisedGroups()->attach($other->id);
        } else { $actor = $this->person($role); }
        Sanctum::actingAs($actor);
        $preview = $this->preview();
        foreach (collect($preview['records'])->whereIn('type', ['deals', 'leads', 'bookings', 'tasks']) as $row) {
            $this->assertContains($target->id, $row['eligible_user_ids']);
        }
        $task = collect($preview['records'])->firstWhere('type', 'tasks');
        $this->assertSame(['type' => 'deals', 'id' => 1], $task['follows_record']);
        $plan = $this->plan();
        foreach ($plan['transfer_plan']['records'] as &$row) {
            if (in_array($row['type'], ['deals', 'leads', 'bookings', 'tasks'])) {
                $row['responsible_user_id'] = $target->id;
                $row['destination_branch_group_id'] = $other->id;
            }
        }
        unset($row);
        $this->deleteJson('/api/user/'.$this->employee->id, $plan)->assertOk()->assertJsonPath('transferred_counts.deals', 3);
        foreach (['leads', 'crm_deals'] as $table) {
            $this->assertSame(3, DB::table($table)->where('responsible_agent_id', $target->id)->where('branch_group_id', $other->id)->count());
        }
        $this->assertDatabaseHas('bookings', ['agent_id' => $target->id, 'branch_group_id' => $other->id]);
        $this->assertSame(2, DB::table('crm_tasks')->where('assignee_id', $target->id)->where('branch_group_id', $other->id)->count());
        $this->assertDatabaseHas('users', ['id' => $this->employee->id, 'status' => 'inactive']);
    }

    public static function crossBranchDealManagers(): array
    {
        return [['admin'], ['branch_director']];
    }

    #[DataProvider('crossBranchDealManagers')]
    public function test_cross_branch_deal_preserves_matching_stage_and_rejects_missing_match(string $role): void
    {
        Schema::create('crm_deal_pipelines', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id'); $t->string('slug'); $t->string('type'); $t->boolean('is_active')->default(true);
        });
        Schema::create('crm_deal_stages', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('pipeline_id'); $t->string('slug'); $t->boolean('is_active')->default(true);
            $t->boolean('is_closed')->default(false); $t->boolean('is_lost')->default(false);
        });
        Schema::create('crm_deals', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('responsible_agent_id'); $t->unsignedBigInteger('branch_group_id');
            $t->unsignedBigInteger('branch_id'); $t->unsignedBigInteger('pipeline_id'); $t->unsignedBigInteger('stage_id');
            $t->timestamp('closed_at')->nullable(); $t->softDeletes(); $t->timestamps();
        });
        $outside = $this->crossBranchTarget();
        $sourceGroup = $role === 'branch_director' ? $outside->branchGroup : $this->group;
        $target = $role === 'branch_director' ? $this->first : $outside;
        Sanctum::actingAs($this->person($role));
        DB::table('crm_deal_pipelines')->insert([
            ['id'=>1, 'branch_id'=>$sourceGroup->branch_id, 'slug'=>'sales', 'type'=>'sales'],
            ['id'=>2, 'branch_id'=>$target->branch_id, 'slug'=>'sales', 'type'=>'sales'],
        ]);
        DB::table('crm_deal_stages')->insert(['id'=>1, 'pipeline_id'=>1, 'slug'=>'negotiation']);
        DB::table('crm_deals')->insert(['id'=>1, 'responsible_agent_id'=>$this->employee->id,
            'branch_group_id'=>$sourceGroup->id, 'branch_id'=>$sourceGroup->branch_id, 'pipeline_id'=>1, 'stage_id'=>1]);
        $deal = collect($this->preview()['records'])->firstWhere('type','deals');
        $this->assertNotContains($target->id, $deal['eligible_user_ids']);
        DB::table('crm_deal_stages')->insert(['id'=>2, 'pipeline_id'=>2, 'slug'=>'negotiation']);
        $deal = collect($this->preview()['records'])->firstWhere('type','deals');
        $this->assertContains($target->id, $deal['eligible_user_ids']);
        $plan = $this->plan();
        foreach ($plan['transfer_plan']['records'] as &$row) {
            if ($row['type'] === 'deals') $row['responsible_user_id'] = $target->id;
        }
        unset($row);
        $this->deleteJson('/api/user/'.$this->employee->id, $plan)->assertOk();
        $this->assertDatabaseHas('crm_deals', ['id'=>1, 'responsible_agent_id'=>$target->id,
            'branch_group_id'=>$target->branch_group_id, 'branch_id'=>$target->branch_id, 'pipeline_id'=>2, 'stage_id'=>2, 'closed_at'=>null]);
    }

}

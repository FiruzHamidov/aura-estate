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
        foreach (['admin', 'agent', 'mop', 'hr', 'branch_director', 'rop'] as $role) {
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

    public function test_director_cannot_transfer_a_record_outside_their_branch(): void
    {
        Sanctum::actingAs($this->person('branch_director'));
        $plan = $this->plan();
        $otherBranch = Branch::create(['name' => 'Other']);
        DB::table('clients')->where('id', 1)->update(['branch_id' => $otherBranch->id]);
        $this->getJson('/api/user/'.$this->employee->id.'/dismissal-preview')->assertForbidden();
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
}

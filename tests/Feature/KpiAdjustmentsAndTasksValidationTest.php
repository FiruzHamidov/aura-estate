<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDailyReportSubmitted;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\CrmTaskType;
use App\Models\DailyReport;
use App\Models\KpiPeriodLock;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KpiAdjustmentsAndTasksValidationTest extends TestCase
{
    private int $phoneCounter = 970000000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(EnsureDailyReportSubmitted::class);
        Schema::dropAllTables();

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('branch_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('name');
            $table->string('contact_visibility_mode')->default(BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->unique();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->string('status')->default('active');
            $table->string('auth_method')->default('password');
            $table->rememberToken()->nullable();
            $table->timestamps();
        });

        Schema::create('crm_task_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 128);
            $table->string('group', 64)->default('kpi');
            $table->boolean('is_kpi')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('crm_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_type_id');
            $table->unsignedBigInteger('assignee_id');
            $table->unsignedBigInteger('creator_id')->nullable();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('new');
            $table->string('result_code', 64)->nullable();
            $table->string('related_entity_type', 32)->nullable();
            $table->unsignedBigInteger('related_entity_id')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('source', 32)->default('manual');
            $table->timestamps();
        });

        Schema::create('daily_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('role_slug')->nullable();
            $table->date('report_date');
            $table->unsignedInteger('calls_count')->default(0);
            $table->unsignedInteger('ad_count')->default(0);
            $table->unsignedInteger('meetings_count')->default(0);
            $table->unsignedInteger('shows_count')->default(0);
            $table->unsignedInteger('new_clients_count')->default(0);
            $table->unsignedInteger('new_properties_count')->default(0);
            $table->unsignedInteger('deposits_count')->default(0);
            $table->unsignedInteger('deals_count')->default(0);
            $table->text('comment')->nullable();
            $table->text('plans_for_tomorrow')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('kpi_period_locks', function (Blueprint $table) {
            $table->id();
            $table->string('period_type', 16);
            $table->string('period_key', 32);
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->unsignedBigInteger('locked_by');
            $table->timestamp('locked_at');
            $table->timestamps();
        });

        Schema::create('kpi_adjustment_logs', function (Blueprint $table) {
            $table->id();
            $table->string('period_type', 16);
            $table->string('period_key', 32);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('field_name', 64);
            $table->decimal('old_value', 14, 4)->nullable();
            $table->decimal('new_value', 14, 4)->nullable();
            $table->text('reason');
            $table->unsignedBigInteger('changed_by');
            $table->timestamp('changed_at');
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_call_task_done_requires_result_and_completed_at(): void
    {
        [$admin, $agent, $callType] = $this->seedUsersAndTypes();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/crm/tasks', [
            'task_type_id' => $callType->id,
            'assignee_id' => $agent->id,
            'title' => 'Call back client',
            'status' => 'done',
            'related_entity_type' => 'lead',
            'related_entity_id' => 10,
        ]);

        $response->assertStatus(422);
    }

    public function test_ad_task_requires_ad_entity_binding(): void
    {
        [$admin, $agent] = $this->seedUsersAndTypes();
        $adType = CrmTaskType::query()->create([
            'code' => 'AD_CREATE',
            'name' => 'Создание объявления',
            'group' => 'ads',
            'is_kpi' => false,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/crm/tasks', [
            'task_type_id' => $adType->id,
            'assignee_id' => $agent->id,
            'title' => 'Publish ad',
            'related_entity_type' => 'lead',
            'related_entity_id' => 20,
        ]);

        $response->assertStatus(422);
    }

    public function test_week_adjustment_distribute_evenly_updates_all_days_and_logs_total(): void
    {
        [$admin, $agent] = $this->seedUsersAndTypes();

        // 3 daily rows in same week
        DailyReport::create([
            'user_id' => $agent->id,
            'role_slug' => 'agent',
            'report_date' => '2026-05-04',
            'calls_count' => 0,
        ]);
        DailyReport::create([
            'user_id' => $agent->id,
            'role_slug' => 'agent',
            'report_date' => '2026-05-05',
            'calls_count' => 0,
        ]);
        DailyReport::create([
            'user_id' => $agent->id,
            'role_slug' => 'agent',
            'report_date' => '2026-05-06',
            'calls_count' => 0,
        ]);

        KpiPeriodLock::create([
            'period_type' => 'week',
            'period_key' => '2026-05-04',
            'branch_id' => $admin->branch_id,
            'branch_group_id' => null,
            'locked_by' => $admin->id,
            'locked_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/kpi-adjustments', [
            'period_type' => 'week',
            'period_key' => '2026-05-04',
            'entity_id' => $agent->id,
            'field_name' => 'calls_count',
            'new_value' => 9,
            'distribution_mode' => 'distribute_evenly',
            'reason' => 'manual correction',
        ]);

        $response->assertCreated();

        $total = DailyReport::query()
            ->where('user_id', $agent->id)
            ->whereBetween('report_date', ['2026-05-04', '2026-05-10'])
            ->sum('calls_count');

        $this->assertEquals(9.0, (float) $total);

        $this->assertDatabaseHas('kpi_adjustment_logs', [
            'period_type' => 'week',
            'period_key' => '2026-05-04',
            'entity_id' => $agent->id,
            'field_name' => 'calls_count',
        ]);
    }

    private function seedUsersAndTypes(): array
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);

        $admin = User::create([
            'name' => 'Admin',
            'phone' => (string) ++$this->phoneCounter,
            'role_id' => $adminRole->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'status' => 'active',
            'auth_method' => 'password',
        ]);

        $agent = User::create([
            'name' => 'Agent',
            'phone' => (string) ++$this->phoneCounter,
            'role_id' => $agentRole->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'status' => 'active',
            'auth_method' => 'password',
        ]);

        $callType = CrmTaskType::create([
            'code' => 'CALL',
            'name' => 'Звонок',
            'group' => 'kpi',
            'is_kpi' => true,
            'is_active' => true,
        ]);

        return [$admin, $agent, $callType];
    }
}

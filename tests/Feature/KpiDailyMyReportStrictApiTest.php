<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDailyReportSubmitted;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\DailyReport;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KpiDailyMyReportStrictApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(EnsureDailyReportSubmitted::class);
        Schema::dropAllTables();

        Schema::create('roles', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->string('slug')->unique(), $t->timestamps()]);
        Schema::create('branches', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->timestamps()]);
        Schema::create('branch_groups', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('branch_id'), $t->string('name'), $t->string('contact_visibility_mode')->default('group_only'), $t->timestamps()]);
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('phone')->unique();
            $t->unsignedBigInteger('role_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('branch_group_id')->nullable();
            $t->string('status')->default('active');
            $t->string('auth_method')->default('password');
            $t->timestamps();
        });

        Schema::create('daily_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('role_slug')->nullable();
            $t->date('report_date');
            $t->unsignedInteger('calls_count')->default(0);
            $t->unsignedInteger('ad_count')->default(0);
            $t->unsignedInteger('meetings_count')->default(0);
            $t->unsignedInteger('shows_count')->default(0);
            $t->unsignedInteger('new_clients_count')->default(0);
            $t->unsignedInteger('new_properties_count')->default(0);
            $t->unsignedInteger('deposits_count')->default(0);
            $t->unsignedInteger('deals_count')->default(0);
            $t->decimal('sales_count', 8, 4)->default(0);
            $t->text('comment')->nullable();
            $t->text('plans_for_tomorrow')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'report_date']);
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
    }

    public function test_read_and_submit_strict_daily_my_report_contract(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-05 12:00:00', 'Asia/Dushanbe'));

        $rop = $this->makeUser('rop');
        Sanctum::actingAs($rop);

        $this->postJson('/api/kpi/daily/my-report', [
            'report_date' => '2026-05-05',
            'ads' => 7,
            'calls' => 13,
            'comment' => 'done',
            'plans_for_tomorrow' => 'more calls',
        ])->assertOk()
            ->assertJsonPath('report_date', '2026-05-05')
            ->assertJsonPath('metrics.objects.source', 'system')
            ->assertJsonPath('metrics.shows.source', 'system')
            ->assertJsonPath('metrics.ads.source', 'manual')
            ->assertJsonPath('metrics.calls.source', 'manual')
            ->assertJsonPath('metrics.sales.source', 'system')
            ->assertJsonPath('manual.ads', 7)
            ->assertJsonPath('manual.calls', 13)
            ->assertJsonPath('manual.comment', 'done')
            ->assertJsonPath('manual.plans_for_tomorrow', 'more calls');

        $this->getJson('/api/kpi/daily/my-report?date=2026-05-05')
            ->assertOk()
            ->assertJsonStructure([
                'report_date',
                'metrics' => ['objects', 'shows', 'ads', 'calls', 'sales'],
                'manual' => ['ads', 'calls', 'comment', 'plans_for_tomorrow'],
                'submitted',
                'submitted_at',
                'meta' => ['locked'],
            ]);

        $this->postJson('/api/kpi/daily/my-report', [
            'report_date' => '2026-05-05',
            'ads' => 9,
            'calls' => 14,
            'comment' => 'updated',
            'plans_for_tomorrow' => 'updated plan',
        ])->assertStatus(403)
            ->assertJsonPath('code', 'KPI_SUBMITTED_EDIT_FORBIDDEN');

        $this->assertTrue(
            DailyReport::query()
                ->where('user_id', $rop->id)
                ->whereDate('report_date', '2026-05-05')
                ->where('ad_count', 7)
                ->where('calls_count', 13)
                ->where('comment', 'done')
                ->exists()
        );
    }

    public function test_submit_with_unknown_kpi_key_returns_kpi_validation_error(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-05 12:00:00', 'Asia/Dushanbe'));

        Sanctum::actingAs($this->makeUser('rop'));

        $this->postJson('/api/kpi/daily/my-report', [
            'report_date' => '2026-05-05',
            'ads' => 1,
            'calls' => 2,
            'deals' => 3,
        ])->assertStatus(422)
            ->assertJsonPath('code', 'KPI_VALIDATION_FAILED')
            ->assertJsonPath('message', 'Validation failed.')
            ->assertJsonPath('details.errors.deals.0', 'This metric is not writable for this endpoint.')
            ->assertJsonStructure(['trace_id']);
    }

    public function test_submit_historical_date_and_role_restrictions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-06 00:01:00', 'Asia/Dushanbe'));

        Sanctum::actingAs($this->makeUser('agent'));
        $this->postJson('/api/kpi/daily/my-report', [
            'report_date' => '2026-05-05',
            'ads' => 1,
            'calls' => 2,
        ])->assertOk()
            ->assertJsonPath('report_date', '2026-05-05')
            ->assertJsonPath('manual.ads', 1)
            ->assertJsonPath('manual.calls', 2);

        $this->postJson('/api/kpi/daily/my-report', [
            'report_date' => '2026-05-05',
            'ads' => 2,
            'calls' => 3,
        ])->assertStatus(403)
            ->assertJsonPath('code', 'KPI_SUBMITTED_EDIT_FORBIDDEN');

        Sanctum::actingAs($this->makeUser('admin'));
        $this->getJson('/api/kpi/daily/my-report?date=2026-05-06')
            ->assertOk();
    }

    private function makeUser(string $roleSlug): User
    {
        $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => ucfirst($roleSlug)]);
        $branch = Branch::firstOrCreate(['name' => 'A']);
        $group = BranchGroup::firstOrCreate(['branch_id' => $branch->id, 'name' => 'A1'], ['contact_visibility_mode' => 'group_only']);

        return User::create([
            'name' => $roleSlug.' user',
            'phone' => '+992'.random_int(100000000, 999999999),
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'status' => 'active',
            'auth_method' => 'password',
        ]);
    }
}

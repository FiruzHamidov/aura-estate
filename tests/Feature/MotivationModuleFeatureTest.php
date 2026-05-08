<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDailyReportSubmitted;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\MotivationRulesSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MotivationModuleFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(EnsureDailyReportSubmitted::class);
        Schema::dropAllTables();

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->string('phone')->unique();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->enum('auth_method', ['password', 'sms'])->default('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->string('moderation_status', 64)->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->timestamps();
        });

        Schema::create('property_agent_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('agent_id');
            $table->enum('role', ['main', 'assistant', 'partner'])->default('assistant');
            $table->timestamps();
            $table->unique(['property_id', 'agent_id']);
        });

        Schema::create('motivation_rules', function (Blueprint $table) {
            $table->id();
            $table->enum('scope', ['agent', 'company']);
            $table->string('metric_key', 64)->default('sales_count');
            $table->decimal('threshold_value', 8, 4);
            $table->string('reward_type', 64);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->enum('period_type', ['week', 'month', 'year']);
            $table->date('date_from');
            $table->date('date_to');
            $table->json('ui_meta')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['scope', 'metric_key', 'threshold_value', 'period_type', 'date_from', 'date_to', 'reward_type'], 'motivation_rules_unique_period_scope_reward');
        });

        Schema::create('motivation_achievements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rule_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->boolean('company_scope')->nullable();
            $table->dateTime('won_at');
            $table->enum('period_type', ['week', 'month', 'year']);
            $table->date('date_from');
            $table->date('date_to');
            $table->decimal('snapshot_value', 12, 4);
            $table->enum('status', ['new', 'approved', 'issued', 'cancelled'])->default('new');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->dateTime('issued_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['rule_id', 'user_id', 'period_type', 'date_from', 'date_to'], 'motivation_achievements_agent_unique');
            $table->unique(['rule_id', 'company_scope', 'period_type', 'date_from', 'date_to'], 'motivation_achievements_company_unique');
        });

        Schema::create('motivation_reward_issues', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('achievement_id');
            $table->unsignedBigInteger('assignee_id')->nullable();
            $table->enum('status', ['new', 'in_progress', 'issued', 'rejected'])->default('new');
            $table->dateTime('issued_at')->nullable();
            $table->text('comment')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['achievement_id']);
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

    public function test_motivation_rules_seeder_is_idempotent(): void
    {
        $this->seed(MotivationRulesSeeder::class);
        $this->seed(MotivationRulesSeeder::class);

        $this->assertDatabaseCount('motivation_rules', 3);
        $this->assertDatabaseHas('motivation_rules', [
            'scope' => 'company',
            'threshold_value' => 100,
            'reward_type' => 'company_party',
        ]);
    }

    public function test_only_rop_plus_can_manage_rules(): void
    {
        $agent = $this->makeUser('agent', '900100001');
        Sanctum::actingAs($agent);

        $this->postJson('/api/motivation/rules', [
            'scope' => 'agent',
            'metric_key' => 'sales_count',
            'threshold_value' => 5,
            'reward_type' => 'trip_tashkent',
            'name' => '5',
            'period_type' => 'month',
            'date_from' => '2026-05-01',
            'date_to' => '2026-05-31',
        ])->assertStatus(403)
            ->assertJsonPath('code', 'KPI_FORBIDDEN_ROLE_ACTION');
    }

    public function test_recalculate_creates_agent_and_company_achievements(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-20 12:00:00', 'Asia/Dushanbe'));

        $this->seed(MotivationRulesSeeder::class);

        $admin = $this->makeUser('admin', '900100002');
        $agentA = $this->makeUser('agent', '900100003');
        $agentB = $this->makeUser('agent', '900100004');

        // 5 full sales for agentA via fallback agent_id
        foreach (range(1, 5) as $idx) {
            \DB::table('properties')->insert([
                'agent_id' => $agentA->id,
                'moderation_status' => 'sold',
                'sold_at' => Carbon::parse('2026-05-'.$idx.' 10:00:00', 'Asia/Dushanbe')->setTimezone('UTC')->toDateTimeString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 95 more company sales for agentB
        foreach (range(6, 100) as $idx) {
            \DB::table('properties')->insert([
                'agent_id' => $agentB->id,
                'moderation_status' => 'sold',
                'sold_at' => Carbon::parse('2026-05-'.min($idx, 28).' 11:00:00', 'Asia/Dushanbe')->setTimezone('UTC')->toDateTimeString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Sanctum::actingAs($admin);
        $this->postJson('/api/motivation/recalculate', ['reason' => 'test'])
            ->assertOk()
            ->assertJsonPath('data.rules_processed', 3);

        $this->assertDatabaseHas('motivation_achievements', [
            'user_id' => $agentA->id,
            'company_scope' => null,
            'status' => 'new',
        ]);

        $this->assertDatabaseHas('motivation_achievements', [
            'company_scope' => true,
            'status' => 'new',
        ]);
    }

    public function test_rules_endpoint_returns_custom_ui_meta_for_new_reward_type(): void
    {
        $mop = $this->makeUser('mop', '900100011');
        Sanctum::actingAs($mop);

        \DB::table('motivation_rules')->insert([
            'scope' => 'agent',
            'metric_key' => 'sales_count',
            'threshold_value' => 12,
            'reward_type' => 'gift_card',
            'name' => '12 продаж — Подарочная карта',
            'period_type' => 'month',
            'date_from' => '2026-05-01',
            'date_to' => '2026-05-31',
            'ui_meta' => json_encode([
                'title' => 'Подарочная карта',
                'short_label' => 'Карта',
                'messages' => [
                    'remaining_1' => 'Еще 1 сделка до подарочной карты!',
                ],
            ], JSON_UNESCAPED_UNICODE),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/motivation/rules?scope=agent&period_type=month&is_active=1')
            ->assertOk()
            ->assertJsonFragment([
                'reward_type' => 'gift_card',
                'title' => 'Подарочная карта',
                'short_label' => 'Карта',
            ]);
    }

    public function test_rules_endpoint_applies_messages_fallback_when_ui_meta_is_partial(): void
    {
        $mop = $this->makeUser('mop', '900100012');
        Sanctum::actingAs($mop);

        \DB::table('motivation_rules')->insert([
            'scope' => 'agent',
            'metric_key' => 'sales_count',
            'threshold_value' => 7,
            'reward_type' => 'trip_baku',
            'name' => '7 продаж — Баку',
            'period_type' => 'month',
            'date_from' => '2026-05-01',
            'date_to' => '2026-05-31',
            'ui_meta' => json_encode([
                'short_label' => 'Баку',
            ], JSON_UNESCAPED_UNICODE),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/motivation/rules?scope=agent&period_type=month&is_active=1')
            ->assertOk();

        $payload = collect($response->json('data'))->firstWhere('reward_type', 'trip_baku');
        $this->assertNotNull($payload);
        $this->assertSame('Баку', $payload['ui_meta']['short_label']);
        $this->assertSame('До Баку осталось {remaining} {unit}.', $payload['ui_meta']['messages']['remaining_default']);
    }

    public function test_my_overview_returns_calculated_cards_next_reward_and_company_goal(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-20 12:00:00', 'Asia/Dushanbe'));
        $this->seed(MotivationRulesSeeder::class);

        $agent = $this->makeUser('agent', '900100013');
        $agentB = $this->makeUser('agent', '900100015');
        Sanctum::actingAs($agent);

        foreach (range(1, 4) as $idx) {
            \DB::table('properties')->insert([
                'agent_id' => $agent->id,
                'moderation_status' => 'sold',
                'sold_at' => Carbon::parse('2026-05-'.$idx.' 10:00:00', 'Asia/Dushanbe')->setTimezone('UTC')->toDateTimeString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (range(1, 91) as $idx) {
            \DB::table('properties')->insert([
                'agent_id' => $agentB->id,
                'moderation_status' => 'sold',
                'sold_at' => Carbon::parse('2026-05-'.min($idx, 28).' 11:00:00', 'Asia/Dushanbe')->setTimezone('UTC')->toDateTimeString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $response = $this->getJson('/api/motivation/my-overview?period_type=month&date_from=2026-05-01&date_to=2026-05-31')
            ->assertOk();

        $response->assertJsonPath('period.period_type', 'month');
        $response->assertJsonPath('next_reward.rule_id', 1);
        $response->assertJsonPath('company_goal.rule_id', 3);
        $response->assertJsonPath('company_goal.status', 'in_progress');
    }

    public function test_my_overview_forbidden_for_non_agent_and_non_mop(): void
    {
        $admin = $this->makeUser('admin', '900100014');
        Sanctum::actingAs($admin);

        $this->getJson('/api/motivation/my-overview?period_type=month&date_from=2026-05-01&date_to=2026-05-31')
            ->assertStatus(403)
            ->assertJsonPath('code', 'KPI_FORBIDDEN_ROLE_ACTION');
    }

    private function makeUser(string $roleSlug, string $phone): User
    {
        $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => ucfirst($roleSlug)]);

        return User::create([
            'name' => $roleSlug.' user',
            'phone' => $phone,
            'role_id' => $role->id,
            'status' => 'active',
            'auth_method' => 'password',
        ]);
    }
}

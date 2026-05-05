<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\UserDailyReportReminderSetting;
use App\Services\NotificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KpiDailyReminderAndProgressTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->unique();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->foreignId('role_id')->constrained('roles');
            $table->string('status')->default('active');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('daily_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role_slug', 64);
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
            $table->unique(['user_id', 'report_date']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('type', 100)->nullable();
            $table->string('category', 32)->nullable();
            $table->string('status', 20)->default('unread');
            $table->unsignedTinyInteger('priority')->default(2);
            $table->json('channels')->nullable();
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->string('action_url')->nullable();
            $table->string('action_type', 50)->nullable();
            $table->string('dedupe_key')->nullable();
            $table->unsignedInteger('occurrences_count')->default(1);
            $table->timestamp('last_occurred_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });

        Schema::create('user_daily_report_reminder_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->unique();
            $table->boolean('enabled')->default(false);
            $table->string('remind_time', 5)->default('18:30');
            $table->string('timezone', 64)->default('Asia/Dushanbe');
            $table->json('channels')->nullable();
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

    public function test_daily_reminder_settings_and_my_progress_endpoints(): void
    {
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = User::create([
            'name' => 'Agent',
            'phone' => '+992900000111',
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);

        DailyReportServiceTestHelper::seedSubmittedYesterday($agent);

        Sanctum::actingAs($agent);

        $this->getJson('/api/me/reminders/daily-report')
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('remind_time', '18:30')
            ->assertJsonPath('timezone', 'Asia/Dushanbe')
            ->assertJsonPath('channels.0', 'in_app');

        $this->putJson('/api/me/reminders/daily-report', [
            'enabled' => true,
            'remind_time' => '18:45',
            'timezone' => 'Asia/Dushanbe',
            'channels' => ['in_app', 'telegram'],
        ])->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('channels.1', 'telegram');

        DB::table('daily_reports')->updateOrInsert(
            ['user_id' => $agent->id, 'report_date' => '2026-05-05'],
            [
                'role_slug' => 'agent',
                'calls_count' => 29,
                'shows_count' => 2,
                'deals_count' => 0,
                'ad_count' => 4,
                'submitted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->getJson('/api/kpi/daily/my-progress?date=2026-05-05')
            ->assertOk()
            ->assertJsonPath('date', '2026-05-05')
            ->assertJsonPath('submitted_daily_report', false)
            ->assertJsonPath('metrics.call.fact', 29)
            ->assertJsonPath('metrics.show.fact', 2)
            ->assertJsonPath('metrics.deal.fact', 0)
            ->assertJsonPath('metrics.advertisement.fact', 4);
    }

    public function test_dispatch_daily_report_reminder_is_idempotent_for_same_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-05 18:32:00', 'Asia/Dushanbe'));

        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = User::create([
            'name' => 'Agent 2',
            'phone' => '+992900000222',
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);

        UserDailyReportReminderSetting::create([
            'user_id' => $agent->id,
            'enabled' => true,
            'remind_time' => '18:30',
            'timezone' => 'Asia/Dushanbe',
            'channels' => ['in_app'],
        ]);

        $service = app(NotificationService::class);
        $method = new \ReflectionMethod($service, 'dispatchDailyReportReminders');
        $method->setAccessible(true);

        $first = $method->invoke($service);
        $second = $method->invoke($service);

        $this->assertSame(1, $first);
        $this->assertSame(1, $second);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $agent->id,
            'action_url' => '/daily-report',
            'dedupe_key' => 'daily-report:reminder:'.$agent->id.':2026-05-05',
        ]);
    }
}

final class DailyReportServiceTestHelper
{
    public static function seedSubmittedYesterday(User $user): void
    {
        DB::table('daily_reports')->insert([
            'user_id' => $user->id,
            'role_slug' => 'agent',
            'report_date' => now()->subDay()->toDateString(),
            'calls_count' => 0,
            'ad_count' => 0,
            'meetings_count' => 0,
            'shows_count' => 0,
            'new_clients_count' => 0,
            'new_properties_count' => 0,
            'deposits_count' => 0,
            'deals_count' => 0,
            'submitted_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

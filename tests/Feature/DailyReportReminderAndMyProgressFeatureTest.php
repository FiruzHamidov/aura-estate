<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Notifications\NotificationType;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DailyReportReminderAndMyProgressFeatureTest extends TestCase
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
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->string('status')->default('active');
            $table->string('auth_method')->default('password');
            $table->string('telegram_chat_id')->nullable();
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

        Schema::create('user_daily_report_reminder_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->boolean('enabled')->default(false);
            $table->string('remind_time', 5)->default('18:30');
            $table->string('timezone', 64)->default('Asia/Dushanbe');
            $table->json('channels')->nullable();
            $table->timestamps();
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

    public function test_reminder_settings_endpoints_and_my_progress_work_for_agent_with_missing_daily_report(): void
    {
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = User::create([
            'name' => 'Agent A',
            'phone' => '992970000001',
            'role_id' => $agentRole->id,
            'status' => 'active',
            'auth_method' => 'password',
        ]);

        DailyReport::query()->create([
            'user_id' => $agent->id,
            'role_slug' => 'agent',
            'report_date' => '2026-05-05',
            'calls_count' => 29,
            'shows_count' => 2,
            'deals_count' => 0,
            'ad_count' => 4,
            'submitted_at' => now(),
        ]);

        Carbon::setTestNow(Carbon::create(2026, 5, 6, 12, 0, 0, 'Asia/Dushanbe'));
        Sanctum::actingAs($agent);

        $this->getJson('/api/me/reminders/daily-report')
            ->assertOk()
            ->assertJson([
                'enabled' => false,
                'remind_time' => '18:30',
                'timezone' => 'Asia/Dushanbe',
                'channels' => ['in_app'],
            ]);

        $this->putJson('/api/me/reminders/daily-report', [
            'enabled' => true,
            'remind_time' => '18:30',
            'timezone' => 'Asia/Dushanbe',
            'channels' => ['in_app', 'telegram', 'push'],
        ])->assertOk()->assertJson([
            'enabled' => true,
            'remind_time' => '18:30',
            'timezone' => 'Asia/Dushanbe',
            'channels' => ['in_app', 'telegram', 'push'],
        ]);

        $this->getJson('/api/kpi/daily/my-progress?date=2026-05-05')
            ->assertOk()
            ->assertJsonPath('date', '2026-05-05')
            ->assertJsonPath('submitted_daily_report', true)
            ->assertJsonPath('metrics.call.fact', 29)
            ->assertJsonPath('metrics.show.fact', 2)
            ->assertJsonPath('metrics.deal.fact', 0)
            ->assertJsonPath('metrics.advertisement.fact', 4);

        Carbon::setTestNow();
    }

    public function test_daily_report_reminder_dispatch_is_idempotent_per_user_per_day(): void
    {
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = User::create([
            'name' => 'Agent B',
            'phone' => '992970000002',
            'role_id' => $agentRole->id,
            'status' => 'active',
            'auth_method' => 'password',
        ]);

        DB::table('user_daily_report_reminder_settings')->insert([
            'user_id' => $agent->id,
            'enabled' => true,
            'remind_time' => '18:30',
            'timezone' => 'Asia/Dushanbe',
            'channels' => json_encode(['in_app']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Carbon::setTestNow(Carbon::create(2026, 5, 6, 13, 31, 0, 'UTC'));

        $service = app(NotificationService::class);
        $method = new \ReflectionMethod($service, 'dispatchDailyReportReminders');
        $first = $method->invoke($service);
        $second = $method->invoke($service);

        $this->assertSame(1, $first);
        $this->assertSame(1, $second);

        $notifications = DB::table('notifications')
            ->where('user_id', $agent->id)
            ->where('type', NotificationType::DAILY_REPORT_REMINDER)
            ->get();

        $this->assertCount(1, $notifications);
        $this->assertSame('/daily-report', $notifications->first()->action_url);
        $this->assertSame('daily-report:reminder:'.$agent->id.':2026-05-06', $notifications->first()->dedupe_key);

        Carbon::setTestNow();
    }
}

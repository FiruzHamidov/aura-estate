<?php

namespace Tests\Feature;

use App\Events\UserLocationUpdated;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCurrentLocation;
use App\Models\UserLocationPoint;
use App\Services\LocationTracking\LocationAccessService;
use Database\Seeders\DushanbeUserLocationSeeder;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LocationTrackingFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        $this->createBaseSchema();
        $migration = require database_path('migrations/2026_07_29_000001_create_user_location_tracking_tables.php');
        $migration->up();
        $metaMigration = require database_path('migrations/2026_07_29_000002_add_meta_to_user_location_points.php');
        $metaMigration->up();

        config([
            'location_tracking.default_enabled' => true,
            'location_tracking.default_mode' => 'always',
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.options.host' => 'localhost',
            'broadcasting.connections.reverb.options.port' => 8080,
            'broadcasting.connections.reverb.options.scheme' => 'http',
        ]);
        app(BroadcastManager::class)->setDefaultDriver('reverb');
        require base_path('routes/channels.php');
        Event::fake([UserLocationUpdated::class]);
    }

    private function createBaseSchema(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('branch_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id');
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('contact_visibility_mode')->default('group_only');
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->unique();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->foreignId('role_id');
            $table->foreignId('branch_id')->nullable();
            $table->foreignId('branch_group_id')->nullable();
            $table->string('photo')->nullable();
            $table->string('status')->default('active');
            $table->string('auth_method')->default('password');
            $table->rememberToken();
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

    public function test_only_agent_and_mop_are_eligible_for_runtime_location_permission(): void
    {
        $context = $this->context();

        foreach (['agentA', 'mopA'] as $key) {
            Sanctum::actingAs($context[$key]);

            $this->getJson('/api/location-tracking/me/policy')
                ->assertOk()
                ->assertJsonPath('data.eligible_for_location_permission', true)
                ->assertJsonPath('data.should_request_location_permission', true)
                ->assertJsonPath('data.tracking_enabled', true);
        }

        foreach (['ropA', 'directorA', 'admin'] as $key) {
            Sanctum::actingAs($context[$key]);

            $this->getJson('/api/location-tracking/me/policy')
                ->assertOk()
                ->assertJsonPath('data.eligible_for_location_permission', false)
                ->assertJsonPath('data.should_request_location_permission', false)
                ->assertJsonPath('data.tracking_enabled', false)
                ->assertJsonPath('data.mode', 'off');
        }
    }

    public function test_non_tracked_role_cannot_register_location_device(): void
    {
        $context = $this->context();
        Sanctum::actingAs($context['admin']);

        $this->putJson('/api/location-tracking/me/device', $this->devicePayload())
            ->assertStatus(403)
            ->assertJsonPath('code', 'LOCATION_FORBIDDEN_ROLE');

        $this->postJson('/api/location-tracking/me/points', [
            'device_uuid' => (string) Str::uuid(),
            'points' => [['invalid' => true]],
        ])->assertStatus(403)
            ->assertJsonPath('code', 'LOCATION_FORBIDDEN_ROLE');
    }

    public function test_agent_can_store_history_and_older_point_does_not_replace_current(): void
    {
        $context = $this->context();
        $agent = $context['agentA'];
        Sanctum::actingAs($agent);
        $device = $this->devicePayload();

        $this->putJson('/api/location-tracking/me/device', $device)->assertOk();
        $newerAt = now()->subMinute()->toISOString();
        $olderAt = now()->subMinutes(10)->toISOString();

        $this->postJson('/api/location-tracking/me/points', [
            'device_uuid' => $device['device_uuid'],
            'points' => [$this->pointPayload($newerAt, 38.5598000, 68.7870000)],
        ])->assertOk()
            ->assertJsonCount(1, 'data.accepted')
            ->assertJsonPath('data.accepted.0.current_location_updated', true);

        $olderResponse = $this->postJson('/api/location-tracking/me/points', [
            'device_uuid' => $device['device_uuid'],
            'points' => [$this->pointPayload($olderAt, 38.5000000, 68.7000000)],
        ]);
        $olderResponse->assertOk()
            ->assertJsonCount(1, 'data.accepted')
            ->assertJsonPath('data.accepted.0.current_location_updated', false);

        $this->assertSame(2, UserLocationPoint::query()->where('user_id', $agent->id)->count());
        $current = UserCurrentLocation::query()->findOrFail($agent->id);
        $this->assertEqualsWithDelta(38.5598, (float) $current->latitude, 0.0000001);
        $this->assertEqualsWithDelta(68.787, (float) $current->longitude, 0.0000001);
    }

    public function test_duplicate_event_is_idempotent(): void
    {
        $context = $this->context();
        Sanctum::actingAs($context['agentA']);
        $device = $this->devicePayload();
        $this->putJson('/api/location-tracking/me/device', $device)->assertOk();
        $point = $this->pointPayload(now()->subMinute()->toISOString(), 38.5598, 68.787);

        $payload = ['device_uuid' => $device['device_uuid'], 'points' => [$point]];
        $this->postJson('/api/location-tracking/me/points', $payload)->assertOk();
        $this->postJson('/api/location-tracking/me/points', $payload)
            ->assertOk()
            ->assertJsonCount(1, 'data.duplicates');

        $this->assertDatabaseCount('user_location_points', 1);
    }

    public function test_batch_broadcasts_only_the_latest_current_location_once(): void
    {
        $context = $this->context();
        Sanctum::actingAs($context['agentA']);
        $device = $this->devicePayload();
        $this->putJson('/api/location-tracking/me/device', $device)->assertOk();

        $this->postJson('/api/location-tracking/me/points', [
            'device_uuid' => $device['device_uuid'],
            'points' => [
                $this->pointPayload(now()->subMinutes(2)->toISOString(), 38.5590, 68.7860),
                $this->pointPayload(now()->subMinute()->toISOString(), 38.5600, 68.7880),
            ],
        ])->assertOk()->assertJsonCount(2, 'data.accepted');

        Event::assertDispatchedTimes(UserLocationUpdated::class, 1);
        Event::assertDispatched(UserLocationUpdated::class, function (UserLocationUpdated $event) {
            return $event->location['user_id'] === 1
                && abs($event->location['latitude'] - 38.5600) < 0.0000001
                && abs($event->location['longitude'] - 68.7880) < 0.0000001;
        });
    }

    public function test_private_location_channel_uses_the_same_scope_as_map(): void
    {
        $context = $this->context();
        Sanctum::actingAs($context['mopA']);
        $this->assertTrue(app(LocationAccessService::class)->canView($context['mopA'], $context['agentA']));

        $allowedResponse = $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-location.user.'.$context['agentA']->id,
        ]);
        $allowedResponse->assertOk()->assertJsonStructure(['auth']);

        $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-location.user.'.$context['agentB']->id,
        ])->assertForbidden();
    }

    public function test_mop_scope_and_selected_watchlist_cannot_include_foreign_group(): void
    {
        $context = $this->context();
        Sanctum::actingAs($context['mopA']);

        $this->getJson('/api/location-tracking/available-users')
            ->assertOk()
            ->assertJsonFragment(['id' => $context['agentA']->id])
            ->assertJsonMissing(['id' => $context['agentOtherGroup']->id])
            ->assertJsonMissing(['id' => $context['agentB']->id]);

        $this->putJson('/api/location-tracking/watchlist', [
            'mode' => 'selected',
            'user_ids' => [$context['agentA']->id],
        ])->assertOk()
            ->assertJsonPath('data.mode', 'selected')
            ->assertJsonPath('data.user_ids.0', $context['agentA']->id);

        $this->getJson('/api/location-tracking/map')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $context['agentA']->id);

        $this->putJson('/api/location-tracking/watchlist', [
            'mode' => 'selected',
            'user_ids' => [$context['agentB']->id],
        ])->assertStatus(422)
            ->assertJsonPath('code', 'LOCATION_INVALID_WATCHLIST');
    }

    public function test_rop_sees_only_agents_and_mops_of_own_branch(): void
    {
        $context = $this->context();
        Sanctum::actingAs($context['ropA']);

        $response = $this->getJson('/api/location-tracking/map')->assertOk();
        $ids = collect($response->json('data'))->pluck('user.id')->sort()->values()->all();
        $expected = collect([
            $context['agentA']->id,
            $context['agentOtherGroup']->id,
            $context['mopA']->id,
        ])->sort()->values()->all();

        $this->assertSame($expected, $ids);
        $this->assertNotContains($context['agentB']->id, $ids);
        $this->assertNotContains($context['ropA']->id, $ids);
    }

    public function test_dushanbe_demo_seeder_is_idempotent_and_updates_current_location(): void
    {
        $context = $this->context();
        $this->assertSame(1, $context['agentA']->id);

        $this->seed(DushanbeUserLocationSeeder::class);
        $this->seed(DushanbeUserLocationSeeder::class);

        $this->assertSame(14, UserLocationPoint::query()->where('user_id', 1)->count());
        $this->assertDatabaseHas('user_location_points', [
            'user_id' => 1,
            'latitude' => 38.5852000,
            'longitude' => 68.7861000,
        ]);
        $latest = UserCurrentLocation::query()->findOrFail(1);
        $this->assertEqualsWithDelta(38.5559, (float) $latest->latitude, 0.0000001);
        $this->assertEqualsWithDelta(68.7961, (float) $latest->longitude, 0.0000001);
        $this->assertSame(
            'Финиш — юго-восток центра',
            UserLocationPoint::query()->findOrFail($latest->location_point_id)->meta['label']
        );
    }

    private function context(): array
    {
        $roles = collect(['agent', 'mop', 'rop', 'branch_director', 'admin'])
            ->mapWithKeys(fn (string $slug) => [$slug => Role::query()->create([
                'name' => $slug,
                'slug' => $slug,
                'description' => $slug,
            ])]);
        $branchA = Branch::query()->create(['name' => 'A']);
        $branchB = Branch::query()->create(['name' => 'B']);
        $groupA1 = BranchGroup::query()->create(['name' => 'A1', 'branch_id' => $branchA->id]);
        $groupA2 = BranchGroup::query()->create(['name' => 'A2', 'branch_id' => $branchA->id]);
        $groupB = BranchGroup::query()->create(['name' => 'B1', 'branch_id' => $branchB->id]);

        return [
            'agentA' => $this->user($roles['agent'], $branchA, $groupA1, 'Agent A'),
            'agentOtherGroup' => $this->user($roles['agent'], $branchA, $groupA2, 'Agent A2'),
            'agentB' => $this->user($roles['agent'], $branchB, $groupB, 'Agent B'),
            'mopA' => $this->user($roles['mop'], $branchA, $groupA1, 'MOP A'),
            'ropA' => $this->user($roles['rop'], $branchA, null, 'ROP A'),
            'directorA' => $this->user($roles['branch_director'], $branchA, null, 'Director A'),
            'admin' => $this->user($roles['admin'], null, null, 'Admin'),
        ];
    }

    private function user(Role $role, ?Branch $branch, ?BranchGroup $group, string $name): User
    {
        return User::query()->create([
            'name' => $name,
            'phone' => '+992'.random_int(100000000, 999999999),
            'role_id' => $role->id,
            'branch_id' => $branch?->id,
            'branch_group_id' => $group?->id,
            'status' => User::STATUS_ACTIVE,
            'auth_method' => 'password',
        ]);
    }

    private function devicePayload(): array
    {
        return [
            'device_uuid' => (string) Str::uuid(),
            'platform' => 'android',
            'app_version' => '1.0.0',
            'os_version' => '16',
            'permission_status' => 'always',
            'background_permission' => true,
            'last_policy_version' => 1,
        ];
    }

    private function pointPayload(string $capturedAt, float $latitude, float $longitude): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy_m' => 18.5,
            'source' => 'fused',
            'app_state' => 'background',
            'battery_percent' => 70,
            'is_mocked' => false,
            'captured_at' => $capturedAt,
        ];
    }
}

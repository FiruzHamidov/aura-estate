<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PropertyTop50Test extends TestCase
{
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Property::unsetEventDispatcher();
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('phone')->unique();
            $table->string('password')->nullable();
            $table->foreignId('role_id');
            $table->foreignId('branch_id')->nullable();
            $table->foreignId('branch_group_id')->nullable();
            $table->string('status')->default('active');
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('properties', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('type_id')->nullable();
            $table->unsignedBigInteger('status_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('repair_type_id')->nullable();
            $table->decimal('price', 15, 2);
            $table->decimal('discount_price', 15, 2)->nullable();
            $table->string('currency', 3)->default('TJS');
            $table->string('offer_type')->default('sale');
            $table->integer('rooms')->nullable();
            $table->decimal('total_area', 10, 2)->nullable();
            $table->integer('floor')->nullable();
            $table->integer('total_floors')->nullable();
            $table->string('district')->nullable();
            $table->string('address')->nullable();
            $table->string('owner_phone')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->string('moderation_status')->default('pending');
            $table->string('listing_type')->default('regular');
            $table->timestamps();
        });
        Schema::create('property_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_id');
            $table->string('file_path');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        DB::table('branches')->insert([['id' => 1, 'name' => 'One'], ['id' => 2, 'name' => 'Two']]);
        (require database_path('migrations/2026_03_09_120000_create_branch_groups_table.php'))->up();
        DB::table('branch_groups')->insert(['id' => 1, 'branch_id' => 1, 'name' => 'Assigned']);
        (require database_path('migrations/2026_09_08_120000_create_rop_group_access.php'))->up();
        (require database_path('migrations/2026_09_04_100000_add_property_moderation_workflow.php'))->up();
        (require database_path('migrations/2026_11_16_100000_add_escalated_at_to_property_moderation_cases.php'))->up();
        (require database_path('migrations/2025_06_23_004510_create_notifications_table.php'))->up();
        (require database_path('migrations/2026_04_04_180000_expand_notifications_table.php'))->up();
        Schema::table('roles', fn (Blueprint $t) => $t->json('permissions')->nullable());
        Schema::table('properties', function (Blueprint $t) {
            $t->unsignedBigInteger('views_count')->default(0);
            $t->timestamp('sold_at')->nullable();
            $t->unsignedBigInteger('co_owner_user_id')->nullable();
        });
        Schema::table('branches', fn (Blueprint $t) => $t->boolean('is_hidden')->default(false));
        Schema::create('bookings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('property_id');
            $t->unsignedBigInteger('agent_id')->nullable();
            $t->unsignedBigInteger('co_agent_id')->nullable();
            $t->string('status');
            $t->dateTime('start_time');
        });
        (require database_path('migrations/2026_11_26_160000_index_completed_property_showings.php'))->up();
        (require database_path('migrations/2026_10_10_090000_create_property_analytics_daily.php'))->up();
        $this->travelTo(Carbon::parse('2026-11-26 10:00:00', 'Asia/Dushanbe'));
        DB::table('property_analytics_coverage')->where('id', 1)->update(['started_at' => '2026-10-10T12:00:00+05:00']);
        $this->actor = $this->actor('all');
        Sanctum::actingAs($this->actor);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        Property::setEventDispatcher(app('events'));
        parent::tearDown();
    }

    private function actor(string $scope, array $additional = []): User
    {
        $role = Role::forceCreate(['slug' => 'custom_'.uniqid(), 'name' => 'Custom', 'permissions' => [
            'properties.list.internal', 'properties.site.own', 'properties.list.scope.'.$scope, ...$additional,
        ]]);

        return User::forceCreate(['name' => 'Agent', 'phone' => uniqid(), 'role_id' => $role->id, 'branch_id' => 1, 'branch_group_id' => 1, 'status' => 'active']);
    }

    private function listing(array $overrides = []): int
    {
        return DB::table('properties')->insertGetId(array_merge([
            'title' => 'Квартира', 'price' => 500000, 'currency' => 'TJS', 'offer_type' => 'sale',
            'created_by' => $this->actor->id, 'agent_id' => $this->actor->id, 'branch_id' => 1, 'branch_group_id' => 1,
            'moderation_status' => 'approved', 'publication_status' => 'published', 'deal_status' => 'available',
            'views_count' => 10, 'owner_phone' => 'private-owner',
        ], $overrides));
    }

    private function showing(int $id, string $status = 'completed', string $at = '2026-11-25 12:00:00'): void
    {
        DB::table('bookings')->insert(['property_id' => $id, 'agent_id' => $this->actor->id, 'co_agent_id' => $this->actor->id,
            'status' => $status, 'start_time' => $at]);
    }

    public function test_top50_counts_all_showing_statuses_and_future_meetings_once(): void
    {
        $first = $this->listing();
        $second = $this->listing();
        $upcoming = $this->listing();
        $this->listing(); // A property without bookings stays outside the ranking.
        foreach (['completed', 'completed', 'cancelled', 'pending', 'confirmed', 'not_held'] as $status) {
            $this->showing($first, $status);
        }
        $this->showing($second);
        $this->showing($second, 'completed', '2026-10-31 18:59:59');
        $this->showing($upcoming, 'pending', '2026-11-27 12:00:00');
        $this->showing($upcoming, 'confirmed', '2026-11-26 18:00:00');
        $r = $this->getJson('/api/properties/top-50')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.property.id', $first)->assertJsonPath('data.0.metric_count', 6)
            ->assertJsonPath('data.1.property.id', $upcoming)->assertJsonPath('data.1.metric_count', 2)
            ->assertJsonPath('data.2.property.id', $second)->assertJsonPath('data.2.metric_count', 1)
            ->assertJsonMissingPath('data.0.property.owner_phone');
        self::assertStringNotContainsString('private-owner', $r->getContent());
        $this->getJson('/api/properties/top-50?period=all')->assertOk()
            ->assertJsonPath('data.1.property.id', $second)->assertJsonPath('data.1.metric_count', 2)
            ->assertJsonPath('data.2.property.id', $upcoming)->assertJsonPath('data.2.metric_count', 2);
        $this->getJson('/api/properties/top-50?period=today')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.metric_count', 1);
        $this->getJson('/api/properties/top-50?period=custom&date_from=2026-11-26&date_to=2026-11-26')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.metric_count', 1);
        $this->getJson('/api/properties/top-50?period=custom&date_from=2026-10-31&date_to=2026-10-31')->assertOk()->assertJsonPath('data.0.property.id', $second);
    }

    public function test_showings_include_full_dushanbe_periods_and_unlimited_future_dates_for_all_time(): void
    {
        $this->travelTo(Carbon::parse('2026-11-26T00:30:00+05:00'));
        $id = $this->listing();
        foreach (['2026-10-31 18:59:59', '2026-10-31 19:00:00', '2026-11-22 18:59:59',
            '2026-11-22 19:00:00', '2026-11-25 18:59:59', '2026-11-25 19:00:00',
            '2026-11-26 18:59:59', '2026-11-26 19:00:00', '2026-11-29 18:59:59',
            '2026-11-29 19:00:00', '2026-11-30 18:59:59', '2026-11-30 19:00:00'] as $at) {
            $this->showing($id, 'pending', $at);
        }
        $this->getJson('/api/properties/top-50?period=today')->assertOk()->assertJsonPath('data.0.metric_count', 2)->assertJsonPath('filters.date_to', '2026-11-26');
        $this->getJson('/api/properties/top-50?period=week')->assertOk()->assertJsonPath('data.0.metric_count', 6)->assertJsonPath('filters.date_to', '2026-11-29');
        $this->getJson('/api/properties/top-50?period=month')->assertOk()->assertJsonPath('data.0.metric_count', 10)->assertJsonPath('filters.date_to', '2026-11-30');
        $this->getJson('/api/properties/top-50?period=all')->assertOk()->assertJsonPath('data.0.metric_count', 12)->assertJsonPath('filters.date_to', null);
        $this->getJson('/api/properties/top-50?period=custom&date_from=2026-10-31&date_to=2026-10-31')->assertOk()->assertJsonPath('data.0.metric_count', 1);
        $this->getJson('/api/properties/top-50?period=custom&date_from=2026-12-01&date_to=2026-12-01')->assertOk()->assertJsonPath('data.0.metric_count', 1);
        $this->assertSame(12, DB::table('bookings')->where('status', 'pending')->count());
    }

    public function test_top50_views_use_daily_values_for_period_and_lifetime_for_all_and_cap_at_50(): void
    {
        $first = $this->listing(['views_count' => 999]);
        $second = $this->listing(['views_count' => 50, 'offer_type' => 'rent']);
        DB::table('property_analytics_daily')->insert([
            ['property_id' => $first, 'day' => '2026-11-25', 'views_count' => 2],
            ['property_id' => $second, 'day' => '2026-11-25', 'views_count' => 8],
            ['property_id' => $first, 'day' => '2026-10-31', 'views_count' => 30],
        ]);
        $this->getJson('/api/properties/top-50?tab=viewed')->assertOk()->assertJsonPath('data.0.property.id', $second)->assertJsonPath('data.0.metric_count', 8);
        $this->getJson('/api/properties/top-50?tab=viewed&offer_type=sale')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.metric_count', 2);
        $this->getJson('/api/properties/top-50?tab=viewed&period=all')->assertOk()->assertJsonPath('data.0.property.id', $first)->assertJsonPath('data.0.metric_count', 999);
        for ($i = 0; $i < 55; $i++) {
            $this->listing(['views_count' => 10]);
        }
        $this->getJson('/api/properties/top-50?tab=viewed&period=all')->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('data.49.position', 50);
        $this->getJson('/api/properties/top-50?tab=viewed&period=custom&date_from=2026-10-01&date_to=2026-10-31')->assertOk()->assertJsonPath('data_quality', 'partial');
    }

    public function test_top50_all_tabs_exclude_unavailable_objects_even_with_high_counters(): void
    {
        $valid = $this->listing();
        $states = [
            ['publication_status' => 'hidden'], ['publication_status' => 'pending'], ['moderation_status' => 'sold'],
            ['moderation_status' => 'rented'], ['moderation_status' => 'deleted'], ['moderation_status' => 'draft'],
            ['moderation_status' => 'deposit'], ['deal_status' => 'deposit'], ['sold_at' => '2026-11-01 10:00:00'],
            ['duplicate_of_property_id' => $valid], ['branch_id' => 2],
        ];
        DB::table('branches')->where('id', 2)->update(['is_hidden' => true]);
        $ids = [$valid];
        foreach ($states as $state) {
            $ids[] = $this->listing($state);
        }
        foreach ($ids as $id) {
            $this->showing($id);
            $this->promotion($id);
        }
        foreach (['shown', 'viewed', 'urgent'] as $tab) {
            $this->getJson('/api/properties/top-50?period=all&tab='.$tab)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.property.id', $valid);
        }
    }

    private function promotion(int $id, array $changes = []): void
    {
        DB::table('property_promotions')->insert(array_merge(['property_id' => $id, 'type' => 'urgent', 'status' => 'active',
            'requested_by' => $this->actor->id, 'requested_at' => '2026-11-25 12:00:00', 'starts_at' => '2026-11-25 12:00:00', 'ends_at' => '2026-12-01 12:00:00'], $changes));
    }

    public function test_top50_supports_current_schema_and_enforces_expiry_when_available(): void
    {
        // The production migrations do not create publication_expires_at.
        self::assertFalse(Schema::hasColumn('properties', 'publication_expires_at'));
        $valid = $this->listing();
        $expired = $this->listing();
        foreach ([$valid, $expired] as $id) {
            $this->showing($id);
            $this->promotion($id);
        }
        foreach (['shown', 'viewed', 'urgent'] as $tab) {
            $this->getJson('/api/properties/top-50?period=all&tab='.$tab)->assertOk()->assertJsonCount(2, 'data');
        }

        Schema::table('properties', fn (Blueprint $t) => $t->timestamp('publication_expires_at')->nullable());
        DB::table('properties')->where('id', $expired)->update(['publication_expires_at' => '2026-11-26 10:00:00']);
        foreach (['shown', 'viewed', 'urgent'] as $tab) {
            $this->getJson('/api/properties/top-50?period=all&tab='.$tab)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.property.id', $valid);
        }
    }

    public function test_top50_urgent_requires_active_unexpired_promotion_and_uses_its_start_date(): void
    {
        $old = $this->listing();
        $new = $this->listing();
        $this->promotion($old, ['starts_at' => '2026-10-31 12:00:00']);
        $this->promotion($new);
        foreach ([['status' => 'requested'], ['status' => 'revoked'], ['status' => 'suspended'], ['type' => 'vip'],
            ['starts_at' => '2026-11-27 12:00:00'], ['ends_at' => '2026-11-26 10:00:00']] as $change) {
            $this->promotion($this->listing(), $change);
        }
        $this->listing(['listing_type' => 'urgent']);
        $this->getJson('/api/properties/top-50?tab=urgent')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.property.id', $new)->assertJsonPath('data.0.metric_count', null);
        $this->getJson('/api/properties/top-50?tab=urgent&period=all')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.property.id', $new)->assertJsonPath('data.1.property.id', $old);
    }

    public function test_top50_keeps_own_and_assigned_group_scopes_and_rejects_missing_permissions(): void
    {
        $other = $this->actor('own');
        $own = $this->listing(['agent_id' => $other->id, 'created_by' => $other->id]);
        $this->listing();
        Sanctum::actingAs($other);
        $this->getJson('/api/properties/top-50?tab=viewed&period=all&scope=all')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.property.id', $own);
        $other->role->forceFill(['permissions' => []])->save();
        $other->unsetRelation('role');
        $this->getJson('/api/properties/top-50')->assertForbidden();
        $restricted = $this->actor('all', ['properties.list.closed_only']);
        Sanctum::actingAs($restricted);
        $this->getJson('/api/properties/top-50')->assertForbidden();
    }

    public function test_top50_validates_period_and_does_not_claim_missing_coverage_is_complete(): void
    {
        foreach (['tab=wrong', 'period=custom', 'tab=viewed&period=custom&date_from=2026-11-27&date_to=2026-11-28',
            'tab=urgent&period=custom&date_from=2026-11-27&date_to=2026-11-28',
            'period=custom&date_from=2026-11-20&date_to=2026-11-01', 'period=custom&date_from=2020-01-01&date_to=2026-11-01'] as $params) {
            $this->getJson('/api/properties/top-50?'.$params)->assertUnprocessable();
        }
        DB::table('property_analytics_coverage')->delete();
        $this->getJson('/api/properties/top-50?tab=viewed')->assertOk()->assertJsonPath('data_quality', 'unknown');
        // Both dates count toward the 366-day limit, even exactly at midnight.
        $this->travelTo(Carbon::parse('2026-11-26 00:00:00', 'Asia/Dushanbe'));
        $this->getJson('/api/properties/top-50?period=custom&date_from=2025-11-26&date_to=2026-11-26')->assertOk();
        $this->getJson('/api/properties/top-50?period=custom&date_from=2025-11-25&date_to=2026-11-26')->assertUnprocessable();
    }

    public function test_top50_assigned_groups_and_blocking_moderation_are_enforced_before_limiting(): void
    {
        $manager = $this->actor('all', ['data.scope.assigned_groups']);
        $manager->supervisedGroups()->attach(1);
        DB::table('branch_groups')->insert(['id' => 2, 'branch_id' => 2, 'name' => 'Other']);
        $valid = $this->listing();
        $blocked = $this->listing();
        $this->listing(['branch_id' => 2, 'branch_group_id' => 2, 'views_count' => 1000]);
        DB::table('property_moderation_cases')->insert(['property_id' => $blocked, 'type' => 'content', 'status' => 'open', 'blocking' => true, 'submitted_at' => now()]);
        Sanctum::actingAs($manager);
        $this->getJson('/api/properties/top-50?tab=viewed&period=all')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.property.id', $valid);
        $manager->supervisedGroups()->detach();
        $this->getJson('/api/properties/top-50?tab=viewed&period=all')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_top50_day_boundaries_use_dushanbe_not_utc(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 20:00:00', 'UTC'));
        $p = $this->listing();
        $this->showing($p, 'completed', '2026-11-25 18:59:59');
        $this->showing($p, 'completed', '2026-11-25 19:00:00');
        $this->getJson('/api/properties/top-50?period=today')->assertOk()->assertJsonPath('filters.date_from', '2026-11-26')->assertJsonPath('data.0.metric_count', 1);
    }
}

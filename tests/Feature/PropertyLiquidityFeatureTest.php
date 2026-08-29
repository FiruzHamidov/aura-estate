<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\User;
use App\Services\PropertyLiquidity\PropertyLiquidityCalculator;
use App\Services\PropertyLiquidity\PropertyMarketDays;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PropertyLiquidityFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->createSchema();
        $this->seedCatalogsAndComparables();
    }

    public function test_calculator_marks_competitive_apartment_below_market_and_exposes_only_public_badge_to_guest(): void
    {
        $property = $this->createTarget(90_000, 1);
        $snapshot = app(PropertyLiquidityCalculator::class)->calculate($property);

        $this->assertNotNull($snapshot);
        $this->assertSame('below_market', $snapshot->price_position);
        $this->assertGreaterThanOrEqual(65, $snapshot->score);
        $this->assertSame('medium', $snapshot->confidence_level);

        $response = $this->getJson("/api/properties/{$property->id}/liquidity")->assertOk();
        $response->assertJsonPath('data.public_price_badge.code', 'below_market');
        $response->assertJsonMissingPath('data.price_position');
        $response->assertJsonMissingPath('data.score');
    }

    public function test_calculator_supports_every_production_property_type(): void
    {
        $profiles = [
            'secondary' => ['rooms' => 2, 'total_area' => 70, 'is_from_developer' => false],
            'new-buildings' => ['rooms' => 2, 'total_area' => 70, 'is_from_developer' => true],
            'houses' => ['rooms' => 4, 'total_area' => 180, 'is_from_developer' => false],
            'commercial' => ['rooms' => null, 'total_area' => 120, 'is_from_developer' => false],
            'parking' => ['rooms' => null, 'total_area' => 18, 'is_from_developer' => false],
            'industrial_base' => ['rooms' => null, 'total_area' => 900, 'is_from_developer' => false],
        ];

        foreach ($profiles as $slug => $profile) {
            $typeId = DB::table('property_types')->insertGetId([
                'name' => $slug,
                'slug' => $slug,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            for ($index = 0; $index < 3; $index++) {
                DB::table('properties')->insert($this->propertyRow($profile + [
                    'title' => "{$slug} comparable {$index}",
                    'type_id' => $typeId,
                    'price' => 100_000 + $index * 1_000,
                    'created_at' => now()->subDays(20 + $index),
                    'listed_at' => now()->subDays(20 + $index),
                ]));
            }

            $targetId = DB::table('properties')->insertGetId($this->propertyRow($profile + [
                'title' => "{$slug} target",
                'type_id' => $typeId,
                'price' => 90_000,
                'created_at' => now()->subDays(10),
                'listed_at' => now()->subDays(10),
            ]));

            $snapshot = app(PropertyLiquidityCalculator::class)
                ->calculate(Property::query()->findOrFail($targetId));

            $this->assertNotNull($snapshot, "The {$slug} production type should be calculated");
            $this->assertSame($typeId, $snapshot->property->type_id);
        }
    }

    public function test_above_market_position_is_hidden_from_client_but_visible_to_internal_owner(): void
    {
        $property = $this->createTarget(130_000, 1);
        app(PropertyLiquidityCalculator::class)->calculate($property);

        Sanctum::actingAs(User::query()->findOrFail(3));
        $this->getJson("/api/properties/{$property->id}/liquidity")
            ->assertOk()
            ->assertJsonPath('data.public_price_badge', null)
            ->assertJsonMissingPath('data.price_position');

        Sanctum::actingAs(User::query()->findOrFail(1));
        $this->getJson("/api/properties/{$property->id}/liquidity")
            ->assertOk()
            ->assertJsonPath('data.price_position.code', 'above_market')
            ->assertJsonStructure(['data' => ['score', 'components', 'market', 'factors']]);
    }

    public function test_agent_feed_is_limited_to_own_properties_and_marketing_can_update_promotion(): void
    {
        $own = $this->createTarget(90_000, 1);
        $other = $this->createTarget(88_000, 2);
        app(PropertyLiquidityCalculator::class)->calculate($own);
        app(PropertyLiquidityCalculator::class)->calculate($other);

        Sanctum::actingAs(User::query()->findOrFail(1));
        $response = $this->getJson('/api/properties/liquidity-feed?purpose=portfolio')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($own->id, $ids);
        $this->assertNotContains($other->id, $ids);
        $this->getJson('/api/reports/properties/liquidity')
            ->assertOk()
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.summary.below_market', 1);

        Sanctum::actingAs(User::query()->findOrFail(4));
        $this->patchJson("/api/marketing/property-promotions/{$own->id}", [
            'status' => 'planned',
            'channel' => 'instagram',
        ])->assertCreated()->assertJsonPath('data.status', 'planned');
        $this->assertDatabaseHas('property_social_promotions', [
            'property_id' => $own->id,
            'status' => 'planned',
            'channel' => 'instagram',
        ]);
    }

    public function test_rop_business_priority_is_audited_without_changing_calculated_score(): void
    {
        $property = $this->createTarget(90_000, 1);
        app(PropertyLiquidityCalculator::class)->calculate($property);
        $score = $property->fresh()->liquidity_score;

        Sanctum::actingAs(User::query()->findOrFail(5));
        $this->patchJson("/api/properties/{$property->id}/liquidity/business-priority", [
            'enabled' => true,
            'comment' => 'Продвигать в первую очередь на этой неделе',
        ])->assertOk()->assertJsonPath('data.enabled', true);

        $this->assertSame($score, $property->fresh()->liquidity_score);
        $this->assertDatabaseHas('property_liquidity_priority_logs', [
            'property_id' => $property->id,
            'enabled' => true,
            'changed_by' => 5,
        ]);
    }

    public function test_market_days_exclude_unpublished_periods_without_resetting_listing_age(): void
    {
        $listedAt = CarbonImmutable::parse('2026-08-01 00:00:00');
        $property = $this->createTarget(100_000, 1);
        $property->updateQuietly([
            'listed_at' => $listedAt,
            'created_at' => $listedAt,
            'sold_at' => $listedAt->addDays(20),
            'moderation_status' => 'sold',
        ]);
        DB::table('property_status_history')->insert([
            [
                'property_id' => $property->id,
                'from_status' => 'approved',
                'to_status' => 'paused',
                'changed_at' => $listedAt->addDays(5),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'property_id' => $property->id,
                'from_status' => 'paused',
                'to_status' => 'approved',
                'changed_at' => $listedAt->addDays(10),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertSame(15, app(PropertyMarketDays::class)->calculate($property->fresh()));
    }

    private function createTarget(float $price, int $agentId): Property
    {
        $id = DB::table('properties')->insertGetId($this->propertyRow([
            'title' => "Target {$price}",
            'price' => $price,
            'created_by' => $agentId,
            'agent_id' => $agentId,
            'views_count' => 30,
            'created_at' => now()->subDays(10),
            'listed_at' => now()->subDays(10),
        ]));

        return Property::query()->findOrFail($id);
    }

    private function seedCatalogsAndComparables(): void
    {
        $now = now();
        DB::table('roles')->insert([
            ['id' => 1, 'name' => 'Агент', 'slug' => 'agent', 'description' => '', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Клиент', 'slug' => 'client', 'description' => '', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'name' => 'Маркетолог', 'slug' => 'marketing', 'description' => '', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'name' => 'РОП', 'slug' => 'rop', 'description' => '', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Agent One', 'email' => 'a1@test.local', 'role_id' => 1, 'branch_id' => 1, 'branch_group_id' => 1, 'status' => 'active', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Agent Two', 'email' => 'a2@test.local', 'role_id' => 1, 'branch_id' => 2, 'branch_group_id' => 2, 'status' => 'active', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'name' => 'Client', 'email' => 'client@test.local', 'role_id' => 2, 'branch_id' => null, 'branch_group_id' => null, 'status' => 'active', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'name' => 'Marketing', 'email' => 'marketing@test.local', 'role_id' => 3, 'branch_id' => null, 'branch_group_id' => null, 'status' => 'active', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 5, 'name' => 'ROP', 'email' => 'rop@test.local', 'role_id' => 4, 'branch_id' => 1, 'branch_group_id' => null, 'status' => 'active', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('property_types')->insert(['id' => 1, 'name' => 'Квартира', 'slug' => 'apartment', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('property_statuses')->insert(['id' => 1, 'name' => 'Доступно', 'slug' => 'available', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('locations')->insert(['id' => 1, 'city' => 'Душанбе', 'district' => 'Сино', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('districts')->insert(['id' => 1, 'location_id' => 1, 'name' => 'Сино', 'slug' => 'sino', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        for ($i = 0; $i < 15; $i++) {
            DB::table('properties')->insert($this->propertyRow([
                'title' => "Sold {$i}",
                'moderation_status' => 'sold',
                'price' => 100_000 + ($i % 3) * 1_000,
                'actual_sale_price' => 100_000,
                'sold_at' => now()->subDays(30 + $i),
                'created_at' => now()->subDays(90 + $i),
                'listed_at' => now()->subDays(90 + $i),
            ]));
        }
        for ($i = 0; $i < 5; $i++) {
            DB::table('properties')->insert($this->propertyRow([
                'title' => "Active {$i}",
                'price' => 100_000 + ($i % 2) * 1_000,
                'views_count' => 20 + $i,
                'created_at' => now()->subDays(20 + $i),
                'listed_at' => now()->subDays(20 + $i),
            ]));
        }
    }

    private function propertyRow(array $overrides = []): array
    {
        $now = now();

        return array_merge([
            'title' => 'Apartment', 'description' => 'Полное описание квартиры',
            'type_id' => 1, 'status_id' => 1, 'location_id' => 1, 'repair_type_id' => null,
            'price' => 100_000, 'discount_price' => null, 'currency' => 'USD', 'offer_type' => 'sale',
            'rooms' => 2, 'total_area' => 100, 'floor' => 3, 'total_floors' => 10,
            'condition' => 'Хорошее', 'has_parking' => true, 'is_mortgage_available' => true,
            'is_from_developer' => false, 'moderation_status' => 'approved', 'district' => 'Сино',
            'district_id' => 1, 'created_by' => 1, 'agent_id' => 1, 'co_owner_user_id' => null,
            'branch_id' => 1, 'branch_group_id' => 1, 'views_count' => 0, 'listing_type' => 'regular',
            'sold_at' => null, 'actual_sale_price' => null, 'listing_updated_at' => $now,
            'listed_at' => $now, 'liquidity_score' => null, 'liquidity_category' => null,
            'liquidity_confidence' => null, 'price_position' => null, 'price_delta_pct' => null,
            'promotion_priority_score' => null, 'promotion_eligibility' => null,
            'liquidity_business_priority' => false, 'liquidity_business_priority_comment' => null,
            'liquidity_business_priority_by' => null, 'liquidity_business_priority_at' => null,
            'liquidity_calculated_at' => null, 'liquidity_model_version' => null,
            'created_at' => $now, 'updated_at' => $now,
        ], $overrides);
    }

    private function createSchema(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::create('property_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
        });
        Schema::create('property_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
        });
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('city');
            $table->string('district')->nullable();
            $table->timestamps();
        });
        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('location_id');
            $table->string('name');
            $table->string('slug');
            $table->boolean('is_active');
            $table->timestamps();
        });
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('type_id');
            $table->unsignedBigInteger('status_id');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('repair_type_id')->nullable();
            $table->decimal('price', 15, 2);
            $table->decimal('discount_price', 15, 2)->nullable();
            $table->string('currency', 3);
            $table->string('offer_type');
            $table->unsignedTinyInteger('rooms')->nullable();
            $table->decimal('total_area', 10, 2)->nullable();
            $table->integer('floor')->nullable();
            $table->integer('total_floors')->nullable();
            $table->string('condition')->nullable();
            $table->boolean('has_parking')->default(false);
            $table->boolean('is_mortgage_available')->default(false);
            $table->boolean('is_from_developer')->default(false);
            $table->string('moderation_status');
            $table->string('district')->nullable();
            $table->unsignedBigInteger('district_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('co_owner_user_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->unsignedBigInteger('views_count')->default(0);
            $table->string('listing_type')->default('regular');
            $table->timestamp('sold_at')->nullable();
            $table->decimal('actual_sale_price', 15, 2)->nullable();
            $table->timestamp('listing_updated_at')->nullable();
            $table->timestamp('listed_at')->nullable();
            $table->unsignedTinyInteger('liquidity_score')->nullable();
            $table->string('liquidity_category')->nullable();
            $table->unsignedTinyInteger('liquidity_confidence')->nullable();
            $table->string('price_position')->nullable();
            $table->decimal('price_delta_pct', 8, 2)->nullable();
            $table->unsignedTinyInteger('promotion_priority_score')->nullable();
            $table->string('promotion_eligibility')->nullable();
            $table->boolean('liquidity_business_priority')->default(false);
            $table->text('liquidity_business_priority_comment')->nullable();
            $table->unsignedBigInteger('liquidity_business_priority_by')->nullable();
            $table->timestamp('liquidity_business_priority_at')->nullable();
            $table->timestamp('liquidity_calculated_at')->nullable();
            $table->string('liquidity_model_version')->nullable();
            $table->timestamps();
        });
        Schema::create('property_photos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->string('file_path');
            $table->string('type')->default('photo');
            $table->integer('position')->default(0);
            $table->timestamps();
        });
        Schema::create('client_need_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug');
            $table->timestamps();
        });
        Schema::create('client_need_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('slug');
            $table->boolean('is_closed');
            $table->timestamps();
        });
        Schema::create('client_needs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('type_id');
            $table->unsignedBigInteger('status_id');
            $table->decimal('budget_from', 15, 2)->nullable();
            $table->decimal('budget_to', 15, 2)->nullable();
            $table->string('currency', 3);
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('district')->nullable();
            $table->unsignedBigInteger('district_id')->nullable();
            $table->unsignedBigInteger('property_type_id')->nullable();
            $table->unsignedInteger('rooms_from')->nullable();
            $table->unsignedInteger('rooms_to')->nullable();
            $table->decimal('area_from', 10, 2)->nullable();
            $table->decimal('area_to', 10, 2)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('property_liquidity_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedTinyInteger('score');
            $table->string('category');
            $table->unsignedTinyInteger('confidence_score');
            $table->string('confidence_level');
            $table->unsignedSmallInteger('predicted_days_from')->nullable();
            $table->unsignedSmallInteger('predicted_days_to')->nullable();
            $table->unsignedTinyInteger('district_market_score');
            $table->unsignedTinyInteger('price_score');
            $table->unsignedTinyInteger('demand_score');
            $table->unsignedTinyInteger('apartment_fit_score');
            $table->unsignedTinyInteger('interest_score')->nullable();
            $table->string('price_position');
            $table->decimal('price_delta_pct', 8, 2);
            $table->json('cohort_definition');
            $table->unsignedInteger('cohort_sold_count');
            $table->unsignedInteger('cohort_active_count');
            $table->unsignedSmallInteger('cohort_median_dom')->nullable();
            $table->decimal('cohort_median_price_sqm', 15, 2);
            $table->json('factors')->nullable();
            $table->json('recommendations')->nullable();
            $table->json('market')->nullable();
            $table->json('interest')->nullable();
            $table->string('model_version');
            $table->timestamp('calculated_at');
            $table->timestamps();
        });
        Schema::create('property_social_promotions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->string('channel')->nullable();
            $table->string('status');
            $table->unsignedTinyInteger('priority_score_snapshot')->nullable();
            $table->unsignedTinyInteger('liquidity_score_snapshot')->nullable();
            $table->timestamp('planned_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('published_url')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->text('skip_reason')->nullable();
            $table->text('notes')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();
        });
        Schema::create('property_status_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();
        });
        Schema::create('property_liquidity_priority_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->boolean('enabled');
            $table->text('comment');
            $table->unsignedBigInteger('changed_by');
            $table->timestamps();
        });
    }
}

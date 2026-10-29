<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\PropertyStatus;
use App\Models\PropertyType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PropertyMyPropertiesBranchFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('branch_groups', function (Blueprint $table) { $table->id(); $table->unsignedBigInteger('branch_id'); $table->string('name'); $table->timestamps(); });
        \Illuminate\Support\Facades\DB::table('branch_groups')->insert(['id' => 20, 'branch_id' => 2, 'name' => 'Group 20']);
        \Illuminate\Support\Facades\DB::table('branch_groups')->insert(['id' => 21, 'branch_id' => 2, 'name' => 'Group 21']);
        \Illuminate\Support\Facades\DB::table('branch_groups')->insert(['id' => 30, 'branch_id' => 3, 'name' => 'Group 30']);

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone')->unique();
            $table->string('password')->nullable();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->string('status')->default('active');
            $table->string('auth_method')->default('password');
            $table->rememberToken()->nullable();
            $table->timestamps();
        });

        Schema::create('property_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->timestamps();
        });

        Schema::create('property_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->timestamps();
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->unsignedBigInteger('type_id');
            $table->unsignedBigInteger('status_id');
            $table->decimal('price', 15, 2);
            $table->string('currency')->default('TJS');
            $table->string('offer_type')->default('sale');
            $table->string('moderation_status')->default('approved');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->timestamps();
        });

        Schema::create('property_photos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->string('file_path');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('property_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->json('changes')->nullable();
            $table->timestamps();
        });

        Schema::create('property_agent_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('agent_id');
            $table->string('role')->nullable();
            $table->decimal('agent_commission_amount', 15, 2)->nullable();
            $table->string('agent_commission_currency')->nullable();
            $table->timestamp('agent_paid_at')->nullable();
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

    public function test_internal_list_grant_overrides_role_gate_without_expanding_object_scope(): void
    {
        $type = PropertyType::create(['name' => 'Apartment']);
        $status = PropertyStatus::create(['name' => 'Active']);
        $actors = [];
        foreach (['client', 'external_agent', 'custom_reader'] as $index => $slug) {
            $role = Role::create(['name' => $slug, 'slug' => $slug]);
            $actor = User::create(['name' => $slug, 'phone' => 'list-reader-'.$index, 'role_id' => $role->id, 'branch_id' => 2, 'branch_group_id' => 20]);
            $property = Property::create(['title' => 'Own listing '.$index, 'type_id' => $type->id, 'status_id' => $status->id,
                'price' => 100000, 'currency' => 'TJS', 'offer_type' => 'sale', 'moderation_status' => 'approved',
                'created_by' => $actor->id, 'agent_id' => $actor->id, 'branch_id' => 2, 'branch_group_id' => 20]);
            $actors[] = [$role, $actor, $property];
        }
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        foreach ($actors as [$role, $actor, $property]) {
            $role->forceFill(['permissions' => ['properties.list.scope.own']])->save();
            Sanctum::actingAs($actor->fresh());
            $this->getJson('/api/my-properties')->assertForbidden();
            $this->getJson('/api/properties')->assertOk()->assertJsonCount(1, 'data');
            $role->forceFill(['permissions' => ['properties.list.internal', 'properties.list.scope.own']])->save();
            Sanctum::actingAs($actor->fresh());
            $this->getJson('/api/my-properties')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $property->id);
            $role->forceFill(['permissions' => ['properties.list.internal']])->save();
            Sanctum::actingAs($actor->fresh());
            $this->getJson('/api/my-properties')->assertOk()->assertJsonCount(0, 'data');
            $role->forceFill(['permissions' => ['properties.list.scope.own']])->save();
            Sanctum::actingAs($actor->fresh());
            $this->getJson('/api/my-properties')->assertForbidden();
        }
    }

    public function test_internal_responsibility_filter_keeps_unpublished_records_and_respects_access(): void
    {
        $role = Role::create(['name' => 'Reader', 'slug' => 'responsibility_reader']);
        $actor = User::create(['name' => 'Recipient', 'phone' => 'responsibility-reader', 'role_id' => $role->id, 'branch_id' => 2, 'branch_group_id' => 20]);
        $type = PropertyType::create(['name' => 'Apartment']);
        $status = PropertyStatus::create(['name' => 'Active']);
        $make = fn (array $extra) => Property::create(array_merge([
            'title' => 'Object', 'type_id' => $type->id, 'status_id' => $status->id,
            'price' => 100000, 'created_by' => 999, 'agent_id' => $actor->id,
            'branch_id' => 2, 'branch_group_id' => 20,
        ], $extra));
        $transferred = $make(['moderation_status' => 'approved']);
        $pending = $make(['moderation_status' => 'pending']);
        $unassigned = $make(['created_by' => $actor->id, 'agent_id' => null]);
        $make(['created_by' => $actor->id, 'agent_id' => 999]);
        $make(['branch_id' => 3, 'branch_group_id' => 30]);
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $role->forceFill(['permissions' => ['properties.list.internal', 'properties.list.scope.group']])->save();
        Sanctum::actingAs($actor->fresh());
        foreach ([(string) $actor->id, $actor->id.',888'] as $ids) {
            $response = $this->getJson('/api/my-properties?responsible_user_id='.$ids)->assertOk();
            $this->assertEqualsCanonicalizing([$transferred->id, $pending->id, $unassigned->id], array_column($response->json('data'), 'id'));
        }
    }

    public function test_report_drilldown_uses_creator_for_open_and_primary_seller_for_closed_without_widening_scope(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->unsignedBigInteger('sale_user_id')->nullable();
            $table->unsignedBigInteger('sale_agent_id')->nullable();
        });
        $role = Role::create(['name' => 'Report reader', 'slug' => 'report_reader']);
        $actor = User::create(['name' => 'Employee', 'phone' => 'report-reader', 'role_id' => $role->id,
            'branch_id' => 2, 'branch_group_id' => 20]);
        $type = PropertyType::create(['name' => 'Apartment']);
        $status = PropertyStatus::create(['name' => 'Active']);
        $make = function (array $extra) use ($type, $status) {
            return \Illuminate\Support\Facades\DB::table('properties')->insertGetId(array_merge([
                'title' => 'Report object', 'type_id' => $type->id, 'status_id' => $status->id,
                'price' => 100000, 'created_by' => 999, 'agent_id' => 999,
                'branch_id' => 2, 'branch_group_id' => 20, 'moderation_status' => 'approved',
            ], $extra));
        };
        $created = $make(['created_by' => $actor->id]);
        $legacyCreated = $make(['created_by' => 0, 'agent_id' => $actor->id]);
        $sold = $make(['moderation_status' => 'sold', 'sale_agent_id' => $actor->id, 'sale_user_id' => 888]);
        $legacySold = $make(['moderation_status' => 'rented', 'sale_user_id' => 0, 'agent_id' => $actor->id]);
        $make(['created_by' => $actor->id, 'moderation_status' => 'sold', 'sale_user_id' => 888]);
        $make(['agent_id' => $actor->id]);
        $make(['moderation_status' => 'sold', 'sale_user_id' => $actor->id, 'branch_id' => 3, 'branch_group_id' => 30]);
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $role->forceFill(['permissions' => ['properties.list.internal', 'properties.list.scope.group']])->save();
        Sanctum::actingAs($actor->fresh());
        $response = $this->getJson('/api/my-properties?report_employee_id='.$actor->id)->assertOk();
        $this->assertEqualsCanonicalizing([$created, $legacyCreated, $sold, $legacySold], array_column($response->json('data'), 'id'));
        $this->getJson('/api/my-properties?report_employee_id='.$actor->id.'&moderation_status=sold')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $sold);
    }

    public function test_report_context_matches_event_dates_and_credited_branch_without_changing_generic_lists(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->unsignedBigInteger('sale_user_id')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->timestamp('deposit_received_at')->nullable();
        });
        $allRole = Role::create(['name' => 'All reports', 'slug' => 'all_report_reader']);
        $groupRole = Role::create(['name' => 'Group reports', 'slug' => 'group_report_reader']);
        $creator = User::create(['name' => 'Creator A', 'phone' => 'report-creator', 'role_id' => $allRole->id,
            'branch_id' => 2, 'branch_group_id' => 20]);
        $seller = User::create(['name' => 'Seller B', 'phone' => 'report-seller', 'role_id' => $groupRole->id,
            'branch_id' => 3, 'branch_group_id' => 30]);
        $type = PropertyType::create(['name' => 'Apartment']);
        $status = PropertyStatus::create(['name' => 'Active']);
        $make = function (array $extra) use ($creator, $seller, $type, $status) {
            return \Illuminate\Support\Facades\DB::table('properties')->insertGetId(array_merge([
                'title' => 'Report event', 'type_id' => $type->id, 'status_id' => $status->id,
                'price' => 100000, 'created_by' => $creator->id, 'agent_id' => $creator->id,
                'branch_id' => 2, 'branch_group_id' => 20, 'moderation_status' => 'sold',
                'sale_user_id' => $seller->id, 'created_at' => '2026-02-10 12:00:00',
                'sold_at' => '2026-03-10 12:00:00',
            ], $extra));
        };
        $soldInMarch = $make([]);
        $make(['sold_at' => '2026-02-20 12:00:00', 'created_at' => '2026-03-10 12:00:00']);
        $undated = $make(['sold_at' => null]);
        $newInMarch = $make(['moderation_status' => 'approved', 'created_by' => $seller->id,
            'agent_id' => $seller->id, 'branch_id' => 3, 'branch_group_id' => 30,
            'created_at' => '2026-03-10 12:00:00', 'sold_at' => null]);
        $depositInMarch = $make(['moderation_status' => 'deposit', 'created_by' => $seller->id,
            'agent_id' => $seller->id, 'branch_id' => 3, 'branch_group_id' => 30,
            'deposit_received_at' => '2026-03-10 12:00:00', 'sold_at' => null]);
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $allRole->forceFill(['permissions' => ['properties.list.internal', 'properties.list.scope.all']])->save();
        $groupRole->forceFill(['permissions' => ['properties.list.internal', 'properties.list.scope.group']])->save();
        Sanctum::actingAs($creator->fresh());
        $period = '&branch_id=3&date_from=2026-03-01&date_to=2026-03-31';
        foreach (['report_context=1', 'report_employee_id='.$seller->id] as $mode) {
            $response = $this->getJson('/api/my-properties?'.$mode.$period)->assertOk();
            $this->assertEqualsCanonicalizing([$soldInMarch, $newInMarch, $depositInMarch], array_column($response->json('data'), 'id'));
        }
        $generic = $this->getJson('/api/my-properties?branch_id=3&date_from=2026-03-01&date_to=2026-03-31')->assertOk();
        $this->assertSame([$newInMarch], array_column($generic->json('data'), 'id'));
        $allTime = $this->getJson('/api/my-properties?report_context=1&branch_id=3')->assertOk();
        $this->assertContains($undated, array_column($allTime->json('data'), 'id'));
        Sanctum::actingAs($seller->fresh());
        $scoped = $this->getJson('/api/my-properties?report_context=1'.$period)->assertOk();
        $this->assertEqualsCanonicalizing([$newInMarch, $depositInMarch], array_column($scoped->json('data'), 'id'));
        $this->assertNotContains($soldInMarch, array_column($scoped->json('data'), 'id'));
    }

    public function test_own_scope_includes_assigned_listings_and_excludes_unrelated_records(): void
    {
        $role = Role::create(['name' => 'Recipient', 'slug' => 'own_recipient']);
        $actor = User::create(['name' => 'Recipient', 'phone' => 'own-recipient', 'role_id' => $role->id]);
        $type = PropertyType::create(['name' => 'Apartment']);
        $status = PropertyStatus::create(['name' => 'Active']);
        $make = fn (array $extra) => Property::create(array_merge([
            'title' => 'Object', 'type_id' => $type->id, 'status_id' => $status->id,
            'price' => 100000, 'created_by' => 999, 'agent_id' => $actor->id,
            'moderation_status' => 'approved',
        ], $extra));
        $assigned = $make([]);
        $own = $make(['created_by' => $actor->id, 'agent_id' => null]);
        $pending = $make(['moderation_status' => 'pending']);
        $make(['created_by' => $actor->id, 'agent_id' => 999]);
        $make(['agent_id' => 999]);
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $role->forceFill(['permissions' => ['properties.list.internal', 'properties.list.scope.own']])->save();
        Sanctum::actingAs($actor->fresh());
        $url = '/api/my-properties?responsible_user_id='.$actor->id;
        $response = $this->getJson($url.'&moderation_status=approved')->assertOk()->assertJsonPath('total', 2);
        $this->assertEqualsCanonicalizing([$assigned->id, $own->id], array_column($response->json('data'), 'id'));
        $this->getJson($url.'&moderation_status=approved&per_page=1')->assertOk()->assertJsonPath('total', 2);
        $this->getJson($url.'&moderation_status=pending')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $pending->id);
        $this->getJson('/api/my-properties?responsible_user_id=888')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_author_can_find_and_manage_a_legacy_denied_listing_before_and_after_responsibility_backfill(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('deletion_requested_at')->nullable();
        });
        Schema::table('properties', function (Blueprint $table): void {
            $table->string('deal_status')->nullable();
            $table->text('status_comment')->nullable();
            $table->unsignedBigInteger('sale_user_id')->nullable();
        });
        $role = Role::create(['name' => 'Author', 'slug' => 'own_author']);
        $author = User::create(['name' => 'Author', 'phone' => 'own-author', 'role_id' => $role->id]);
        $other = User::create(['name' => 'Other agent', 'phone' => 'own-other', 'role_id' => $role->id]);
        $type = PropertyType::create(['name' => 'Apartment']);
        $status = PropertyStatus::create(['name' => 'Active']);
        $property = Property::create([
            'title' => 'Legacy listing', 'type_id' => $type->id, 'status_id' => $status->id,
            'price' => 755000, 'created_by' => $author->id, 'agent_id' => null,
            'moderation_status' => 'denied', 'deal_status' => 'client_denied',
            'status_comment' => 'Продано другим агентом', 'sale_user_id' => $other->id,
        ]);
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $role->forceFill(['permissions' => ['properties.list.internal', 'properties.list.scope.own', 'properties.edit']])->save();
        $access = app(\App\Services\PropertyModeration\PropertyModerationAccess::class);

        foreach ([false, true] as $backfilled) {
            if ($backfilled) {
                (require database_path('migrations/2026_10_29_120000_backfill_property_responsible_authors.php'))->up();
            }
            Sanctum::actingAs($author->fresh());
            $url = '/api/my-properties?responsible_user_id='.$author->id;
            foreach ([1, 20] as $perPage) {
                $this->getJson($url.'&moderation_status=denied&per_page='.$perPage)->assertOk()
                    ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $property->id)
                    ->assertJsonPath('data.0.agent_id', $backfilled ? $author->id : null);
            }
            $this->getJson($url.'&moderation_status=sold')->assertOk()->assertJsonPath('total', 0);
            $this->assertTrue($access->canEdit($author->fresh(), $property->fresh()));
            $this->assertFalse($access->canEdit($other->fresh(), $property->fresh()));

            Sanctum::actingAs($other->fresh());
            $this->getJson('/api/my-properties?moderation_status=denied&responsible_user_id='.$other->id)
                ->assertOk()->assertJsonPath('total', 0);
        }
        $this->assertDatabaseHas('properties', [
            'id' => $property->id, 'agent_id' => $author->id, 'created_by' => $author->id,
            'moderation_status' => 'denied', 'deal_status' => 'client_denied',
            'status_comment' => 'Продано другим агентом', 'sale_user_id' => $other->id,
        ]);
    }

    public function test_custom_list_scopes_are_shared_by_list_count_map_and_my_properties(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->unsignedBigInteger('co_owner_user_id')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->decimal('discount_price', 15, 2)->nullable();
        });
        $role = Role::create(['name' => 'Custom list reader', 'slug' => 'custom_list_reader']);
        $actor = User::create(['name' => 'Actor', 'phone' => 'scope-actor', 'role_id' => $role->id, 'branch_id' => 2, 'branch_group_id' => 20]);
        $other = User::create(['name' => 'Other', 'phone' => 'scope-other', 'role_id' => $role->id, 'branch_id' => 3, 'branch_group_id' => 30]);
        $type = PropertyType::create(['name' => 'Apartment']);
        $status = PropertyStatus::create(['name' => 'Active']);
        $make = fn (array $extra) => Property::create(array_merge([
            'title' => 'Scoped object', 'type_id' => $type->id, 'status_id' => $status->id,
            'price' => 100000, 'currency' => 'TJS', 'offer_type' => 'sale', 'moderation_status' => 'approved',
            'created_by' => $other->id, 'agent_id' => $other->id, 'branch_id' => 3, 'branch_group_id' => 30,
            'latitude' => 38.5, 'longitude' => 68.8,
        ], $extra));
        $own = $make(['created_by' => $actor->id, 'agent_id' => $actor->id, 'branch_id' => 2, 'branch_group_id' => 20]);
        $group = $make(['moderation_status' => 'pending', 'branch_id' => 2, 'branch_group_id' => 20]);
        $foreign = $make([]);
        $deleted = $make(['created_by' => $actor->id, 'branch_id' => 2, 'branch_group_id' => 20, 'moderation_status' => 'deleted']);
        $coOwned = $make(['co_owner_user_id' => $actor->id]);
        $assignedOnly = $make(['agent_id' => $actor->id]);
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $this->assertContains('properties.list.scope.all', $role->fresh()->permissions);
        $grant = function (array $permissions) use ($role, $actor): void {
            $role->forceFill(['permissions' => ['properties.list.internal', ...$permissions]])->save();
            Sanctum::actingAs($actor->fresh());
        };
        foreach ([
            'own' => [$own->id, $coOwned->id, $assignedOnly->id],
            'group' => [$own->id, $group->id],
            'public' => [$own->id, $foreign->id, $coOwned->id, $assignedOnly->id],
            'all' => [$own->id, $group->id, $foreign->id, $deleted->id, $coOwned->id, $assignedOnly->id],
            'none' => [],
        ] as $scope => $ids) {
            $grant($scope === 'none' ? [] : ['properties.list.scope.'.$scope]);
            foreach (['/api/properties', '/api/my-properties'] as $url) {
                $response = $this->getJson($url)->assertOk();
                $this->assertEqualsCanonicalizing($ids, array_column($response->json('data'), 'id'), $scope.' '.$url);
            }
            $this->getJson('/api/properties/count')->assertOk()->assertJsonPath('count', count($ids));
            $map = $this->getJson('/api/properties/map?bbox=38.4,68.6,38.8,69.1&zoom=12')->assertOk();
            $this->assertEqualsCanonicalizing($ids, array_column(array_column($map->json('features'), 'properties'), 'id'));
        }
        $grant(['properties.list.scope.own']);
        $this->getJson('/api/my-properties?moderation_status=deleted')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $deleted->id);
        $own->forceFill(['moderation_status' => 'sold'])->saveQuietly();
        $foreign->forceFill(['moderation_status' => 'sold'])->saveQuietly();
        $grant(['properties.list.scope.own', 'properties.list.closed_only']);
        $report = $this->getJson('/api/my-properties')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $own->id);
        $this->assertSame([$actor->id], array_column($report->json('security_summary.agents'), 'id'));
    }

    public function test_security_report_only_returns_control_stages_and_scoped_totals(): void
    {
        $role = Role::create(['name' => 'СБ', 'slug' => 'security']);
        $sb = User::create(['name' => 'SB', 'phone' => '900000099', 'role_id' => $role->id, 'status' => 'active']);
        $type = PropertyType::create(['name' => 'Apartment']);
        $status = PropertyStatus::create(['name' => 'Available']);
        foreach (['approved', 'pending', 'draft', 'deposit', 'sold', 'sold_by_owner', 'rented', 'deleted'] as $stage) {
            Property::create(['title' => $stage, 'type_id' => $type->id, 'status_id' => $status->id,
                'price' => 100, 'currency' => 'TJS', 'created_by' => $sb->id, 'moderation_status' => $stage, 'branch_id' => 2]);
        }
        Sanctum::actingAs($sb);
        $response = $this->getJson('/api/my-properties?per_page=2')->assertOk()->assertJsonPath('total', 5)->assertJsonCount(2, 'data');
        $this->assertEquals(500, $response->json('security_summary.by_currency.0.sum_price'));
        $this->assertEquals(5, collect($response->json('security_summary.by_status'))->sum('total'));
        $this->getJson('/api/my-properties?moderation_status=approved')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/my-properties?moderation_status=approved,sold_by_owner')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.moderation_status', 'sold_by_owner');
        $this->getJson('/api/my-properties?moderation_status=sold_by_owner')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonCount(1, 'security_summary.by_status');
        $this->getJson('/api/my-properties?branch_id=3')->assertOk()->assertJsonPath('total', 0);
        $ids = [];
        for ($page = 1; $page <= 3; $page++) {
            $rows = $this->getJson('/api/my-properties?per_page=2&page='.$page)->assertOk()->json('data');
            foreach ($rows as $row) {
                $this->assertContains($row['moderation_status'], ['deposit', 'sold', 'sold_by_owner', 'rented', 'deleted']);
                $ids[] = $row['id'];
            }
        }
        $this->assertCount(5, array_unique($ids));
    }

    public function test_my_properties_filters_by_branch_id_and_excludes_other_branches(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $admin = User::create([
            'name' => 'Admin User',
            'phone' => '900000001',
            'password' => bcrypt('password'),
            'role_id' => $adminRole->id,
            'status' => 'active',
        ]);

        $branch2User = User::create([
            'name' => 'Branch 2 Agent',
            'phone' => '900000002',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => 2,
            'branch_group_id' => 20,
            'status' => 'active',
        ]);

        $branch3User = User::create([
            'name' => 'Branch 3 Agent',
            'phone' => '900000003',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => 3,
            'branch_group_id' => 30,
            'status' => 'active',
        ]);

        $type = PropertyType::create(['name' => 'Apartment']);
        $status = PropertyStatus::create(['name' => 'Available']);

        $inBranchByProperty = Property::create([
            'title' => 'Branch 2 direct',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 100000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $branch2User->id,
            'agent_id' => $branch2User->id,
            'branch_id' => 2,
            'branch_group_id' => 20,
        ]);

        $inBranchByAgentFallback = Property::create([
            'title' => 'Branch 2 fallback',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 110000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $branch2User->id,
            'agent_id' => $branch2User->id,
            'branch_id' => null,
            'branch_group_id' => 20,
        ]);

        Property::create([
            'title' => 'Branch 3 direct',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 120000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $branch3User->id,
            'agent_id' => $branch3User->id,
            'branch_id' => 3,
            'branch_group_id' => 30,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/my-properties?branch_id=2');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonFragment(['id' => $inBranchByProperty->id]);
        $response->assertJsonFragment(['id' => $inBranchByAgentFallback->id]);

        $returnedBranchIds = collect($response->json('data'))
            ->pluck('branch_id')
            ->map(fn ($id) => $id === null ? null : (int) $id)
            ->all();

        $this->assertNotContains(3, $returnedBranchIds);
    }

    public function test_my_properties_applies_branch_id_and_branch_group_id_together(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $admin = User::create([
            'name' => 'Admin User',
            'phone' => '900000011',
            'password' => bcrypt('password'),
            'role_id' => $adminRole->id,
            'status' => 'active',
        ]);

        $agent = User::create([
            'name' => 'Scoped Agent',
            'phone' => '900000012',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => 2,
            'branch_group_id' => 20,
            'status' => 'active',
        ]);

        $type = PropertyType::create(['name' => 'Apartment']);
        $status = PropertyStatus::create(['name' => 'Available']);

        $expected = Property::create([
            'title' => 'Branch 2 Group 20',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 130000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $agent->id,
            'agent_id' => $agent->id,
            'branch_id' => 2,
            'branch_group_id' => 20,
        ]);

        Property::create([
            'title' => 'Branch 2 Group 21',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 131000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $agent->id,
            'agent_id' => $agent->id,
            'branch_id' => 2,
            'branch_group_id' => 21,
        ]);

        Property::create([
            'title' => 'Branch 3 Group 30',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 132000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $agent->id,
            'agent_id' => $agent->id,
            'branch_id' => 3,
            'branch_group_id' => 30,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/my-properties?branch_id=2&branch_group_id=20');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['id' => $expected->id]);
    }

    public function test_accounting_report_resolves_seller_and_leaders_without_cross_group_names(): void
    {
        Schema::table('properties', fn (Blueprint $table) => $table->unsignedBigInteger('sale_user_id')->nullable());
        $users = [];
        foreach (['accountant', 'superadmin', 'agent', 'rop', 'mop'] as $index => $slug) {
            $role = Role::create(['name' => $slug, 'slug' => $slug]);
            $users[$slug] = User::create(['name' => $slug, 'phone' => '91000000'.$index,
                'role_id' => $role->id, 'branch_id' => 2, 'branch_group_id' => 20]);
        }
        $other = User::create(['name' => 'Other group MOP', 'phone' => '910000009',
            'role_id' => $users['mop']->role_id, 'branch_id' => 2, 'branch_group_id' => 21]);
        $type = PropertyType::create(['name' => 'Apartment']);
        $status = PropertyStatus::create(['name' => 'Available']);
        $property = Property::create(['title' => 'Report test', 'type_id' => $type->id,
            'status_id' => $status->id, 'price' => 100, 'created_by' => $users['accountant']->id,
            'agent_id' => $users['agent']->id, 'sale_user_id' => $users['agent']->id,
            'moderation_status' => 'sold']);
        $property->saleAgents()->attach($users['rop']->id, ['role' => 'partner', 'agent_commission_amount' => 250, 'agent_commission_currency' => 'TJS']);
        foreach (['accountant', 'superadmin'] as $slug) {
            Sanctum::actingAs($users[$slug]);
            $this->getJson('/api/my-properties?moderation_status=sold')->assertOk()
                ->assertJsonPath('data.0.report_seller.name', 'agent')
                ->assertJsonPath('data.0.report_rop.0.name', 'rop')
                ->assertJsonPath('data.0.report_rop.0.amount', 250)
                ->assertJsonPath('data.0.report_mop.0.amount', null)
                ->assertJsonPath('data.0.report_mop.0.name', 'mop')
                ->assertJsonCount(1, 'data.0.report_mop');
        }
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $custom = Role::create(['name' => 'Custom accountant', 'slug' => 'custom_accountant']);
        $custom->forceFill(['permissions' => ['properties.list.internal', 'properties.list.scope.all']])->save();
        Sanctum::actingAs($users['superadmin']->fresh());
        $users['accountant']->forceFill(['role_id' => $custom->id])->save();
        Sanctum::actingAs($users['accountant']->fresh());
        $this->getJson('/api/my-properties?moderation_status=sold')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.report_rop');
        $custom->forceFill(['permissions' => ['properties.list.internal', 'properties.list.scope.all', 'properties.reports.economics']])->save();
        Sanctum::actingAs($users['accountant']->fresh());
        $this->getJson('/api/my-properties?moderation_status=sold')->assertOk()->assertJsonPath('data.0.report_rop.0.amount', 250);
        $custom->forceFill(['permissions' => ['properties.list.internal', 'properties.list.scope.all']])->save();
        Sanctum::actingAs($users['accountant']->fresh());
        $this->getJson('/api/my-properties?moderation_status=sold')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.report_rop');
        Sanctum::actingAs($users['superadmin']->fresh());
        $users['accountant']->forceFill(['role_id' => Role::where('slug', 'accountant')->value('id')])->save();
        Sanctum::actingAs($users['accountant']->fresh());
        $this->getJson('/api/user?report_agents=1')->assertOk()->assertJsonMissingPath('data.0.phone');
        $this->postJson('/api/properties', [])->assertForbidden();
        $this->getJson('/api/user')->assertForbidden();
        Sanctum::actingAs($users['agent']);
        $property->updateQuietly(['created_by' => $users['agent']->id]);
        $this->getJson('/api/my-properties?moderation_status=sold')->assertOk()
            ->assertJsonMissingPath('data.0.report_rop');
    }
    private function consistencyFixture(): array
    {
        $role = Role::create(['name' => 'Reader', 'slug' => 'consistency-reader']);
        $actor = User::create(['name' => 'Current agent', 'phone' => 'consistency1', 'role_id' => $role->id]);
        $author = User::create(['name' => 'Previous author', 'phone' => 'consistency2', 'role_id' => $role->id]);
        $property = Property::create(['title' => 'Transferred', 'type_id' => PropertyType::create(['name' => 'Apartment'])->id,
            'status_id' => PropertyStatus::create(['name' => 'Active'])->id, 'price' => 100000, 'moderation_status' => 'approved',
            'created_by' => $author->id, 'agent_id' => $actor->id]);
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $role->forceFill(['permissions' => ['properties.list.internal', 'properties.list.scope.all', 'properties.edit']])->save();
        Sanctum::actingAs($actor->fresh());
        return [$actor, $author, $property];
    }

    public function test_internal_list_exposes_the_same_safe_responsible_contact_as_catalog(): void
    {
        [$actor, $author, $property] = $this->consistencyFixture();
        foreach (['/api/properties', '/api/my-properties'] as $url) {
            $this->getJson($url.'?responsible_user_id='.$actor->id)->assertOk()
                ->assertJsonPath('data.0.id', $property->id)
                ->assertJsonPath('data.0.creator.id', $author->id)
                ->assertJsonPath('data.0.responsible_agent.id', $actor->id)
                ->assertJsonPath('data.0.responsible_agent.name', $actor->name)
                ->assertJsonMissingPath('data.0.responsible_agent.email')
                ->assertJsonMissingPath('data.0.responsible_agent.phone')
                ->assertJsonMissingPath('data.0.agent');
        }
    }

    public function test_legacy_report_label_uses_the_same_seller_as_sql_and_credit_attribution(): void
    {
        [$actor, $other, $property] = $this->consistencyFixture();
        Schema::table('properties', fn (Blueprint $table) => $table->unsignedBigInteger('sale_user_id')->nullable());
        $property->forceFill(['moderation_status' => 'sold', 'sale_user_id' => null])->saveQuietly();
        $property->saleAgents()->attach($other->id, ['role' => 'main']);
        $service = app(\App\Services\SalesAttributionService::class);
        foreach ([null, $other->id] as $explicitSeller) {
            $property->refresh()->forceFill(['sale_user_id' => $explicitSeller])->saveQuietly();
            $rows = Property::whereKey($property->id)->get();
            app(\App\Services\PropertyReportStaff::class)->attach($rows);
            $expected = $explicitSeller ?? $actor->id;
            $this->assertSame($expected, $service->primarySellerId($property->fresh()));
            $this->assertSame($expected, $rows->first()->report_seller['id']);
            $this->assertSame($expected, (int) Property::whereKey($property->id)->selectRaw($service->primarySellerExpression().' AS credited_seller')->first()->credited_seller);
        }
    }

    public function test_personal_participation_includes_coowned_without_changing_responsibility_counts(): void
    {
        [$actor, $other, $assigned] = $this->consistencyFixture();
        Schema::table('properties', fn (Blueprint $table) => $table->unsignedBigInteger('co_owner_user_id')->nullable());
        $coowned = Property::create(['title' => 'Shared', 'type_id' => $assigned->type_id, 'status_id' => $assigned->status_id,
            'price' => 200000, 'created_by' => $other->id, 'agent_id' => $other->id, 'co_owner_user_id' => $actor->id]);
        $unrelated = Property::create(['title' => 'Other', 'type_id' => $assigned->type_id, 'status_id' => $assigned->status_id,
            'price' => 300000, 'created_by' => $other->id, 'agent_id' => $other->id]);
        $url = '/api/my-properties?responsible_user_id='.$actor->id;
        $this->getJson($url)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $assigned->id);
        $result = $this->getJson($url.'&include_co_owned=1')->assertOk()->assertJsonPath('total', 2);
        $this->assertEqualsCanonicalizing([$assigned->id, $coowned->id], array_column($result->json('data'), 'id'));
        $this->getJson('/api/properties/count?responsible_user_id='.$actor->id.'&include_co_owned=1')->assertOk()->assertJsonPath('count', 2);
    }

}

<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\ClientAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientNeedPropertyTypeFilterTest extends TestCase
{
    private int $phoneCounter = 940000000;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        (require database_path('migrations/2026_09_29_120000_create_client_catalog_claims_table.php'))->up();
        (require database_path('migrations/2026_11_10_100000_remove_client_catalog_daily_limit.php'))->up();

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->json('permissions')->nullable();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        (require database_path('migrations/2026_03_09_120000_create_branch_groups_table.php'))->up();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone')->unique();
            $table->string('password')->nullable();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->enum('auth_method', ['password', 'sms'])->default('password');
            $table->rememberToken()->nullable();
            $table->timestamps();
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('city');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('client_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_business')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('client_need_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('client_need_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_closed')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('property_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->timestamps();
        });

        Schema::create('repair_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->timestamps();
        });

        Schema::create('client_sources', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('phone')->nullable();
            $table->string('phone_normalized')->nullable();
            $table->string('email')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('responsible_agent_id')->nullable();
            $table->unsignedBigInteger('client_type_id')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('source_comment')->nullable();
            $table->string('contact_kind', 16)->default(Client::CONTACT_KIND_BUYER);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedBigInteger('bitrix_contact_id')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('client_collaborators', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role', 32)->default(Client::COLLABORATOR_ROLE_COLLABORATOR);
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'user_id']);
        });

        Schema::create('client_needs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('type_id');
            $table->unsignedBigInteger('status_id');
            $table->decimal('budget_from', 15, 2)->nullable();
            $table->decimal('budget_to', 15, 2)->nullable();
            $table->decimal('budget_total', 15, 2)->nullable();
            $table->decimal('budget_cash', 15, 2)->nullable();
            $table->boolean('has_cash_on_hand')->default(false);
            $table->decimal('cash_on_hand_amount', 15, 2)->nullable();
            $table->decimal('budget_mortgage', 15, 2)->nullable();
            $table->string('currency', 3)->default('TJS');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('district')->nullable();
            $table->unsignedBigInteger('property_type_id')->nullable();
            $table->unsignedBigInteger('repair_type_id')->nullable();
            $table->unsignedInteger('rooms_from')->nullable();
            $table->unsignedInteger('rooms_to')->nullable();
            $table->decimal('area_from', 10, 2)->nullable();
            $table->decimal('area_to', 10, 2)->nullable();
            $table->text('comment')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('responsible_agent_id')->nullable();
            $table->boolean('wants_mortgage')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('client_need_property_type', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_need_id');
            $table->unsignedBigInteger('property_type_id');
            $table->timestamps();
            $table->unique(['client_need_id', 'property_type_id']);
        });

        (require database_path('migrations/2026_04_28_130000_add_client_need_repair_types_table.php'))->up();

        Schema::create('crm_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('context')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('crm_client_id');
            $table->string('status')->default('pending');
            $table->dateTime('start_time')->nullable();
            $table->dateTime('end_time')->nullable();
            $table->timestamps();
        });
        foreach (['crm_deals', 'leads'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();
                $table->unsignedBigInteger('client_id');
                $table->dateTime('next_activity_at')->nullable();
                $table->dateTime('closed_at')->nullable();
                if ($name === 'leads') {
                    $table->dateTime('last_activity_at')->nullable();
                    $table->dateTime('first_contacted_at')->nullable();
                    $table->dateTime('next_follow_up_at')->nullable();
                }
                $table->softDeletes();
                $table->timestamps();
            });
        }
        Schema::create('crm_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('related_entity_type');
            $table->unsignedBigInteger('related_entity_id');
            $table->string('status')->default('new');
            $table->dateTime('due_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
        });

        DB::table('client_types')->insert([
            'id' => 1,
            'name' => 'Физлицо',
            'slug' => ClientType::SLUG_INDIVIDUAL,
            'is_business' => false,
            'sort_order' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('client_need_types')->insert([
            'id' => 1,
            'name' => 'Покупка',
            'slug' => 'buy',
            'sort_order' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('client_need_statuses')->insert([
            'id' => 1,
            'name' => 'Новая',
            'slug' => 'new',
            'is_closed' => false,
            'sort_order' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('repair_types')->insert([
            [
                'id' => 1,
                'name' => 'Черновой',
                'slug' => 'rough',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'name' => 'Косметический',
                'slug' => 'cosmetic',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 3,
                'name' => 'Дизайнерский',
                'slug' => 'designer',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('client_sources')->insert([
            [
                'id' => 1,
                'code' => 'phone',
                'name' => 'Телефон',
                'is_active' => true,
                'sort_order' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'code' => 'instagram',
                'name' => 'Instagram',
                'is_active' => true,
                'sort_order' => 20,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 3,
                'code' => 'other',
                'name' => 'Другое',
                'is_active' => false,
                'sort_order' => 30,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function test_clients_index_filters_by_property_type_ids_across_legacy_and_pivot_storage(): void
    {
        Setting::create([
            'key' => ClientAccess::VISIBILITY_SETTING_KEY,
            'value' => ClientAccess::VISIBILITY_ALL_BRANCH,
        ]);

        $branch = Branch::create(['name' => 'Main branch']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = $this->createUser($agentRole, $branch, 'Agent A');

        $houseTypeId = DB::table('property_types')->insertGetId([
            'name' => 'Дом',
            'slug' => 'houses',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $apartmentTypeId = DB::table('property_types')->insertGetId([
            'name' => 'Квартира',
            'slug' => 'apartments',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legacyHouseClient = $this->createClient($branch, $agent, 'Legacy house');
        $pivotHouseClient = $this->createClient($branch, $agent, 'Pivot house');
        $apartmentClient = $this->createClient($branch, $agent, 'Apartment only');

        DB::table('client_needs')->insert([
            'client_id' => $legacyHouseClient->id,
            'type_id' => 1,
            'status_id' => 1,
            'property_type_id' => $houseTypeId,
            'created_by' => $agent->id,
            'responsible_agent_id' => $agent->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pivotNeedId = DB::table('client_needs')->insertGetId([
            'client_id' => $pivotHouseClient->id,
            'type_id' => 1,
            'status_id' => 1,
            'property_type_id' => $apartmentTypeId,
            'created_by' => $agent->id,
            'responsible_agent_id' => $agent->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('client_need_property_type')->insert([
            'client_need_id' => $pivotNeedId,
            'property_type_id' => $houseTypeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('client_needs')->insert([
            'client_id' => $apartmentClient->id,
            'type_id' => 1,
            'status_id' => 1,
            'property_type_id' => $apartmentTypeId,
            'created_by' => $agent->id,
            'responsible_agent_id' => $agent->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($agent);

        $this->assertClientIds($this->getJson('/api/clients?contact_kind=buyer&property_type_ids[]='.$houseTypeId.'&per_page=15'), [$legacyHouseClient->id, $pivotHouseClient->id]);
    }

    public function test_property_type_filter_uses_related_type_id_not_pivot_row_id(): void
    {
        [$agent, $branch] = $this->prepareAgentContext();
        $house = DB::table('property_types')->insertGetId(['name' => 'House', 'slug' => 'house']);
        $apartment = DB::table('property_types')->insertGetId(['name' => 'Apartment', 'slug' => 'apartment']);
        $client = $this->createClient($branch, $agent, 'House via pivot only');
        $need = DB::table('client_needs')->insertGetId([
            'client_id' => $client->id, 'type_id' => 1, 'status_id' => 1,
            'created_by' => $agent->id, 'responsible_agent_id' => $agent->id,
        ]);
        // A coincident ID in the pivot must not be interpreted as a property type.
        DB::table('client_need_property_type')->insert([
            'id' => $apartment, 'client_need_id' => $need, 'property_type_id' => $house,
        ]);
        Sanctum::actingAs($agent);
        $this->assertClientIds($this->getJson('/api/clients?property_type_ids[]='.$house), [$client->id]);
        $this->assertClientIds($this->getJson('/api/clients?property_type_ids[]='.$apartment), []);
    }

    public function test_clients_index_filters_by_multiple_property_type_ids(): void
    {
        Setting::create([
            'key' => ClientAccess::VISIBILITY_SETTING_KEY,
            'value' => ClientAccess::VISIBILITY_ALL_BRANCH,
        ]);

        $branch = Branch::create(['name' => 'Main branch']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = $this->createUser($agentRole, $branch, 'Agent A');

        $houseTypeId = DB::table('property_types')->insertGetId([
            'name' => 'Дом',
            'slug' => 'houses',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $apartmentTypeId = DB::table('property_types')->insertGetId([
            'name' => 'Квартира',
            'slug' => 'apartments',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $houseClient = $this->createClient($branch, $agent, 'House client');
        $apartmentClient = $this->createClient($branch, $agent, 'Apartment client');
        $landClient = $this->createClient($branch, $agent, 'Land client');

        foreach ([
            [$houseClient->id, $houseTypeId],
            [$apartmentClient->id, $apartmentTypeId],
        ] as [$clientId, $propertyTypeId]) {
            DB::table('client_needs')->insert([
                'client_id' => $clientId,
                'type_id' => 1,
                'status_id' => 1,
                'property_type_id' => $propertyTypeId,
                'created_by' => $agent->id,
                'responsible_agent_id' => $agent->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Sanctum::actingAs($agent);

        $this->assertClientIds($this->getJson('/api/clients?property_type_ids[]='.$houseTypeId.'&property_type_ids[]='.$apartmentTypeId), [$houseClient->id, $apartmentClient->id]);
    }

    public function test_clients_index_filters_by_single_repair_type_id_in_array_param(): void
    {
        [$agent, $branch] = $this->prepareAgentContext();

        $matchClient = $this->createClient($branch, $agent, 'Repair match');
        $otherClient = $this->createClient($branch, $agent, 'Repair other');

        DB::table('client_needs')->insert([
            [
                'client_id' => $matchClient->id,
                'type_id' => 1,
                'status_id' => 1,
                'repair_type_id' => 1,
                'created_by' => $agent->id,
                'responsible_agent_id' => $agent->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'client_id' => $otherClient->id,
                'type_id' => 1,
                'status_id' => 1,
                'repair_type_id' => 2,
                'created_by' => $agent->id,
                'responsible_agent_id' => $agent->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Sanctum::actingAs($agent);

        $this->assertClientIds($this->getJson('/api/clients?repair_type_ids[]=1'), [$matchClient->id]);
    }

    public function test_clients_index_filters_by_multiple_repair_type_ids(): void
    {
        [$agent, $branch] = $this->prepareAgentContext();

        $firstClient = $this->createClient($branch, $agent, 'Repair one');
        $secondClient = $this->createClient($branch, $agent, 'Repair two');
        $thirdClient = $this->createClient($branch, $agent, 'Repair three');

        DB::table('client_needs')->insert([
            [
                'client_id' => $firstClient->id,
                'type_id' => 1,
                'status_id' => 1,
                'repair_type_id' => 1,
                'created_by' => $agent->id,
                'responsible_agent_id' => $agent->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'client_id' => $secondClient->id,
                'type_id' => 1,
                'status_id' => 1,
                'repair_type_id' => 2,
                'created_by' => $agent->id,
                'responsible_agent_id' => $agent->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'client_id' => $thirdClient->id,
                'type_id' => 1,
                'status_id' => 1,
                'repair_type_id' => 3,
                'created_by' => $agent->id,
                'responsible_agent_id' => $agent->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Sanctum::actingAs($agent);

        $this->assertClientIds($this->getJson('/api/clients?repair_type_ids=1,2'), [$firstClient->id, $secondClient->id]);
    }

    public function test_clients_index_ignores_empty_repair_type_ids_parameter(): void
    {
        [$agent, $branch] = $this->prepareAgentContext();
        $firstClient = $this->createClient($branch, $agent, 'First client');
        $secondClient = $this->createClient($branch, $agent, 'Second client');

        DB::table('client_needs')->insert([
            [
                'client_id' => $firstClient->id,
                'type_id' => 1,
                'status_id' => 1,
                'repair_type_id' => 1,
                'created_by' => $agent->id,
                'responsible_agent_id' => $agent->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'client_id' => $secondClient->id,
                'type_id' => 1,
                'status_id' => 1,
                'repair_type_id' => 2,
                'created_by' => $agent->id,
                'responsible_agent_id' => $agent->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Sanctum::actingAs($agent);

        $this->assertClientIds($this->getJson('/api/clients?repair_type_ids='), [$firstClient->id, $secondClient->id]);
    }

    public function test_clients_index_keeps_backward_compatibility_for_repair_type_id_and_prioritizes_repair_type_ids(): void
    {
        [$agent, $branch] = $this->prepareAgentContext();

        $legacyClient = $this->createClient($branch, $agent, 'Legacy filter client');
        $priorityClient = $this->createClient($branch, $agent, 'Priority filter client');

        DB::table('client_needs')->insert([
            [
                'client_id' => $legacyClient->id,
                'type_id' => 1,
                'status_id' => 1,
                'repair_type_id' => 1,
                'created_by' => $agent->id,
                'responsible_agent_id' => $agent->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'client_id' => $priorityClient->id,
                'type_id' => 1,
                'status_id' => 1,
                'repair_type_id' => 2,
                'created_by' => $agent->id,
                'responsible_agent_id' => $agent->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Sanctum::actingAs($agent);

        $this->assertClientIds($this->getJson('/api/clients?repair_type_id=1'), [$legacyClient->id]);

        $this->assertClientIds($this->getJson('/api/clients?repair_type_id=1&repair_type_ids[]=2'), [$priorityClient->id]);
    }

    public function test_clients_index_filters_by_cash_on_hand_and_amount_range(): void
    {
        [$agent, $branch] = $this->prepareAgentContext();

        $smallCashClient = $this->createClient($branch, $agent, 'Small ready cash');
        $largeCashClient = $this->createClient($branch, $agent, 'Large ready cash');
        $noCashClient = $this->createClient($branch, $agent, 'No ready cash');

        DB::table('client_needs')->insert([
            [
                'client_id' => $smallCashClient->id,
                'type_id' => 1,
                'status_id' => 1,
                'has_cash_on_hand' => true,
                'cash_on_hand_amount' => 100000,
                'created_by' => $agent->id,
                'responsible_agent_id' => $agent->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'client_id' => $largeCashClient->id,
                'type_id' => 1,
                'status_id' => 1,
                'has_cash_on_hand' => true,
                'cash_on_hand_amount' => 300000,
                'created_by' => $agent->id,
                'responsible_agent_id' => $agent->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'client_id' => $noCashClient->id,
                'type_id' => 1,
                'status_id' => 1,
                'has_cash_on_hand' => false,
                'cash_on_hand_amount' => null,
                'created_by' => $agent->id,
                'responsible_agent_id' => $agent->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Sanctum::actingAs($agent);

        $this->assertClientIds($this->getJson('/api/clients?has_cash_on_hand=1'), [$smallCashClient->id, $largeCashClient->id]);
        $this->assertClientIds($this->getJson('/api/clients?has_cash_on_hand=0'), [$noCashClient->id]);
        $this->assertClientIds(
            $this->getJson('/api/clients?cash_on_hand_amount_from=150000&cash_on_hand_amount_to=350000'),
            [$largeCashClient->id]
        );
    }

    public function test_sms_preview_and_send_follow_the_same_client_list_filters_across_pages(): void
    {
        (require database_path('migrations/2026_11_14_120000_create_sms_campaigns.php'))->up();
        [$agent, $branch] = $this->prepareAgentContext();
        $admin = $this->createUser(Role::create(['name' => 'Admin', 'slug' => 'admin']), $branch, 'Admin');
        $matches = [];
        foreach ([100000, 300000, 325000, 500000] as $amount) {
            $client = $this->createClient($branch, $agent, "Buyer $amount");
            $this->catalogNeed($client, $amount);
            if ($amount >= 300000 && $amount <= 350000) {
                $matches[] = $client->id;
            }
        }
        $filters = ['contact_kind' => 'buyer', 'full_name' => 'Buyer', 'responsible_agent_id' => $agent->id,
            'has_cash_on_hand' => '1', 'cash_on_hand_amount_from' => '300000', 'cash_on_hand_amount_to' => '350000'];
        Sanctum::actingAs($admin);
        $list = $this->getJson('/api/clients?'.http_build_query($filters + ['per_page' => 1]))->assertOk();
        $this->assertSame(2, $list->json('total'));
        $draft = ['request_id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Проверка', 'message' => 'Подборка',
            'segment' => ['base' => 'clients'] + $filters];
        $url = '/api/crm/sms-campaigns';
        $token = $this->postJson($url.'/preview', $draft)->assertOk()->assertJsonPath('total', 2)->json('preview_token');
        $id = $this->postJson($url, $draft + ['preview_token' => $token])->assertCreated()->json('id');
        $this->assertEqualsCanonicalizing($matches, DB::table('sms_campaign_recipients')->where('campaign_id', $id)->pluck('source_id')->all());
        $this->getJson($url)->assertOk()->assertJsonMissingPath('data.0.segment.full_name');
    }

    public function test_client_store_accepts_valid_source_id(): void
    {
        [$agent] = $this->prepareAgentContext();
        Sanctum::actingAs($agent);

        $this->postJson('/api/clients', [
            'full_name' => 'Source Test',
            'source_id' => 1,
            'source_comment' => 'С рекламы',
        ])->assertCreated()
            ->assertJsonPath('source_id', 1)
            ->assertJsonPath('source.id', 1)
            ->assertJsonPath('source.code', 'phone');
    }

    public function test_client_store_rejects_non_existing_source_id(): void
    {
        [$agent] = $this->prepareAgentContext();
        Sanctum::actingAs($agent);

        $this->postJson('/api/clients', [
            'full_name' => 'Invalid Source',
            'source_id' => 999,
        ])->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['source_id'], 'details.errors');
    }

    public function test_client_store_rejects_inactive_source_id(): void
    {
        [$agent] = $this->prepareAgentContext();
        Sanctum::actingAs($agent);

        $this->postJson('/api/clients', [
            'full_name' => 'Inactive Source',
            'source_id' => 3,
        ])->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['source_id'], 'details.errors');
    }

    public function test_clients_index_filters_by_source_id_and_source_ids_with_priority(): void
    {
        [$agent, $branch] = $this->prepareAgentContext();

        $phoneClient = $this->createClient($branch, $agent, 'Phone source');
        $instaClient = $this->createClient($branch, $agent, 'Insta source');

        $phoneClient->update(['source_id' => 1]);
        $instaClient->update(['source_id' => 2]);

        Sanctum::actingAs($agent);

        $this->assertClientIds($this->getJson('/api/clients?source_id=1'), [$phoneClient->id]);

        $this->assertClientIds($this->getJson('/api/clients?source_ids[]=1&source_ids[]=2'), [$phoneClient->id, $instaClient->id]);

        $this->getJson('/api/clients?source_ids=')
            ->assertOk()
            ->assertJsonPath('total', 2);

        $this->assertClientIds($this->getJson('/api/clients?source_id=1&source_ids[]=2'), [$instaClient->id]);
    }

    public function test_client_sources_endpoint_returns_only_active_sources_sorted(): void
    {
        $this->getJson('/api/client-sources')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.id', 1)
            ->assertJsonPath('0.code', 'phone')
            ->assertJsonPath('1.id', 2)
            ->assertJsonPath('1.code', 'instagram')
            ->assertJsonMissing(['id' => 3]);
    }

    public function test_catalog_shares_cross_branch_needs_without_contact_data(): void
    {
        [$agent, $branch] = $this->prepareAgentContext();
        $other = Branch::create(['name' => 'Other branch']);
        $owner = $this->createUser($agent->role, $other, 'Other owner');
        $client = $this->createClient($other, $owner, 'Shared client');
        $client->update(['phone' => '073287321', 'email' => 'private@example.com', 'note' => 'secret note']);
        $this->catalogNeed($client, 1200000, ['comment' => 'Ищет квартиру. Звонить +992 073 287 321 или private@example.com', 'meta' => json_encode(['phone' => '073287321'])]);
        Sanctum::actingAs($agent);
        $this->getJson('/api/client-catalog/counts')->assertOk()->assertExactJson(['total' => 1, 'lists' => ['agents' => 1, 'interns' => 0, 'unattended' => 0]]);
        $response = $this->getJson('/api/client-catalog?list=agents')->assertOk()
            ->assertJsonPath('data.0.id', $client->id)
            ->assertJsonPath('data.0.responsible_agent_name', 'Other owner')
            ->assertJsonPath('data.0.needs.0.comment', 'Ищет квартиру. Звонить [номер скрыт] или [контакт скрыт]');
        foreach (['073287321', 'private@example.com', 'secret note', '"phone"', '"email"'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertArrayNotHasKey('meta', $response->json('data.0.needs.0'));
        $this->getJson('/api/clients/'.$client->id)->assertForbidden();
        $this->assertEquals($owner->id, $client->fresh()->responsible_agent_id);
    }

    public function test_catalog_counts_unique_clients_and_excludes_unavailable_needs(): void
    {
        [$agent, $branch] = $this->prepareAgentContext();
        $client = $this->createClient($branch, $agent, 'Both lists');
        $this->catalogNeed($client, 1000000);
        $this->catalogNeed($client, 1000000);
        $this->catalogNeed($client, 300000);
        foreach ([['closed_at' => now()], ['currency' => 'USD'], ['has_cash_on_hand' => false], ['deleted_at' => now()]] as $attributes) {
            $excluded = $this->createClient($branch, $agent, 'Excluded');
            $this->catalogNeed($excluded, 1200000, $attributes);
        }
        $inactive = $this->createClient($branch, $agent, 'Inactive');
        $this->catalogNeed($inactive, 1200000);
        $inactive->update(['status' => 'inactive']);
        $deleted = $this->createClient($branch, $agent, 'Deleted');
        $this->catalogNeed($deleted, 1200000);
        $deleted->delete();
        Sanctum::actingAs($agent);
        $this->getJson('/api/client-catalog/counts')->assertOk()->assertExactJson(['total' => 2, 'lists' => ['agents' => 1, 'interns' => 1, 'unattended' => 1]]);
        $this->getJson('/api/client-catalog?list=interns')->assertOk()->assertJsonCount(1, 'data.0.needs');
    }

    public function test_catalog_limits_interns_and_denies_other_roles(): void
    {
        [$agent, $branch] = $this->prepareAgentContext();
        $client = $this->createClient($branch, $agent, 'High budget');
        $this->catalogNeed($client, 1000000);
        foreach (['intern', 'rop', 'mop', 'admin', 'superadmin', 'client', 'marketing'] as $slug) {
            $role = Role::create(['name' => $slug, 'slug' => $slug]);
            $user = $this->createUser($role, $branch, $slug);
            Sanctum::actingAs($user);
            if (in_array($slug, ['client', 'marketing'])) {
                $this->getJson('/api/client-catalog/counts')->assertForbidden();
                $this->getJson('/api/client-catalog')->assertForbidden();
            } elseif ($slug === 'intern') {
                $this->getJson('/api/client-catalog/counts')->assertOk()->assertExactJson(['total' => 0, 'lists' => ['interns' => 0, 'unattended' => 0]]);
                $this->getJson('/api/client-catalog?list=agents')->assertUnprocessable();
                $this->getJson('/api/client-catalog')->assertOk()->assertJsonCount(0, 'data');
            } else {
                $this->getJson('/api/client-catalog/counts')->assertOk()->assertJsonPath('total', 1);
            }
        }
    }

    public function test_catalog_grants_select_lists_independently_of_role(): void
    {
        [$agent, $branch] = $this->prepareAgentContext();
        $client = $this->createClient($branch, $agent, 'High budget');
        $this->catalogNeed($client, 1000000);
        $role = $agent->role;
        foreach (['custom_catalog', 'intern'] as $slug) {
            $role->forceFill(['slug' => $slug, 'permissions' => ['clients.catalog.view', 'clients.catalog.list.interns', 'clients.catalog.claim']])->save();
            Sanctum::actingAs($agent->fresh('role'));
            $this->getJson('/api/client-catalog/counts')->assertExactJson(['total' => 0, 'lists' => ['interns' => 0]]);
            $this->getJson('/api/client-catalog?list=agents')->assertUnprocessable();
            $this->postJson('/api/client-catalog/'.$client->id.'/claim')->assertConflict();
            $role->forceFill(['permissions' => ['clients.catalog.view', 'clients.catalog.list.agents']])->save();
            Sanctum::actingAs($agent->fresh('role'));
            $this->getJson('/api/client-catalog/counts')->assertExactJson(['total' => 1, 'lists' => ['agents' => 1]]);
            $this->getJson('/api/client-catalog')->assertOk()->assertJsonPath('data.0.id', $client->id)->assertJsonMissingPath('data.0.phone');
            $this->postJson('/api/client-catalog/'.$client->id.'/claim')->assertForbidden();
            $role->forceFill(['permissions' => ['clients.catalog.view']])->save();
            Sanctum::actingAs($agent->fresh('role'));
            $this->getJson('/api/client-catalog')->assertForbidden();
        }
        $this->assertDatabaseCount('client_catalog_claims', 0);
        $role->forceFill(['slug' => 'custom_claimant', 'permissions' => ['clients.catalog.view', 'clients.catalog.list.agents', 'clients.catalog.claim']])->save();
        Sanctum::actingAs($agent->fresh('role'));
        $this->postJson('/api/client-catalog/'.$client->id.'/claim')->assertOk()->assertJsonPath('data.id', $client->id)->assertJsonStructure(['data' => ['phone']]);
        $this->assertDatabaseCount('client_catalog_claims', 1);

    }

    public function test_catalog_paginates_oldest_clients_and_searches_only_names(): void
    {
        [$agent, $branch] = $this->prepareAgentContext();
        $new = $this->createClient($branch, $agent, 'New client');
        $old = $this->createClient($branch, $agent, 'Old client');
        $old->created_at = now()->subMonths(3);
        $old->save();
        $this->catalogNeed($new, 800000);
        $this->catalogNeed($old, 300000);
        Sanctum::actingAs($agent);
        $this->getJson('/api/client-catalog?list=interns&per_page=1')->assertOk()->assertJsonPath('data.0.id', $old->id)->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/client-catalog?list=interns&per_page=1&page=2')->assertOk()->assertJsonPath('data.0.id', $new->id);
        $this->getJson('/api/client-catalog?list=interns&search=New')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $new->id);
        $this->getJson('/api/client-catalog?search=073287321')->assertUnprocessable();
        $this->getJson('/api/client-catalog?search=private@example.com')->assertUnprocessable();
        $this->getJson('/api/client-catalog?per_page=1000')->assertUnprocessable();
    }

    public function test_catalog_requires_authentication(): void
    {
        $this->getJson('/api/client-catalog')->assertUnauthorized();
        $this->getJson('/api/client-catalog/counts')->assertUnauthorized();
    }

    public function test_claim_transfers_client_and_open_needs_and_reveals_phone_only_to_claimant(): void
    {
        [$agent, $branch] = $this->prepareAgentContext();
        $otherBranch = Branch::create(['name' => 'Other']);
        $owner = $this->createUser($agent->role, $otherBranch, 'Previous owner');
        $client = $this->createClient($otherBranch, $owner, 'Claim me');
        $this->catalogNeed($client, 1200000, ['responsible_agent_id' => $owner->id]);
        $this->catalogNeed($client, 1200000, ['responsible_agent_id' => $owner->id, 'closed_at' => now()]);
        Sanctum::actingAs($agent);
        $this->getJson('/api/client-catalog')->assertOk()->assertJsonMissingPath('data.0.phone');
        $this->postJson('/api/client-catalog/'.$client->id.'/claim')->assertOk()->assertJsonPath('data.phone', $client->phone);
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'responsible_agent_id' => $agent->id, 'branch_id' => $branch->id, 'created_by' => $owner->id]);
        $this->assertDatabaseHas('client_needs', ['client_id' => $client->id, 'closed_at' => null, 'responsible_agent_id' => $agent->id]);
        $this->assertDatabaseHas('client_needs', ['client_id' => $client->id, 'responsible_agent_id' => $owner->id]);
        $this->assertDatabaseHas('crm_audit_logs', ['auditable_id' => $client->id, 'actor_id' => $agent->id, 'event' => 'responsible_agent_changed']);
        $this->getJson('/api/client-catalog/counts')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/client-catalog/mine')->assertOk()->assertJsonPath('data.0.phone', $client->phone);
        $other = $this->createUser($agent->role, $branch, 'Other agent');
        Sanctum::actingAs($other);
        $this->getJson('/api/client-catalog/mine')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/client-catalog/'.$client->id.'/claim')->assertConflict()->assertJsonMissingPath('data.phone');
        $this->getJson('/api/client-catalog/claim-status')->assertOk()->assertJsonPath('can_claim', true);
    }

    public function test_claims_are_unlimited_on_the_same_day_with_idempotent_retries(): void
    {
        [$agent, $branch] = $this->prepareAgentContext();
        $first = $this->createClient($branch, $agent, 'First');
        $second = $this->createClient($branch, $agent, 'Second');
        $this->catalogNeed($first, 1000000);
        $this->catalogNeed($second, 1000000);
        $this->travelTo(\Carbon\Carbon::parse('2026-09-29 18:59:59', 'UTC'));
        Sanctum::actingAs($agent);
        $this->postJson('/api/client-catalog/'.$first->id.'/claim')->assertOk();
        $this->postJson('/api/client-catalog/'.$first->id.'/claim')->assertOk();
        $this->assertDatabaseCount('client_catalog_claims', 1);
        $this->getJson('/api/client-catalog/claim-status')->assertOk()->assertJsonPath('used_today', true)->assertJsonPath('can_claim', true)->assertJsonPath('next_available_at', null);
        $this->postJson('/api/client-catalog/'.$second->id.'/claim')->assertOk();
        $this->assertDatabaseCount('client_catalog_claims', 2);
        $this->travelTo(\Carbon\Carbon::parse('2026-09-29 19:00:00', 'UTC'));
        $this->getJson('/api/client-catalog/claim-status')->assertOk()->assertJsonPath('can_claim', true);
        $this->postJson('/api/client-catalog/'.$second->id.'/claim')->assertOk();
        $this->getJson('/api/client-catalog/mine')->assertOk()->assertJsonCount(2, 'data');
        $this->assertDatabaseCount('client_catalog_claims', 2);
        $this->travelBack();
    }

    public function test_claim_checks_roles_budget_and_branch(): void
    {
        [$agent, $branch] = $this->prepareAgentContext();
        $high = $this->createClient($branch, $agent, 'High');
        $low = $this->createClient($branch, $agent, 'Low');
        $this->catalogNeed($high, 1000000);
        $this->catalogNeed($low, 300000);
        foreach (['admin', 'superadmin', 'rop', 'mop', 'client'] as $slug) {
            $role = Role::create(['slug' => $slug, 'name' => $slug]);
            Sanctum::actingAs($this->createUser($role, $branch, $slug));
            $this->postJson('/api/client-catalog/'.$low->id.'/claim')->assertForbidden();
            $this->getJson('/api/client-catalog/mine')->assertForbidden();
        }
        $role = Role::create(['slug' => 'intern', 'name' => 'Intern']);
        $intern = $this->createUser($role, $branch, 'Intern');
        Sanctum::actingAs($intern);
        $this->postJson('/api/client-catalog/'.$high->id.'/claim')->assertConflict();
        $this->assertDatabaseCount('client_catalog_claims', 0);
        $intern->update(['branch_id' => null]);
        $this->postJson('/api/client-catalog/'.$low->id.'/claim')->assertUnprocessable();
        $intern->update(['branch_id' => $branch->id]);
        $this->postJson('/api/client-catalog/'.$low->id.'/claim')->assertOk();
        $this->getJson('/api/client-catalog/mine')->assertOk()->assertJsonPath('data.0.id', $low->id);
    }

    public function test_reassignment_revokes_access_and_preserves_claim_history(): void
    {
        [$agent, $branch] = $this->prepareAgentContext();
        $other = $this->createUser($agent->role, $branch, 'Next owner');
        $client = $this->createClient($branch, $agent, 'Transferred');
        $this->catalogNeed($client, 1000000);
        Sanctum::actingAs($agent);
        $this->postJson('/api/client-catalog/'.$client->id.'/claim')->assertOk();
        $client->update(['responsible_agent_id' => $other->id]);
        $this->getJson('/api/client-catalog/mine')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/client-catalog/'.$client->id.'/claim')->assertConflict();
        $client->delete();
        $this->getJson('/api/client-catalog/claim-status')->assertOk()->assertJsonPath('can_claim', true);
        $this->assertDatabaseCount('client_catalog_claims', 1);
    }

    public function test_failed_transfer_rolls_back_claim(): void
    {
        [$agent, $branch] = $this->prepareAgentContext();
        $owner = $this->createUser($agent->role, $branch, 'Owner');
        $client = $this->createClient($branch, $owner, 'Rollback');
        $this->catalogNeed($client, 1000000);
        $this->mock(\App\Services\Crm\AuditLogger::class, fn ($mock) => $mock->shouldReceive('log')->once()->andThrow(new \RuntimeException('Audit unavailable')));
        Sanctum::actingAs($agent);
        $this->postJson('/api/client-catalog/'.$client->id.'/claim')->assertStatus(500);
        $this->assertDatabaseCount('client_catalog_claims', 0);
        $this->assertEquals($owner->id, $client->fresh()->responsible_agent_id);
        $this->getJson('/api/client-catalog/claim-status')->assertOk()->assertJsonPath('can_claim', true);
    }

    public function test_claim_ledger_has_database_uniqueness_guards(): void
    {
        DB::table('client_catalog_claims')->insert(['client_id' => 1, 'user_id' => 1, 'claimed_on' => '2026-09-29', 'created_at' => now()]);
        foreach ([['client_id' => 1, 'user_id' => 2]] as $collision) {
            try {
                DB::table('client_catalog_claims')->insert($collision + ['claimed_on' => '2026-09-29', 'created_at' => now()]);
                $this->fail('The database must reject duplicate client claims.');
            } catch (\Illuminate\Database\QueryException $exception) {
                $this->assertStringContainsString('UNIQUE', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('client_catalog_claims', 1);
    }

    public function test_all_clients_catalog_includes_new_and_recent_clients_without_any_need(): void
    {
        $this->travelTo(now()->startOfSecond());
        [$agent, $branch] = $this->prepareAgentContext();
        $old = $this->unattendedClient($branch, $agent, 'Старый клиент');
        $boundary = $this->unattendedClient($branch, $agent, 'Граница');
        DB::table('clients')->where('id', $boundary->id)->update(['created_at' => now()->subDays(14), 'updated_at' => now()->subDays(14)]);
        $recent = $this->unattendedClient($branch, $agent, 'Недавний');
        DB::table('clients')->where('id', $recent->id)->update(['updated_at' => now()->subDays(14)->addSecond()]);
        $new = $this->createClient($branch, $agent, 'Новый');
        $seller = $this->createClient($branch, $agent, 'Продавец');
        $seller->update(['contact_kind' => 'seller']);
        foreach ([['has_cash_on_hand' => false, 'cash_on_hand_amount' => null], ['closed_at' => now()->subDays(20)], ['status_id' => 2]] as $attributes) {
            $client = $this->unattendedClient($branch, $agent, 'С потребностью');
            $this->catalogNeed($client, 100, $attributes + ['created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30)]);
        }
        $sellerWithNeed = $this->createClient($branch, $agent, 'Продавец с потребностью');
        $sellerWithNeed->update(['contact_kind' => 'seller']);
        $this->catalogNeed($sellerWithNeed, 1200000);
        foreach (['inactive', 'deleted'] as $excluded) {
            $client = $this->unattendedClient($branch, $agent, $excluded);
            DB::table('clients')->where('id', $client->id)->update(match ($excluded) {
                'inactive' => ['status' => 'inactive'], 'deleted' => ['deleted_at' => now()],
            });
        }
        Sanctum::actingAs($agent);
        $this->getJson('/api/client-catalog?list=unattended&per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 4)->assertJsonPath('meta.inactive_days', null)->assertJsonPath('meta.last_page', 4)
            ->assertJsonPath('data.0.id', $old->id)->assertJsonCount(0, 'data.0.needs')
            ->assertJsonPath('data.0.phone', $old->phone)->assertJsonMissingPath('data.0.note');
        $this->getJson('/api/client-catalog?list=unattended&per_page=1&page=2')->assertOk()->assertJsonPath('data.0.id', $recent->id);
        $this->getJson('/api/client-catalog?list=unattended')->assertOk()->assertJsonPath('data.2.id', $boundary->id)
            ->assertJsonPath('data.3.id', $new->id)->assertJsonCount(4, 'data');
        $this->getJson('/api/client-catalog?list=unattended&search='.urlencode('Старый'))->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/client-catalog?list=unattended&search='.$old->phone)->assertUnprocessable();
        $this->getJson('/api/client-catalog/counts')->assertOk()->assertExactJson(['total' => 4, 'lists' => ['agents' => 0, 'interns' => 0, 'unattended' => 4]]);
    }

    public function test_all_clients_catalog_excludes_sellers_from_search_counts_and_intake(): void
    {
        [$owner, $branch] = $this->prepareAgentContext();
        $agent = $this->createUser($owner->role, $branch, 'Catalog buyer agent');
        $seller = $this->createClient($branch, $owner, 'Seller only');
        $seller->update(['contact_kind' => 'seller']);
        $buyer = $this->createClient($branch, $owner, 'Buyer');
        $both = $this->createClient($branch, $owner, 'Mixed contact');
        $both->update(['contact_kind' => 'both']);
        Sanctum::actingAs($agent);

        $response = $this->getJson('/api/client-catalog?list=unattended')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertEqualsCanonicalizing([$buyer->id, $both->id], array_column($response->json('data'), 'id'));
        $this->getJson('/api/client-catalog?list=unattended&search=Seller')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/client-catalog/counts')->assertOk()->assertExactJson(['total' => 2, 'lists' => ['agents' => 0, 'interns' => 0, 'unattended' => 2]]);
        $this->postJson('/api/client-catalog/'.$seller->id.'/claim')->assertConflict();
        $this->postJson('/api/client-catalog/'.$seller->id.'/needs', ['type_id' => 1, 'status_id' => 1])->assertConflict();
        $this->assertDatabaseCount('client_catalog_claims', 0);
        $this->assertDatabaseCount('client_needs', 0);
        $this->assertEquals($owner->id, $seller->fresh()->responsible_agent_id);
    }

    public function test_all_clients_catalog_ignores_recent_and_planned_activity_when_no_need_exists(): void
    {
        [$agent, $branch] = $this->prepareAgentContext();
        $available = $this->unattendedClient($branch, $agent, 'Available');
        $old = ['created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30)];
        // Activity never excludes a client whose needs are absent.
        DB::table('crm_tasks')->insert($old + ['related_entity_type' => 'client', 'related_entity_id' => $available->id, 'status' => 'done', 'completed_at' => now()->subDays(20)]);
        $withActivity = [];
        foreach (['audit', 'booking', 'planned_booking', 'task', 'planned_task', 'deleted_need', 'deal', 'lead', 'deal_audit', 'lead_audit', 'deal_task', 'lead_task', 'showing_task'] as $activity) {
            $client = $this->unattendedClient($branch, $agent, $activity);
            $withActivity[] = $client;
            if ($activity === 'audit') {
                DB::table('crm_audit_logs')->insert(['auditable_type' => Client::class, 'auditable_id' => $client->id, 'event' => 'comment', 'created_at' => now(), 'updated_at' => now()]);
            } elseif ($activity === 'deleted_need') {
                $this->catalogNeed($client, 100, $old + ['deleted_at' => now()]);
            } elseif (in_array($activity, ['booking', 'planned_booking'])) {
                DB::table('bookings')->insert($old + ['crm_client_id' => $client->id, 'end_time' => $activity === 'booking' ? now()->subDay() : now()->addDay()]);
            } elseif (in_array($activity, ['task', 'planned_task'])) {
                DB::table('crm_tasks')->insert($old + ['related_entity_type' => 'client', 'related_entity_id' => $client->id,
                    'completed_at' => $activity === 'task' ? now() : null, 'due_at' => $activity === 'planned_task' ? now()->addDay() : null]);
            } else {
                $type = explode('_', $activity)[0];
                $table = ['deal' => 'crm_deals', 'lead' => 'leads', 'showing' => 'bookings'][$type];
                $id = DB::table($table)->insertGetId(array_merge($old, [$type === 'showing' ? 'crm_client_id' : 'client_id' => $client->id],
                    in_array($activity, ['deal', 'lead']) ? ['updated_at' => now()] : []));
                if (str_ends_with($activity, '_audit')) {
                    DB::table('crm_audit_logs')->insert(['auditable_type' => $type === 'deal' ? \App\Models\Deal::class : \App\Models\Lead::class,
                        'auditable_id' => $id, 'event' => 'comment', 'created_at' => now(), 'updated_at' => now()]);
                } elseif (str_ends_with($activity, '_task')) {
                    DB::table('crm_tasks')->insert(['related_entity_type' => $type, 'related_entity_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
        Sanctum::actingAs($agent);
        $this->getJson('/api/client-catalog?list=unattended')->assertOk()->assertJsonPath('meta.total', 14)->assertJsonCount(14, 'data');
        $this->getJson('/api/client-catalog/counts')->assertOk()->assertJsonPath('lists.unattended', 14);
        foreach ($withActivity as $client) {
            $claimant = $this->createUser($agent->role, $branch, 'Claimant '.$client->id);
            Sanctum::actingAs($claimant);
            $this->postJson('/api/client-catalog/'.$client->id.'/claim')->assertOk()->assertJsonPath('data.phone', $client->phone);
        }
        $this->assertDatabaseCount('client_catalog_claims', 13);
        // A freshly updated client is still eligible when the claim is rechecked.
        Sanctum::actingAs($agent);
        DB::table('clients')->where('id', $available->id)->update(['updated_at' => now()]);
        $this->postJson('/api/client-catalog/'.$available->id.'/claim')->assertOk();
    }

    public function test_unattended_and_budget_clients_can_be_claimed_on_the_same_day(): void
    {
        [$agent, $branch] = $this->prepareAgentContext();
        $other = Branch::create(['name' => 'Other branch']);
        $owner = $this->createUser($agent->role, $other, 'Other agent');
        $client = $this->createClient($other, $owner, 'Claim without need');
        $budget = $this->createClient($branch, $owner, 'Budget');
        $this->catalogNeed($budget, 1200000);
        Sanctum::actingAs($agent);
        $this->postJson('/api/client-catalog/'.$client->id.'/claim')->assertOk()->assertJsonPath('data.phone', $client->phone)->assertJsonCount(0, 'data.needs');
        $this->postJson('/api/client-catalog/'.$client->id.'/claim')->assertOk();
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'responsible_agent_id' => $agent->id, 'branch_id' => $branch->id, 'created_by' => $owner->id]);
        $this->getJson('/api/client-catalog/counts')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('lists.unattended', 0);
        $this->getJson('/api/client-catalog/mine')->assertOk()->assertJsonPath('data.0.phone', $client->phone);
        $this->postJson('/api/client-catalog/'.$budget->id.'/claim')->assertOk();
        $this->assertDatabaseCount('client_catalog_claims', 2);
        Sanctum::actingAs($owner);
        $this->postJson('/api/client-catalog/'.$client->id.'/claim')->assertConflict();
    }

    public function test_agents_and_interns_can_claim_new_clients_from_any_branch(): void
    {
        [$owner, $sourceBranch] = $this->prepareAgentContext();
        $targetBranch = Branch::create(['name' => 'Receiving branch']);
        foreach (['agent', 'intern'] as $slug) {
            $role = $slug === 'agent' ? $owner->role : Role::create(['name' => 'Intern', 'slug' => 'intern']);
            $claimant = $this->createUser($role, $targetBranch, $slug);
            $client = $this->createClient($sourceBranch, $owner, 'New buyer '.$slug);
            Sanctum::actingAs($claimant);
            $this->getJson('/api/client-catalog?list=unattended')->assertOk()->assertJsonPath('data.0.id', $client->id);
            $this->postJson('/api/client-catalog/'.$client->id.'/claim')->assertOk()->assertJsonPath('data.phone', $client->phone);
            $this->assertDatabaseHas('clients', ['id' => $client->id, 'responsible_agent_id' => $claimant->id, 'branch_id' => $targetBranch->id]);
            $this->getJson('/api/client-catalog/claim-status')->assertOk()->assertJsonPath('used_today', true)->assertJsonPath('can_claim', true);
        }
    }

    public function test_unattended_list_requires_its_own_permission_and_rechecks_added_needs(): void
    {
        [$agent, $branch] = $this->prepareAgentContext();
        $client = $this->unattendedClient($branch, $agent, 'Unattended');
        $role = $agent->role;
        $role->forceFill(['permissions' => ['clients.catalog.view', 'clients.catalog.list.agents', 'clients.catalog.claim']])->save();
        Sanctum::actingAs($agent->fresh());
        $this->getJson('/api/client-catalog?list=unattended')->assertUnprocessable();
        $this->postJson('/api/client-catalog/'.$client->id.'/claim')->assertConflict();
        $role->forceFill(['permissions' => ['clients.catalog.view', 'clients.catalog.list.unattended', 'clients.catalog.claim']])->save();
        Sanctum::actingAs($agent->fresh());
        $this->getJson('/api/client-catalog')->assertOk()->assertJsonPath('meta.list', 'unattended')->assertJsonPath('data.0.id', $client->id);
        $this->getJson('/api/client-catalog/counts')->assertOk()->assertExactJson(['total' => 1, 'lists' => ['unattended' => 1]]);
        $this->catalogNeed($client, 100);
        $this->postJson('/api/client-catalog/'.$client->id.'/claim')->assertConflict();
        $this->assertDatabaseCount('client_catalog_claims', 0);
    }

    public function test_daily_limit_migration_preserves_history_and_refuses_a_lossy_rollback(): void
    {
        $migration = require database_path('migrations/2026_11_10_100000_remove_client_catalog_daily_limit.php');
        $migration->down();
        $row = ['client_id' => 1, 'user_id' => 7, 'claimed_on' => '2026-11-10', 'created_at' => now()];
        DB::table('client_catalog_claims')->insert($row);
        $migration->up();
        $this->assertDatabaseHas('client_catalog_claims', $row);
        DB::table('client_catalog_claims')->insert(array_replace($row, ['client_id' => 2]));
        try {
            $migration->down();
            $this->fail('Rollback must not discard existing assignments.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Cannot restore the daily limit', $exception->getMessage());
        }
        DB::table('client_catalog_claims')->insert(array_replace($row, ['client_id' => 3]));
        $this->assertDatabaseCount('client_catalog_claims', 3);
    }

    public function test_catalog_need_creation_claims_and_saves_atomically_and_rejects_retries(): void
    {
        [$owner, $sourceBranch] = $this->prepareAgentContext();
        $branch = Branch::create(['name' => 'Destination']);
        $agent = $this->createUser($owner->role, $branch, 'New responsible');
        $client = $this->createClient($sourceBranch, $owner, 'Contact before claim');
        $this->mock(\App\Services\Crm\ClientNeedDealService::class, fn ($mock) => $mock->shouldReceive('ensureForNeed')->once()->andReturn(new \App\Models\Deal));
        Sanctum::actingAs($agent);
        $this->getJson('/api/client-catalog?list=unattended')->assertOk()->assertJsonPath('data.0.phone', $client->phone);
        $this->getJson('/api/clients/'.$client->id)->assertForbidden();
        $payload = ['type_id' => 1, 'status_id' => 1, 'comment' => 'Нужна двухкомнатная квартира', 'responsible_agent_id' => $owner->id];
        $response = $this->postJson('/api/client-catalog/'.$client->id.'/needs', $payload)->assertCreated()
            ->assertJsonPath('client_id', $client->id)->assertJsonPath('responsible_agent_id', $agent->id)->assertJsonPath('created_by', $agent->id);
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'responsible_agent_id' => $agent->id, 'branch_id' => $branch->id]);
        $this->assertDatabaseHas('client_catalog_claims', ['client_id' => $client->id, 'user_id' => $agent->id]);
        $this->getJson('/api/client-catalog?list=unattended')->assertJsonCount(0, 'data');
        $this->getJson('/api/client-catalog/mine')->assertOk()->assertJsonPath('data.0.needs.0.id', $response->json('id'));
        $this->getJson('/api/clients/'.$client->id)->assertOk();
        $this->postJson('/api/client-catalog/'.$client->id.'/needs', $payload)->assertConflict();
        Sanctum::actingAs($owner);
        $this->postJson('/api/client-catalog/'.$client->id.'/needs', $payload)->assertConflict();
        $this->assertDatabaseCount('client_needs', 1);
        $this->assertDatabaseCount('client_catalog_claims', 1);
    }

    public function test_invalid_catalog_need_does_not_transfer_or_consume_client(): void
    {
        [$owner, $branch] = $this->prepareAgentContext();
        $agent = $this->createUser($owner->role, $branch, 'Invalid payload actor');
        $client = $this->createClient($branch, $owner, 'No partial changes');
        Sanctum::actingAs($agent);
        $this->postJson('/api/client-catalog/'.$client->id.'/needs', [])->assertUnprocessable();
        $this->assertDatabaseCount('client_needs', 0);
        $this->assertDatabaseCount('client_catalog_claims', 0);
        $this->assertDatabaseCount('crm_audit_logs', 0);
        $this->assertEquals($owner->id, $client->fresh()->responsible_agent_id);
        $this->getJson('/api/client-catalog?list=unattended')->assertJsonPath('data.0.id', $client->id);
    }

    public function test_catalog_need_creation_rechecks_permissions_and_new_needs(): void
    {
        [$agent, $branch] = $this->prepareAgentContext();
        $client = $this->createClient($branch, $agent, 'Access checks');
        $base = ['clients.catalog.view', 'clients.catalog.list.unattended', 'clients.catalog.claim', 'client_needs.create'];
        foreach ($base as $revoked) {
            $agent->role->forceFill(['permissions' => array_values(array_diff($base, [$revoked]))])->save();
            Sanctum::actingAs($agent->fresh());
            $this->postJson('/api/client-catalog/'.$client->id.'/needs', ['type_id' => 1])->assertForbidden();
        }
        $agent->role->forceFill(['permissions' => $base])->save();
        Sanctum::actingAs($agent->fresh());
        $this->catalogNeed($client, 500000);
        $this->postJson('/api/client-catalog/'.$client->id.'/needs', ['type_id' => 1])->assertConflict();
        $this->assertDatabaseCount('client_catalog_claims', 0);
    }

    public function test_catalog_need_deal_failure_rolls_back_need_and_assignment(): void
    {
        [$owner, $branch] = $this->prepareAgentContext();
        $agent = $this->createUser($owner->role, $branch, 'Failed deal actor');
        $client = $this->createClient($branch, $owner, 'No partial need');
        $this->mock(\App\Services\Crm\ClientNeedDealService::class, fn ($mock) => $mock->shouldReceive('ensureForNeed')->once()->andThrow(new \RuntimeException('Deal failure')));
        Sanctum::actingAs($agent);
        $this->postJson('/api/client-catalog/'.$client->id.'/needs', ['type_id' => 1, 'status_id' => 1])->assertStatus(500);
        $this->assertDatabaseCount('client_needs', 0);
        $this->assertDatabaseCount('client_catalog_claims', 0);
        $this->assertEquals($owner->id, $client->fresh()->responsible_agent_id);
    }

    private function unattendedClient(Branch $branch, User $agent, string $name): Client
    {
        $client = $this->createClient($branch, $agent, $name);
        DB::table('clients')->where('id', $client->id)->update(['created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30)]);
        return $client;
    }

    private function catalogNeed(Client $client, int $amount, array $attributes = []): void
    {
        DB::table('client_needs')->insert(array_merge([
            'client_id' => $client->id, 'type_id' => 1, 'status_id' => 1,
            'has_cash_on_hand' => true, 'cash_on_hand_amount' => $amount,
            'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    private function assertClientIds(\Illuminate\Testing\TestResponse $response, array $expectedIds): void
    {
        $response->assertOk()->assertJsonPath('total', count($expectedIds))->assertJsonCount(count($expectedIds), 'data');
        // Compare only client IDs, never IDs of nested branch/source/type records.
        $this->assertEqualsCanonicalizing($expectedIds, array_column($response->json('data'), 'id'));
    }

    private function prepareAgentContext(): array
    {
        Setting::create([
            'key' => ClientAccess::VISIBILITY_SETTING_KEY,
            'value' => ClientAccess::VISIBILITY_ALL_BRANCH,
        ]);

        $branch = Branch::create(['name' => 'Main branch']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = $this->createUser($agentRole, $branch, 'Agent A');

        return [$agent, $branch];
    }

    private function createUser(Role $role, Branch $branch, string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
            'phone' => (string) $this->phoneCounter++,
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'status' => 'active',
            'auth_method' => 'password',
        ]);
    }

    private function createClient(Branch $branch, User $agent, string $name): Client
    {
        return Client::create([
            'full_name' => $name,
            'phone' => (string) $this->phoneCounter++,
            'phone_normalized' => (string) $this->phoneCounter,
            'branch_id' => $branch->id,
            'created_by' => $agent->id,
            'responsible_agent_id' => $agent->id,
            'client_type_id' => 1,
            'contact_kind' => Client::CONTACT_KIND_BUYER,
            'status' => 'active',
        ]);
    }
}

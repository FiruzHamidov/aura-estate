<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicTeamHallOfFameTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

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
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->enum('auth_method', ['password', 'sms'])->default('password');
            $table->string('photo')->nullable();
            $table->rememberToken()->nullable();
            $table->timestamps();
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->string('moderation_status')->nullable();
            $table->decimal('actual_sale_price', 15, 2)->nullable();
            $table->string('actual_sale_currency', 3)->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('crm_client_id')->nullable();
            $table->timestamp('start_time')->nullable();
            $table->timestamp('end_time')->nullable();
            $table->text('note')->nullable();
            $table->string('status')->nullable();
            $table->string('client_name')->nullable();
            $table->string('client_phone')->nullable();
            $table->timestamps();
        });

        Schema::create('property_agent_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('agent_id');
            $table->string('role')->default('main');
            $table->decimal('agent_commission_amount', 15, 2)->nullable();
            $table->string('agent_commission_currency', 3)->default('TJS');
            $table->timestamp('agent_paid_at')->nullable();
            $table->timestamps();
        });
    }

    private function seedPreviewTeam(bool $withActivity = true): array
    {
        $this->travelTo(now()->setDate(2026, 11, 18)->startOfDay());
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agents = [];
        for ($index = 0; $index < 15; $index++) {
            $agents[] = User::create(['name' => 'Expert '.$index, 'phone' => '902090'.sprintf('%03d', $index),
                'role_id' => $role->id, 'branch_id' => $index % 2 + 1, 'status' => 'active']);
        }
        if ($withActivity) {
            for ($index = 0; $index < 3; $index++) {
                for ($count = 0; $count < 3 - $index; $count++) {
                    DB::table('properties')->insert(['agent_id' => $agents[$index]->id, 'created_by' => $agents[$index]->id,
                        'moderation_status' => 'sold', 'sold_at' => now(), 'created_at' => now()->subMonth(), 'updated_at' => now()]);
                    DB::table('bookings')->insert(['agent_id' => $agents[$index + 3]->id, 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('properties')->insert(['created_by' => $agents[$index + 6]->id,
                        'moderation_status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }

        return $agents;
    }

    public function test_guests_and_clients_get_nine_current_nominees_and_one_daily_extra(): void
    {
        $agents = $this->seedPreviewTeam();
        $response = $this->getJson('/api/public/team')->assertOk()->assertJsonCount(10);
        $ids = array_column($response->json(), 'id');
        $this->assertSame(array_map(fn ($agent) => $agent->id, array_slice($agents, 0, 9)), array_slice($ids, 0, 9));
        $this->assertCount(10, array_unique($ids));
        $this->getJson('/api/public/team?limit=100&status=all&date_from=2000-01-01')->assertExactJson($response->json());

        $role = Role::create(['name' => 'Client', 'slug' => 'client']);
        $client = User::create(['name' => 'Client', 'phone' => '902091001', 'role_id' => $role->id, 'status' => 'active']);
        Sanctum::actingAs($client);
        $this->getJson('/api/public/team')->assertOk()->assertExactJson($response->json());
        $this->travel(1)->days();
        $next = array_column($this->getJson('/api/public/team')->assertOk()->assertJsonCount(10)->json(), 'id');
        $this->assertSame(array_slice($ids, 0, 9), array_slice($next, 0, 9));
        $this->assertNotSame($ids[9], $next[9]);
    }

    public function test_every_active_non_client_role_keeps_the_full_team_and_order(): void
    {
        $agents = $this->seedPreviewTeam();
        $mopRole = Role::create(['name' => 'MOP', 'slug' => 'mop']);
        $mop = User::create(['name' => 'Public MOP', 'phone' => '902091002', 'role_id' => $mopRole->id, 'status' => 'active']);
        $agents[14]->update(['status' => 'inactive']);
        foreach (['admin', 'security', 'external_agent', 'custom_internal'] as $index => $slug) {
            $role = Role::create(['name' => $slug, 'slug' => $slug]);
            $viewer = User::create(['name' => 'Viewer', 'phone' => '90209200'.$index, 'role_id' => $role->id, 'status' => 'active']);
            Sanctum::actingAs($viewer);
            $rows = $this->getJson('/api/public/team')->assertOk()->assertJsonCount(15)
                ->assertJsonPath('0.id', $mop->id)->assertJsonMissing(['id' => $agents[14]->id])->json();
            $this->assertSame(array_merge([$mop->id], array_map(fn ($agent) => $agent->id, array_slice($agents, 0, 14))), array_column($rows, 'id'));
        }
    }

    private function createTokensTable(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id(); $table->morphs('tokenable'); $table->string('name');
            $table->string('token', 64)->unique(); $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable(); $table->timestamp('expires_at')->nullable(); $table->timestamps();
        });
    }

    public function test_inactive_viewer_and_invalid_bearer_token_only_get_the_public_preview(): void
    {
        $agents = $this->seedPreviewTeam();
        $this->createTokensTable();
        $this->withToken('999|invalid-token')->getJson('/api/public/team')->assertOk()->assertJsonCount(10);
        $agents[14]->update(['status' => 'inactive']);
        Sanctum::actingAs($agents[14]);
        $this->getJson('/api/public/team')->assertOk()->assertJsonCount(10)->assertJsonMissing(['id' => $agents[14]->id]);
    }

    public function test_real_bearer_authentication_uses_the_current_role_and_token_expiry(): void
    {
        $agents = $this->seedPreviewTeam();
        $this->createTokensTable();
        $token = $agents[14]->createToken('team-preview')->plainTextToken;
        $response = $this->withToken($token)->getJson('/api/public/team')->assertOk()->assertJsonCount(15);
        $this->assertContains('Authorization', array_map('trim', explode(',', $response->headers->get('Vary'))));

        $clientRole = Role::create(['name' => 'Client', 'slug' => 'client']);
        $agents[14]->update(['role_id' => $clientRole->id]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/public/team')->assertOk()->assertJsonCount(10);

        $agents[14]->update(['role_id' => $agents[0]->role_id]);
        DB::table('personal_access_tokens')->update(['expires_at' => now()->subMinute()]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/public/team')->assertOk()->assertJsonCount(10);
    }

    public function test_public_preview_deduplicates_nominations_and_uses_next_ranked_people(): void
    {
        $agents = $this->seedPreviewTeam(false);
        for ($index = 0; $index < 12; $index++) {
            for ($count = 0; $count < 12 - $index; $count++) {
                DB::table('properties')->insert(['agent_id' => $agents[$index]->id, 'created_by' => $agents[$index]->id,
                    'moderation_status' => 'sold', 'sold_at' => now(), 'created_at' => now()->subMonth(), 'updated_at' => now()]);
                DB::table('bookings')->insert(['agent_id' => $agents[$index]->id, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('properties')->insert(['created_by' => $agents[$index]->id,
                    'moderation_status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $ids = array_column($this->getJson('/api/public/team')->assertOk()->assertJsonCount(10)->json(), 'id');
        $this->assertCount(10, array_unique($ids));
        $this->assertSame(array_map(fn ($agent) => $agent->id, array_slice($agents, 0, 9)), array_slice($ids, 0, 9));
    }

    public function test_preview_tracks_new_month_and_fills_missing_nominees_without_duplicates(): void
    {
        $agents = $this->seedPreviewTeam();
        $this->travelTo(now()->addMonth()->startOfMonth());
        $this->getJson('/api/public/team/hall-of-fame')->assertJsonCount(0, 'nominations.best_sales_by_count.leaders');
        $ids = array_column($this->getJson('/api/public/team')->assertOk()->assertJsonCount(10)->json(), 'id');
        $this->assertCount(10, array_unique($ids));
        DB::table('bookings')->insert(['agent_id' => $agents[14]->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson('/api/public/team')->assertOk()->assertJsonCount(10)->assertJsonPath('0.id', $agents[14]->id);
    }

    public function test_custom_public_role_can_enter_and_leave_public_nominations(): void
    {
        Schema::table('roles', fn (Blueprint $t) => $t->json('permissions')->nullable());
        $role = Role::create(['name' => 'Custom specialist', 'slug' => 'custom_public']);
        $role->forceFill(['permissions' => ['public_team.subject.profile']])->save();
        $employee = User::create(['name' => 'Specialist', 'phone' => '900091010', 'role_id' => $role->id, 'status' => 'active']);
        DB::table('properties')->insert(['created_by' => $employee->id, 'agent_id' => $employee->id, 'moderation_status' => 'approved', 'created_at' => '2026-04-02 10:00:00', 'updated_at' => '2026-04-02 10:00:00']);
        $path = '/api/public/team/hall-of-fame?date_from=2026-04-01&date_to=2026-04-30';
        $this->getJson($path)->assertOk()->assertJsonPath('nominations.most_properties_added.winner.agent.id', $employee->id);
        $role->forceFill(['permissions' => []])->save();
        $this->getJson($path)->assertOk()->assertJsonCount(0, 'nominations.most_properties_added.leaders');
        $this->assertDatabaseCount('properties', 1);
    }

    public function test_public_team_hall_of_fame_returns_public_nominations(): void
    {
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $managerRole = Role::create(['name' => 'Manager', 'slug' => 'manager']);

        $agentOne = User::create([
            'name' => 'Агент Один',
            'phone' => '900000001',
            'role_id' => $agentRole->id,
            'status' => 'active',
            'photo' => 'users/1.jpg',
        ]);

        $agentTwo = User::create([
            'name' => 'Агент Два',
            'phone' => '900000002',
            'role_id' => $agentRole->id,
            'status' => 'active',
            'photo' => 'users/2.jpg',
        ]);

        $inactiveAgent = User::create([
            'name' => 'Неактивный агент',
            'phone' => '900000003',
            'role_id' => $agentRole->id,
            'status' => 'inactive',
            'photo' => 'users/3.jpg',
        ]);

        $manager = User::create([
            'name' => 'Менеджер',
            'phone' => '900000004',
            'role_id' => $managerRole->id,
            'status' => 'active',
        ]);

        $propertyOneId = DB::table('properties')->insertGetId([
            'title' => 'P1',
            'created_by' => $agentOne->id,
            'moderation_status' => 'sold',
            'actual_sale_price' => 100000,
            'actual_sale_currency' => 'TJS',
            'sold_at' => '2026-04-10 10:00:00',
            'created_at' => '2026-04-01 10:00:00',
            'updated_at' => '2026-04-10 10:00:00',
        ]);

        $propertyTwoId = DB::table('properties')->insertGetId([
            'title' => 'P2',
            'created_by' => $agentOne->id,
            'moderation_status' => 'sold',
            'actual_sale_price' => 150000,
            'actual_sale_currency' => 'TJS',
            'sold_at' => '2026-04-12 10:00:00',
            'created_at' => '2026-04-02 10:00:00',
            'updated_at' => '2026-04-12 10:00:00',
        ]);

        $propertyThreeId = DB::table('properties')->insertGetId([
            'title' => 'P3',
            'created_by' => $agentTwo->id,
            'moderation_status' => 'sold',
            'actual_sale_price' => 500000,
            'actual_sale_currency' => 'TJS',
            'sold_at' => '2026-04-15 10:00:00',
            'created_at' => '2026-04-03 10:00:00',
            'updated_at' => '2026-04-15 10:00:00',
        ]);

        $propertyRentedId = DB::table('properties')->insertGetId([
            'title' => 'P9',
            'created_by' => $agentOne->id,
            'moderation_status' => 'rented',
            'actual_sale_price' => 2000,
            'actual_sale_currency' => 'TJS',
            'sold_at' => '2026-04-16 10:00:00',
            'created_at' => '2026-04-09 10:00:00',
            'updated_at' => '2026-04-16 10:00:00',
        ]);

        DB::table('properties')->insert([
            [
                'title' => 'P4',
                'created_by' => $agentOne->id,
                'moderation_status' => 'approved',
                'actual_sale_price' => null,
                'actual_sale_currency' => null,
                'sold_at' => null,
                'created_at' => '2026-04-04 10:00:00',
                'updated_at' => '2026-04-04 10:00:00',
            ],
            [
                'title' => 'P5',
                'created_by' => $agentOne->id,
                'moderation_status' => 'approved',
                'actual_sale_price' => null,
                'actual_sale_currency' => null,
                'sold_at' => null,
                'created_at' => '2026-04-05 10:00:00',
                'updated_at' => '2026-04-05 10:00:00',
            ],
            [
                'title' => 'P6',
                'created_by' => $agentTwo->id,
                'moderation_status' => 'approved',
                'actual_sale_price' => null,
                'actual_sale_currency' => null,
                'sold_at' => null,
                'created_at' => '2026-04-06 10:00:00',
                'updated_at' => '2026-04-06 10:00:00',
            ],
            [
                'title' => 'P6 deleted',
                'created_by' => $agentTwo->id,
                'moderation_status' => 'deleted',
                'actual_sale_price' => null,
                'actual_sale_currency' => null,
                'sold_at' => null,
                'created_at' => '2026-04-06 11:00:00',
                'updated_at' => '2026-04-06 11:00:00',
            ],
            [
                'title' => 'P7',
                'created_by' => $inactiveAgent->id,
                'moderation_status' => 'sold',
                'actual_sale_price' => 999999,
                'actual_sale_currency' => 'TJS',
                'sold_at' => '2026-04-18 10:00:00',
                'created_at' => '2026-04-07 10:00:00',
                'updated_at' => '2026-04-18 10:00:00',
            ],
            [
                'title' => 'P8',
                'created_by' => $manager->id,
                'moderation_status' => 'approved',
                'actual_sale_price' => null,
                'actual_sale_currency' => null,
                'sold_at' => null,
                'created_at' => '2026-04-08 10:00:00',
                'updated_at' => '2026-04-08 10:00:00',
            ],
        ]);

        DB::table('property_agent_sales')->insert([
            [
                'property_id' => $propertyOneId,
                'agent_id' => $agentOne->id,
                'created_at' => '2026-04-10 10:00:00',
                'updated_at' => '2026-04-10 10:00:00',
            ],
            [
                'property_id' => $propertyTwoId,
                'agent_id' => $agentOne->id,
                'created_at' => '2026-04-12 10:00:00',
                'updated_at' => '2026-04-12 10:00:00',
            ],
            [
                'property_id' => $propertyThreeId,
                'agent_id' => $agentTwo->id,
                'created_at' => '2026-04-15 10:00:00',
                'updated_at' => '2026-04-15 10:00:00',
            ],
            [
                'property_id' => $propertyRentedId,
                'agent_id' => $agentOne->id,
                'created_at' => '2026-04-16 10:00:00',
                'updated_at' => '2026-04-16 10:00:00',
            ],
        ]);

        DB::table('bookings')->insert([
            [
                'property_id' => $propertyOneId,
                'agent_id' => $agentOne->id,
                'created_at' => '2026-04-09 09:00:00',
                'updated_at' => '2026-04-09 09:00:00',
            ],
            [
                'property_id' => $propertyTwoId,
                'agent_id' => $agentTwo->id,
                'created_at' => '2026-04-11 09:00:00',
                'updated_at' => '2026-04-11 09:00:00',
            ],
            [
                'property_id' => $propertyTwoId,
                'agent_id' => $agentTwo->id,
                'created_at' => '2026-04-12 09:00:00',
                'updated_at' => '2026-04-12 09:00:00',
            ],
            [
                'property_id' => $propertyThreeId,
                'agent_id' => $agentTwo->id,
                'created_at' => '2026-04-13 09:00:00',
                'updated_at' => '2026-04-13 09:00:00',
            ],
            [
                'property_id' => $propertyThreeId,
                'agent_id' => $inactiveAgent->id,
                'created_at' => '2026-04-14 09:00:00',
                'updated_at' => '2026-04-14 09:00:00',
            ],
        ]);

        $response = $this->getJson('/api/public/team/hall-of-fame?date_from=2026-04-01&date_to=2026-04-30');

        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertJsonPath('nominations.best_sales_by_count.winner.agent.id', $agentOne->id);
        $response->assertJsonPath('nominations.best_sales_by_count.winner.sold_count', 3);
        $response->assertJsonPath('nominations.best_sales_by_count.title', 'Лучший продажник');
        $response->assertJsonMissingPath('nominations.best_sales_by_amount');
        $response->assertJsonPath('nominations.most_showings_added.winner.agent.id', $agentTwo->id);
        $response->assertJsonPath('nominations.most_showings_added.winner.shows_count', 3);
        $response->assertJsonPath('nominations.most_properties_added.winner.agent.id', $agentOne->id);
        $response->assertJsonPath('nominations.most_properties_added.winner.added_count', 5);
        $response->assertJsonPath('nominations.most_properties_added.leaders.1.agent.id', $agentTwo->id);
        $response->assertJsonPath('nominations.most_properties_added.leaders.1.added_count', 2);

        $leaderIds = collect($response->json('nominations.best_sales_by_count.leaders'))
            ->pluck('agent.id')
            ->all();

        $this->assertNotContains($inactiveAgent->id, $leaderIds);
        $this->assertNotContains($manager->id, $leaderIds);
    }

    public function test_best_sales_by_count_uses_manager_efficiency_tie_breakers(): void
    {
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $agentOne = User::create([
            'name' => 'Агент Один',
            'phone' => '911000001',
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);

        $agentTwo = User::create([
            'name' => 'Агент Два',
            'phone' => '911000002',
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);

        $soldOneId = DB::table('properties')->insertGetId([
            'title' => 'Sold A1',
            'created_by' => $agentOne->id,
            'moderation_status' => 'sold',
            'sold_at' => '2026-04-10 10:00:00',
            'created_at' => '2026-04-01 10:00:00',
            'updated_at' => '2026-04-10 10:00:00',
        ]);

        $soldTwoId = DB::table('properties')->insertGetId([
            'title' => 'Sold A2',
            'created_by' => $agentTwo->id,
            'moderation_status' => 'sold',
            'sold_at' => '2026-04-11 10:00:00',
            'created_at' => '2026-04-01 11:00:00',
            'updated_at' => '2026-04-11 10:00:00',
        ]);

        DB::table('properties')->insert([
            [
                'title' => 'A1 approved',
                'created_by' => $agentOne->id,
                'moderation_status' => 'approved',
                'sold_at' => null,
                'created_at' => '2026-04-03 10:00:00',
                'updated_at' => '2026-04-03 10:00:00',
            ],
            [
                'title' => 'A2 approved #1',
                'created_by' => $agentTwo->id,
                'moderation_status' => 'approved',
                'sold_at' => null,
                'created_at' => '2026-04-04 10:00:00',
                'updated_at' => '2026-04-04 10:00:00',
            ],
            [
                'title' => 'A2 approved #2',
                'created_by' => $agentTwo->id,
                'moderation_status' => 'approved',
                'sold_at' => null,
                'created_at' => '2026-04-05 10:00:00',
                'updated_at' => '2026-04-05 10:00:00',
            ],
        ]);

        DB::table('property_agent_sales')->insert([
            [
                'property_id' => $soldOneId,
                'agent_id' => $agentOne->id,
                'created_at' => '2026-04-10 10:00:00',
                'updated_at' => '2026-04-10 10:00:00',
            ],
            [
                'property_id' => $soldTwoId,
                'agent_id' => $agentTwo->id,
                'created_at' => '2026-04-11 10:00:00',
                'updated_at' => '2026-04-11 10:00:00',
            ],
        ]);

        $response = $this->getJson('/api/public/team/hall-of-fame?date_from=2026-04-01&date_to=2026-04-30');
        $response->assertOk();

        // sold_count одинаковый, поэтому победитель определяется по approved, как в отчете эффективности.
        $response->assertJsonPath('nominations.best_sales_by_count.winner.agent.id', $agentTwo->id);
        $response->assertJsonPath('nominations.best_sales_by_count.winner.sold_count', 1);
    }
}

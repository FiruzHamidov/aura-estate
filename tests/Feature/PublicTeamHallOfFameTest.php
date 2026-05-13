<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
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
}

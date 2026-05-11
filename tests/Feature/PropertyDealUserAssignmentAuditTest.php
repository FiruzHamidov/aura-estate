<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Property;
use App\Models\PropertyStatus;
use App\Models\PropertyType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PropertyDealUserAssignmentAuditTest extends TestCase
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

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

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

        Schema::create('client_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_business')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('phone')->nullable();
            $table->string('phone_normalized')->nullable();
            $table->unsignedBigInteger('client_type_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('property_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('property_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('building_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->timestamps();
        });

        Schema::create('crm_deal_pipelines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->string('code')->nullable();
            $table->string('type')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->unsignedBigInteger('type_id')->nullable();
            $table->unsignedBigInteger('status_id')->nullable();
            $table->decimal('price', 15, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('offer_type')->nullable();
            $table->string('moderation_status')->default('approved');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('owner_client_id')->nullable();
            $table->unsignedBigInteger('buyer_client_id')->nullable();
            $table->string('owner_name')->nullable();
            $table->string('owner_phone')->nullable();
            $table->string('buyer_full_name')->nullable();
            $table->string('buyer_phone')->nullable();
            $table->decimal('deposit_amount', 15, 2)->nullable();
            $table->string('deposit_currency', 3)->nullable();
            $table->timestamp('deposit_received_at')->nullable();
            $table->timestamp('deposit_taken_at')->nullable();
            $table->unsignedBigInteger('deposit_user_id')->nullable();
            $table->decimal('company_expected_income', 15, 2)->nullable();
            $table->string('company_expected_income_currency', 3)->nullable();
            $table->timestamp('planned_contract_signed_at')->nullable();
            $table->decimal('actual_sale_price', 15, 2)->nullable();
            $table->string('actual_sale_currency', 3)->nullable();
            $table->decimal('company_commission_amount', 15, 2)->nullable();
            $table->string('company_commission_currency', 3)->nullable();
            $table->string('money_holder')->nullable();
            $table->timestamp('money_received_at')->nullable();
            $table->timestamp('contract_signed_at')->nullable();
            $table->unsignedBigInteger('sale_user_id')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->string('listing_type')->nullable();
            $table->text('status_comment')->nullable();
            $table->timestamps();
        });

        Schema::create('property_agent_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('agent_id');
            $table->string('role')->nullable();
            $table->decimal('agent_commission_amount', 15, 2)->nullable();
            $table->string('agent_commission_currency', 3)->nullable();
            $table->timestamp('agent_paid_at')->nullable();
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
            $table->text('comment')->nullable();
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

    public function test_audit_deposit_and_sale_user_assignment_endpoints(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $ropRole = Role::create(['name' => 'ROP', 'slug' => 'rop']);

        $owner = $this->makeUser($agentRole, $branchA->id, 'owner');
        $sameBranchAgent45 = $this->makeUser($agentRole, $branchA->id, 'agent45');
        $sameBranchAgent52 = $this->makeUser($agentRole, $branchA->id, 'agent52');
        $sameBranchRop = $this->makeUser($ropRole, $branchA->id, 'rop');
        $otherBranchAgent = $this->makeUser($agentRole, $branchB->id, 'other');

        $type = PropertyType::create(['name' => 'Apartment']);
        $status = PropertyStatus::create(['name' => 'New']);

        Property::unsetEventDispatcher();

        $property = Property::create([
            'title' => 'Audit Property',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 100000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $owner->id,
            'agent_id' => $owner->id,
        ]);

        $cases = [];

        Sanctum::actingAs($owner);

        $cases[] = $this->runCase('A1_patch_deposit_explicit_deposit_user_id', 'patchJson', "/api/properties/{$property->id}/moderation-listing", [
            'moderation_status' => 'deposit',
            'deposit_user_id' => $sameBranchAgent45->id,
            'buyer_full_name' => 'Buyer One',
            'buyer_phone' => '900000111',
            'deposit_amount' => 1000,
            'deposit_currency' => 'USD',
            'deposit_received_at' => '2026-05-01',
            'planned_contract_signed_at' => '2026-05-15',
            'company_expected_income' => 200,
            'company_expected_income_currency' => 'USD',
            'money_holder' => 'company',
        ], $property->id);

        $cases[] = $this->runCase('A2_patch_deposit_without_deposit_user_id_autofill_current_user', 'patchJson', "/api/properties/{$property->id}/moderation-listing", [
            'moderation_status' => 'deposit',
            'buyer_full_name' => 'Buyer Two',
            'buyer_phone' => '900000112',
            'deposit_amount' => 1100,
            'deposit_currency' => 'USD',
            'deposit_received_at' => '2026-05-02',
            'planned_contract_signed_at' => '2026-05-16',
            'company_expected_income' => 210,
            'company_expected_income_currency' => 'USD',
            'money_holder' => 'company',
        ], $property->id);

        $cases[] = $this->runCase('B1_post_sold_explicit_sale_user_id', 'postJson', "/api/properties/{$property->id}/deal", [
            'moderation_status' => 'sold',
            'sale_user_id' => $sameBranchAgent52->id,
            'actual_sale_price' => 120000,
            'actual_sale_currency' => 'USD',
            'company_commission_amount' => 3000,
            'company_commission_currency' => 'USD',
        ], $property->id);

        $cases[] = $this->runCase('B2_post_sold_without_sale_user_id_autofill_current_user', 'postJson', "/api/properties/{$property->id}/deal", [
            'moderation_status' => 'sold',
            'actual_sale_price' => 121000,
            'actual_sale_currency' => 'USD',
            'company_commission_amount' => 3050,
            'company_commission_currency' => 'USD',
        ], $property->id);

        Sanctum::actingAs($sameBranchRop);

        $cases[] = $this->runCase('C1_rop_patch_deposit_can_assign_other_agent_same_branch', 'patchJson', "/api/properties/{$property->id}/moderation-listing", [
            'moderation_status' => 'deposit',
            'deposit_user_id' => $sameBranchAgent45->id,
            'buyer_full_name' => 'Buyer Three',
            'buyer_phone' => '900000113',
            'deposit_amount' => 1300,
            'deposit_currency' => 'USD',
            'deposit_received_at' => '2026-05-03',
            'planned_contract_signed_at' => '2026-05-17',
            'company_expected_income' => 215,
            'company_expected_income_currency' => 'USD',
            'money_holder' => 'company',
        ], $property->id);

        $cases[] = $this->runCase('C2_rop_post_sold_can_assign_other_agent_same_branch', 'postJson', "/api/properties/{$property->id}/deal", [
            'moderation_status' => 'sold',
            'sale_user_id' => $sameBranchAgent52->id,
            'actual_sale_price' => 123000,
            'actual_sale_currency' => 'USD',
            'company_commission_amount' => 3100,
            'company_commission_currency' => 'USD',
        ], $property->id);

        $cases[] = $this->runCase('E1_patch_deposit_nonexistent_deposit_user_id', 'patchJson', "/api/properties/{$property->id}/moderation-listing", [
            'moderation_status' => 'deposit',
            'deposit_user_id' => 999999,
            'buyer_full_name' => 'Buyer Four',
            'buyer_phone' => '900000114',
            'deposit_amount' => 1500,
            'deposit_currency' => 'USD',
            'deposit_received_at' => '2026-05-04',
            'planned_contract_signed_at' => '2026-05-18',
            'company_expected_income' => 220,
            'company_expected_income_currency' => 'USD',
            'money_holder' => 'company',
        ], $property->id);

        $cases[] = $this->runCase('E2_post_sold_nonexistent_sale_user_id', 'postJson', "/api/properties/{$property->id}/deal", [
            'moderation_status' => 'sold',
            'sale_user_id' => 999998,
            'actual_sale_price' => 124000,
            'actual_sale_currency' => 'USD',
            'company_commission_amount' => 3150,
            'company_commission_currency' => 'USD',
        ], $property->id);

        $cases[] = $this->runCase('E3_rop_assign_out_of_scope_user_from_other_branch', 'patchJson', "/api/properties/{$property->id}/moderation-listing", [
            'moderation_status' => 'deposit',
            'deposit_user_id' => $otherBranchAgent->id,
            'buyer_full_name' => 'Buyer Five',
            'buyer_phone' => '900000115',
            'deposit_amount' => 1550,
            'deposit_currency' => 'USD',
            'deposit_received_at' => '2026-05-05',
            'planned_contract_signed_at' => '2026-05-19',
            'company_expected_income' => 230,
            'company_expected_income_currency' => 'USD',
            'money_holder' => 'company',
        ], $property->id);

        $reportPath = storage_path('app/property_deal_user_assignment_audit.json');
        File::ensureDirectoryExists(dirname($reportPath));
        File::put($reportPath, json_encode([
            'property_id' => $property->id,
            'users' => [
                'owner' => $owner->id,
                'same_branch_agent_45' => $sameBranchAgent45->id,
                'same_branch_agent_52' => $sameBranchAgent52->id,
                'rop' => $sameBranchRop->id,
                'other_branch_agent' => $otherBranchAgent->id,
            ],
            'cases' => $cases,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->assertTrue(true);
    }

    private function runCase(string $key, string $method, string $url, array $body, int $propertyId): array
    {
        $response = $this->{$method}($url, $body);
        $getResponse = $this->getJson("/api/properties/{$propertyId}");

        return [
            'case' => $key,
            'request' => [
                'method' => strtoupper(str_replace('Json', '', $method)),
                'url' => $url,
                'body' => $body,
            ],
            'response' => [
                'status' => $response->status(),
                'body' => $response->json(),
            ],
            'get_after' => [
                'status' => $getResponse->status(),
                'body' => $getResponse->json(),
            ],
        ];
    }

    private function makeUser(Role $role, int $branchId, string $suffix): User
    {
        static $i = 0;
        $i++;

        return User::create([
            'name' => "User {$suffix}",
            'phone' => '93000'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
            'email' => "{$suffix}{$i}@example.test",
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'branch_id' => $branchId,
            'status' => 'active',
            'auth_method' => 'password',
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDailyReportSubmitted;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ListingStatusReportTest extends TestCase
{
    private const URL = '/api/reports/properties/status-inventory';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureDailyReportSubmitted::class);
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug');
            $t->json('permissions')->nullable();
            $t->unsignedInteger('permissions_version')->default(0);
            $t->json('permission_expirations')->nullable();
            $t->timestamps();
        });
        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->boolean('is_hidden')->default(false);
        });
        Schema::create('branch_groups', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('branch_id');
            $t->boolean('is_archived')->default(false);
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('role_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('branch_group_id')->nullable();
            $t->string('status')->default('active');
            $t->softDeletes();
            $t->unsignedInteger('access_scope_version')->default(0);
            $t->timestamps();
        });
        Schema::create('rop_branch_groups', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('rop_id');
            $t->unsignedBigInteger('branch_group_id');
        });
        Schema::create('properties', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('branch_group_id')->nullable();
            $t->unsignedBigInteger('agent_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('moderation_status')->nullable();
        });
        Schema::create('role_permission_audits', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('role_id');
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('role_name');
            $t->json('before');
            $t->json('after');
            $t->unsignedInteger('version');
            $t->timestamp('created_at');
        });
        foreach (['admin', 'superadmin', 'branch_director', 'rop', 'agent'] as $index => $slug) {
            DB::table('roles')->insert(['id' => $index + 1, 'slug' => $slug, 'name' => $slug]);
        }
        foreach ([1 => 'Айни', 2 => '102', 3 => 'Скрытый', 4 => 'Пустой'] as $id => $name) {
            DB::table('branches')->insert(['id' => $id, 'name' => $name, 'is_hidden' => $id === 3]);
        }
        foreach ([10 => 1, 11 => 1, 12 => 1, 20 => 2, 30 => 3] as $id => $branch) {
            DB::table('branch_groups')->insert(['id' => $id, 'branch_id' => $branch, 'name' => 'Группа '.$id, 'is_archived' => $id === 11]);
        }
        foreach ([[101, 1, 1, null], [102, 2, 1, null], [111, 3, 1, null], [112, 3, null, null],
            [121, 4, 1, null], [122, 4, 1, null], [123, 4, 2, null], [124, 4, 1, null], [125, 4, 1, null],
            [201, 5, 1, 10], [202, 5, 2, 20], [203, 5, 1, null]] as [$id,$role,$branch,$group]) {
            DB::table('users')->insert(['id' => $id, 'role_id' => $role, 'branch_id' => $branch, 'branch_group_id' => $group,
                'name' => 'Сотрудник '.$id, 'status' => in_array($id, [125, 201]) ? 'inactive' : 'active']);
        }
        foreach ([[121, 10], [121, 11], [122, 12], [123, 20], [125, 11]] as [$rop,$group]) {
            DB::table('rop_branch_groups')->insert(['rop_id' => $rop, 'branch_group_id' => $group]);
        }
        foreach (['approved', 'pending', 'deposit', 'sold', 'sold_by_owner', 'rented', 'denied', 'rejected', 'draft', 'deleted', 'future_status'] as $status) {
            $this->property(1, 10, $status, 201, 202);
        }
        $this->property(1, 11, 'deleted');
        $this->property(1, 12, 'approved');
        $this->property(2, 20, 'sold');
        $this->property(3, 30, 'approved');
        $this->property(null, null, 'approved', 201);
        $this->property(1, null, 'draft', 203);
        $this->property(null, null, 'approved');
        $this->property(2, 10, 'approved');
    }

    private function property(?int $branch, ?int $group, string $status, ?int $agent = null, ?int $author = null): void
    {
        DB::table('properties')->insert(['branch_id' => $branch, 'branch_group_id' => $group, 'moderation_status' => $status, 'agent_id' => $agent, 'created_by' => $author]);
    }

    private function login(int $id): void
    {
        Sanctum::actingAs(User::findOrFail($id));
    }

    public function test_admin_counts_every_status_and_reconciles_all_dimensions_without_duplicate_rops(): void
    {
        foreach ([101, 102] as $id) {
            $this->login($id);
            $result = $this->getJson(self::URL.'?include_hidden=1')->assertOk()->assertJsonPath('totals.total', 19)
                ->assertJsonPath('totals.by_status.deleted', 2)->assertJsonPath('totals.by_status.unknown', 1)->json();
            foreach (['by_branch', 'by_group', 'by_rop'] as $dimension) {
                $this->assertSame(19, array_sum(array_column($result[$dimension], 'total')));
                foreach ($result['totals']['by_status'] as $status => $count) {
                    $this->assertSame($count, array_sum(array_map(fn ($r) => $r['by_status'][$status], $result[$dimension])));
                }
            }
            $this->assertSame(1, collect($result['by_rop'])->firstWhere('key', '121-125')['total']);
            $this->assertSame(0, collect($result['by_branch'])->firstWhere('key', '4')['total']);
        }
        $this->getJson(self::URL)->assertOk()->assertJsonPath('totals.total', 18)->assertJsonCount(3, 'options.branches');
    }

    public function test_director_counts_owned_branch_including_legacy_and_inactive_employee_records(): void
    {
        $this->login(111);
        $json = $this->getJson(self::URL)->assertOk()->assertJsonPath('totals.total', 15)->assertJsonCount(1, 'options.branches')->json();
        $this->assertNotContains(123, array_column($json['options']['rops'], 'id'));
        foreach (['branch_id=2', 'branch_group_id=20', 'rop_id=123', 'include_hidden=1'] as $filter) {
            $this->getJson(self::URL.'?'.$filter)->assertForbidden();
        }
        $this->login(112);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('totals.total', 0)->assertJsonCount(0, 'options.branches');
    }

    public function test_rop_is_strictly_scoped_to_assigned_records_even_if_global_report_permission_is_granted(): void
    {
        $defaults = app(\App\Services\Rbac\PermissionCatalog::class)->defaults('rop');
        DB::table('roles')->where('slug', 'rop')->update(['permissions' => json_encode([...$defaults, 'property_reports.scope.all'])]);
        $this->login(121);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('totals.total', 12)->assertJsonCount(2, 'options.groups')
            ->assertJsonCount(1, 'options.rops')->assertJsonPath('by_rop.0.key', '121');
        $this->getJson(self::URL.'?branch_group_id=11')->assertOk()->assertJsonPath('totals.total', 1);
        foreach (['branch_id=2', 'branch_group_id=12', 'rop_id=122', 'include_hidden=1'] as $filter) {
            $this->getJson(self::URL.'?'.$filter)->assertForbidden();
        }
        $this->login(124);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('totals.total', 0)->assertJsonCount(0, 'options.groups');
    }

    public function test_filters_narrow_counts_and_reject_invalid_combinations(): void
    {
        $this->login(101);
        $branch = $this->getJson(self::URL.'?branch_id=2')->assertOk()->assertJsonPath('totals.total', 2)->json();
        $this->assertSame('Группа 10', collect($branch['by_group'])->firstWhere('key', '10')['name']);
        $this->getJson(self::URL.'?rop_id=121')->assertOk()->assertJsonPath('totals.total', 14);
        $this->getJson(self::URL.'?branch_group_id=10')->assertOk()->assertJsonPath('totals.total', 13);
        foreach (['branch_id=1&branch_group_id=20', 'branch_id=2&rop_id=121', 'branch_group_id=12&rop_id=121', 'branch_id[]=1', 'rop_id=abc'] as $filter) {
            $this->getJson(self::URL.'?'.$filter)->assertUnprocessable();
        }
        $this->getJson(self::URL.'?rop_id=99999')->assertForbidden();
    }

    public function test_permission_revocation_and_missing_scope_deny_direct_api_access(): void
    {
        $this->login(203);
        $this->getJson(self::URL)->assertForbidden();
        foreach ([[], ['property_reports.view', 'property_reports.scope.all'], ['property_reports.view', 'property_reports.inventory.view']] as $permissions) {
            DB::table('roles')->where('id', 1)->update(['permissions' => json_encode($permissions)]);
            $this->login(101);
            $this->getJson(self::URL)->assertForbidden();
        }
    }

    public function test_migration_grants_only_existing_report_readers_and_is_idempotent(): void
    {
        DB::table('roles')->where('id', 1)->update(['permissions' => json_encode(['property_reports.view', 'property_reports.scope.all'])]);
        DB::table('roles')->where('id', 3)->update(['permissions' => '[]']);
        $migration = require database_path('migrations/2026_11_20_120000_enable_listing_status_report.php');
        $migration->up();
        $migration->up();
        $this->assertContains('property_reports.inventory.view', json_decode(DB::table('roles')->where('id', 1)->value('permissions'), true));
        $this->assertSame('[]',DB::table('roles')->where('id',3)->value('permissions'));
        $this->assertSame(1,DB::table('role_permission_audits')->count());
    }
}

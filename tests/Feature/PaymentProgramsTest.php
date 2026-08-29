<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDailyReportSubmitted;
use App\Http\Middleware\LogApiRequest;
use App\Models\NewBuilding;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ResidentialSchema;
use Tests\TestCase;

class PaymentProgramsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ResidentialSchema::create();
        (require database_path('migrations/2026_08_28_170000_create_payment_programs.php'))->up();
        $this->withoutMiddleware([EnsureDailyReportSubmitted::class, LogApiRequest::class]);
        Http::preventStrayRequests();
    }

    private function actor(string $slug = 'admin', ?int $branch = null): User
    {
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);

        return User::create(['name' => $slug, 'phone' => '+992'.random_int(100000000, 999999999), 'role_id' => $role->id, 'branch_id' => $branch]);
    }

    private function building(array $values = []): NewBuilding
    {
        return NewBuilding::create(['title' => 'Payment fixture', 'publication_status' => 'published', ...$values]);
    }

    private function terms(array $values = []): array
    {
        return ['title' => 'Тестовая программа, не предложение банка', 'type' => 'installment', 'calculation_method' => 'equal_installment', 'scope' => 'all', 'publication_status' => 'published',
            'period_months' => 1, 'term_min_months' => 3, 'term_max_months' => 24, 'min_down_percent' => '20.00', 'annual_rate' => '0.000', 'fees_verified' => true, 'upfront_fee_percent' => '0.00', 'upfront_fee_fixed' => '0.00',
            'source_url' => 'https://example.com/fixture-terms', 'confirmation_reference' => 'Internal fixture confirmation', 'conditions' => 'Только синтетическая программа для тестов. Не реальные банковские условия.', 'valid_from' => now()->subDay()->toDateString(), 'valid_until' => now()->addMonth()->toDateString(), 'data_verified_at' => now()->toIso8601String(), ...$values];
    }

    public function test_author_submits_and_moderator_confirms_with_required_evidence_and_version(): void
    {
        $agent = $this->actor('agent');
        Sanctum::actingAs($agent);
        $building = $this->building(['created_by' => $agent->id]);
        $path = '/api/admin/new-buildings/'.$building->id.'/payment-programs';
        $this->postJson($path, $this->terms())->assertForbidden();
        $id = $this->postJson($path, $this->terms(['publication_status' => 'pending']))->assertCreated()->json('id');
        $this->getJson('/api/new-buildings/'.$building->id.'/payment-programs')->assertOk()->assertJsonPath('total', 0);
        Sanctum::actingAs($this->actor());
        $this->patchJson($path.'/'.$id, ['version' => 1, 'publication_status' => 'published', 'confirmation_reference' => null])->assertUnprocessable();
        $this->patchJson($path.'/'.$id, ['version' => 1, 'publication_status' => 'published'])->assertOk()->assertJsonPath('version', 2);
        $this->getJson('/api/new-buildings/'.$building->id.'/payment-programs')->assertOk()->assertJsonPath('total', 1)->assertJsonMissingPath('data.0.confirmation_reference')->assertJsonMissingPath('data.0.verified_by');
        $this->patchJson($path.'/'.$id, ['version' => 1, 'title' => 'Stale'])->assertConflict();
        Sanctum::actingAs($agent);
        $this->patchJson($path.'/'.$id, ['version' => 2, 'fees_verified' => false])->assertOk()->assertJsonPath('publication_status', 'pending');
        $this->getJson('/api/new-buildings/'.$building->id.'/payment-programs')->assertOk()->assertJsonPath('total', 0);
        $this->assertDatabaseHas('crm_audit_logs', ['event' => 'residential.program.updated']);
    }

    public function test_admin_program_reload_finds_an_older_page_without_widening_scope_or_permissions(): void
    {
        $admin = $this->actor();
        $agent = $this->actor('agent');
        $building = $this->building(['created_by' => $agent->id]);
        $foreign = $this->building(['created_by' => $admin->id]);
        $path = '/api/admin/new-buildings/'.$building->id.'/payment-programs';
        $foreignPath = '/api/admin/new-buildings/'.$foreign->id.'/payment-programs';
        Sanctum::actingAs($admin);
        $id = $this->postJson($path, $this->terms())->assertCreated()->json('id');
        for ($index = 0; $index < 20; $index++) {
            $this->postJson($path, $this->terms())->assertCreated();
        }
        $foreignId = $this->postJson($foreignPath, $this->terms())->assertCreated()->json('id');
        $firstPage = $this->getJson($path)->assertOk()->assertJsonCount(20, 'data');
        $this->assertNotContains($id, array_column($firstPage->json('data'), 'id'));
        $this->patchJson($path.'/'.$id, ['version' => 1, 'title' => 'Fresh QA program'])->assertOk();
        $this->getJson($path.'?program_id='.$id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.version', 2)->assertJsonPath('can_publish', true);
        $this->getJson($path.'?program_id='.$foreignId)->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/admin/payment-programs?program_id='.$id)->assertOk()->assertJsonPath('total', 0);
        $this->getJson($path.'?program_id=0')->assertUnprocessable();
        Sanctum::actingAs($agent);
        $this->getJson($path.'?program_id='.$id)->assertOk()->assertJsonPath('data.0.version', 2)->assertJsonPath('can_publish', false);
        $this->getJson($foreignPath.'?program_id='.$foreignId)->assertForbidden();
        $this->getJson('/api/admin/payment-programs?program_id='.$id)->assertForbidden();
        Sanctum::actingAs($this->actor('client'));
        $this->getJson($path.'?program_id='.$id)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_scopes_and_quote_resolve_actual_unit_price_and_versions_not_client_price(): void
    {
        Sanctum::actingAs($this->actor());
        $building = $this->building();
        $unit = $building->units()->create(['name' => 'A1', 'area' => 50, 'rooms' => 2, 'publication_status' => 'published', 'availability_status' => 'available', 'total_price' => 100000]);
        $other = $building->units()->create(['name' => 'A2', 'area' => 60, 'rooms' => 2, 'publication_status' => 'published', 'availability_status' => 'available', 'total_price' => 200000]);
        $draft = $building->units()->create(['name' => 'Secret', 'area' => 50, 'publication_status' => 'draft']);
        $path = '/api/admin/new-buildings/'.$building->id.'/payment-programs';
        $id = $this->postJson($path, $this->terms(['scope' => 'units', 'unit_ids' => [$unit->id, $draft->id]]))->assertCreated()->json('id');
        $this->getJson('/api/new-buildings/'.$building->id.'/payment-programs?unit_id='.$unit->id)->assertOk()->assertJsonPath('data.0.unit_count', 1)->assertJsonMissingPath('data.0.unit_ids');
        $this->getJson('/api/new-buildings/'.$building->id.'/payment-programs?unit_id='.$other->id)->assertOk()->assertJsonPath('total', 0);
        $payload = ['version' => 1, 'building_id' => $building->id, 'unit_id' => $unit->id, 'unit_version' => 1, 'price' => '1.00', 'down_percent' => 20, 'months' => 12];
        $quote = '/api/payment-programs/'.$id.'/quote';
        $this->postJson($quote, $payload)->assertOk()->assertJsonPath('price', '100000.00')->assertJsonPath('principal', '80000.00')->assertJsonPath('price_source', 'inventory')->assertJsonPath('first_payment', '6666.67');
        $this->postJson($quote, [...$payload, 'unit_id' => $other->id])->assertNotFound();
        $unit->update(['version' => 2, 'total_price' => 110000]);
        $this->postJson($quote, $payload)->assertConflict();
        $unit->update(['availability_status' => 'sold']);
        $this->postJson($quote, [...$payload, 'unit_version' => 2])->assertUnprocessable();
        $this->postJson($quote, [...$payload, 'unit_id' => $draft->id])->assertNotFound();
    }

    public function test_minimum_percentage_is_rounded_up_to_currency_precision_without_weakening_the_program_minimum(): void
    {
        Sanctum::actingAs($this->actor());
        $id = $this->postJson('/api/admin/payment-programs', $this->terms(['type' => 'mortgage', 'bank_name' => 'QA']))->assertCreated()->json('id');
        $path = '/api/payment-programs/'.$id.'/quote';
        $payload = ['version' => 1, 'price' => '510000.01', 'down_percent' => '20', 'months' => 12];
        $quote = $this->postJson($path, $payload)->assertOk()
            ->assertJsonPath('down_payment', '102000.01')
            ->assertJsonPath('principal', '408000.00')
            ->assertJsonPath('total_cost', '510000.01')
            ->assertJsonPath('schedule.11.balance', '0.00');
        $this->assertStringContainsString('Взнос, заданный процентом, округляется вверх до 0,01 TJS', implode(' ', $quote->json('assumptions')));
        $this->postJson($path, [...$payload, 'price' => '510000.03'])->assertOk()->assertJsonPath('down_payment', '102000.01');
        $this->postJson($path, [...$payload, 'price' => '510000.05'])->assertOk()->assertJsonPath('down_payment', '102000.01');
        $this->postJson($path, [...$payload, 'price' => '510000.00'])->assertOk()->assertJsonPath('down_payment', '102000.00');
        // Reject a below-minimum percentage even if cent rounding would lift its amount.
        $this->postJson($path, [...$payload, 'price' => '0.06', 'down_percent' => '19'])
            ->assertUnprocessable()->assertJsonValidationErrors(['down_percent'], 'details.errors');
        $amountPayload = ['version' => 1, 'price' => '510000.01', 'months' => 12];
        $this->postJson($path, [...$amountPayload, 'down_payment' => '102000.00'])->assertUnprocessable();
        $this->postJson($path, [...$amountPayload, 'down_payment' => '102000.01'])->assertOk()->assertJsonPath('principal', '408000.00');
        $building = $this->building();
        $unit = $building->units()->create(['name' => 'QA decimal unit', 'area' => 51, 'rooms' => 2,
            'publication_status' => 'published', 'availability_status' => 'available', 'total_price' => '510000.01']);
        $before = $unit->fresh()->getAttributes();
        $this->postJson($path, [...$payload, 'building_id' => $building->id, 'unit_id' => $unit->id, 'unit_version' => 1, 'price' => '1.00'])
            ->assertOk()->assertJsonPath('price_source', 'inventory')->assertJsonPath('price', '510000.01')->assertJsonPath('down_payment', '102000.01');
        $this->assertEquals($before, $unit->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_expired_unconfirmed_and_archived_programs_are_hidden_and_manual_conditions_do_not_invent_rates(): void
    {
        Sanctum::actingAs($this->actor());
        $building = $this->building();
        $path = '/api/admin/new-buildings/'.$building->id.'/payment-programs';
        $this->postJson($path, $this->terms(['valid_from' => now()->subMonth()->toDateString(), 'valid_until' => now()->subDay()->toDateString()]))->assertCreated();
        $this->postJson($path, $this->terms(['publication_status' => 'draft']))->assertCreated();
        $this->postJson($path, $this->terms(['publication_status' => 'archived']))->assertCreated();
        $manual = $this->postJson($path, $this->terms(['calculation_method' => 'manual', 'annual_rate' => null, 'fees_verified' => false, 'upfront_fee_fixed' => null]))->assertCreated()->json('id');
        $this->getJson('/api/new-buildings/'.$building->id.'/payment-programs')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.calculator_available', false)->assertJsonPath('data.0.annual_rate', null);
        $this->postJson('/api/payment-programs/'.$manual.'/quote', ['building_id' => $building->id, 'version' => 1, 'price' => 100000, 'down_payment' => 20000, 'months' => 12])->assertUnprocessable();
        $this->postJson($path, $this->terms(['source_url' => 'javascript:alert(1)']))->assertUnprocessable();
        $building->update(['publication_status' => 'draft']);
        $this->getJson('/api/new-buildings/'.$building->id.'/payment-programs')->assertNotFound();
    }

    public function test_global_mortgages_are_shared_and_branch_roles_cannot_edit_foreign_or_global_programs(): void
    {
        $a = DB::table('branches')->insertGetId(['name' => 'A']);
        $b = DB::table('branches')->insertGetId(['name' => 'B']);
        $building = $this->building(['branch_id' => $a]);
        $foreign = $this->building(['branch_id' => $b]);
        Sanctum::actingAs($this->actor());
        $global = $this->postJson('/api/admin/payment-programs', $this->terms(['type' => 'mortgage', 'bank_name' => 'Тестовый банк']))->assertCreated()->json('id');
        $this->getJson('/api/payment-programs')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.valid_from', now()->subDay()->toDateString())
            ->assertJsonPath('data.0.valid_until', now()->addMonth()->toDateString());
        $this->getJson('/api/new-buildings/'.$building->id.'/payment-programs')->assertOk()->assertJsonPath('data.0.id', $global);
        $this->patchJson('/api/admin/payment-programs/'.$global, ['version' => 1, 'title' => 'Обновлённая тестовая программа'])->assertOk()->assertJsonPath('version', 2);
        $this->postJson('/api/payment-programs/'.$global.'/quote', ['version' => 1, 'price' => 100000, 'down_payment' => 20000, 'months' => 12])->assertConflict();
        foreach (['rop', 'branch_director', 'agent', 'mop', 'hr', 'accountant', 'client'] as $role) {
            Sanctum::actingAs($this->actor($role, $a));
            $this->getJson('/api/admin/payment-programs')->assertForbidden();
            $this->postJson('/api/admin/payment-programs', $this->terms(['type' => 'mortgage', 'bank_name' => 'Fake']))->assertForbidden();
            $this->postJson('/api/admin/new-buildings/'.$foreign->id.'/payment-programs', $this->terms())->assertForbidden();
        }
        Sanctum::actingAs($this->actor('rop', $a));
        $this->postJson('/api/admin/new-buildings/'.$building->id.'/payment-programs', $this->terms())->assertCreated();
    }

    public function test_assigned_mop_can_submit_but_not_publish_or_archive_a_program(): void
    {
        $mop = $this->actor('mop');
        $admin = $this->actor();
        $building = $this->building(['created_by' => $admin->id, 'responsible_agent_id' => $mop->id]);
        $path = '/api/admin/new-buildings/'.$building->id.'/payment-programs';
        Sanctum::actingAs($mop);
        $this->getJson($path)->assertOk()->assertJsonPath('can_publish', false);
        $id = $this->postJson($path, $this->terms(['publication_status' => 'pending']))->assertCreated()->json('id');
        $auditCount = DB::table('crm_audit_logs')->count();
        foreach (['published', 'archived', 'rejected'] as $status) {
            $this->patchJson($path.'/'.$id, ['version' => 1, 'publication_status' => $status])->assertForbidden();
        }
        $this->assertDatabaseHas('payment_programs', ['id' => $id, 'version' => 1, 'publication_status' => 'pending']);
        $this->assertDatabaseCount('crm_audit_logs', $auditCount);
        Sanctum::actingAs($admin);
        $this->patchJson($path.'/'.$id, ['version' => 1, 'publication_status' => 'published'])->assertOk()->assertJsonPath('version', 2);
        Sanctum::actingAs($mop);
        $this->patchJson($path.'/'.$id, ['version' => 2, 'title' => 'Изменение назначенным МОП'])
            ->assertOk()->assertJsonPath('version', 3)->assertJsonPath('publication_status', 'pending');
        $this->getJson('/api/new-buildings/'.$building->id.'/payment-programs')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_assignment_does_not_grant_program_access_to_clients_hr_or_accountants(): void
    {
        foreach (['client', 'hr', 'accountant'] as $role) {
            $actor = $this->actor($role);
            $building = $this->building(['created_by' => $actor->id, 'responsible_agent_id' => $actor->id]);
            $path = '/api/admin/new-buildings/'.$building->id.'/payment-programs';
            Sanctum::actingAs($this->actor());
            $id = $this->postJson($path, $this->terms())->assertCreated()->json('id');
            $auditCount = DB::table('crm_audit_logs')->count();
            Sanctum::actingAs($actor);
            $this->getJson($path)->assertForbidden();
            $this->postJson($path, $this->terms(['publication_status' => 'pending']))->assertForbidden();
            $this->patchJson($path.'/'.$id, ['version' => 1, 'title' => 'Forbidden change'])->assertForbidden();
            $this->assertDatabaseHas('payment_programs', ['id' => $id, 'version' => 1, 'publication_status' => 'published']);
            $this->assertDatabaseCount('crm_audit_logs', $auditCount);
            $this->assertNull($actor->fresh()->branch_id);
        }
    }

    public function test_branch_director_can_moderate_only_own_branch_programs(): void
    {
        $ownBranch = DB::table('branches')->insertGetId(['name' => 'Own branch']);
        $otherBranch = DB::table('branches')->insertGetId(['name' => 'Other branch']);
        $director = $this->actor('branch_director', $ownBranch);
        $building = $this->building(['branch_id' => $ownBranch]);
        $foreign = $this->building(['branch_id' => $otherBranch]);
        Sanctum::actingAs($this->actor());
        $foreignPath = '/api/admin/new-buildings/'.$foreign->id.'/payment-programs';
        $foreignId = $this->postJson($foreignPath, $this->terms())->assertCreated()->json('id');
        Sanctum::actingAs($director);
        $path = '/api/admin/new-buildings/'.$building->id.'/payment-programs';
        $this->getJson($path)->assertOk()->assertJsonPath('can_publish', true);
        $id = $this->postJson($path, $this->terms(['publication_status' => 'pending']))->assertCreated()->json('id');
        $this->patchJson($path.'/'.$id, ['version' => 1, 'publication_status' => 'published'])->assertOk()->assertJsonPath('version', 2);
        $auditCount = DB::table('crm_audit_logs')->count();
        $this->getJson($foreignPath)->assertForbidden();
        $this->patchJson($foreignPath.'/'.$foreignId, ['version' => 1, 'publication_status' => 'archived'])->assertForbidden();
        $this->patchJson($path.'/'.$foreignId, ['version' => 1, 'publication_status' => 'archived'])->assertNotFound();
        $this->assertDatabaseHas('payment_programs', ['id' => $foreignId, 'version' => 1, 'publication_status' => 'published']);
        $this->assertDatabaseCount('crm_audit_logs', $auditCount);
    }
}

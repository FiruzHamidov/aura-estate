<?php

namespace Tests\Feature;

use App\Jobs\ProcessCampaignSms;
use App\Jobs\SubmitBulkCampaignSms;
use App\Models\Role;
use App\Models\SmsCampaignRecipient;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SmsCampaignTest extends TestCase
{
    private const URL = '/api/crm/sms-campaigns';

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.api_requests.enabled' => false, 'kpi.daily_report.enforce_submission' => false, 'osonsmsservice.bulk_token' => null]);
        foreach ([
            '0001_01_01_000000_create_roles_table', '0001_01_01_000003_create_users_table', '0001_01_01_000002_create_jobs_table',
            '2025_06_23_015158_create_personal_access_tokens_table', '2026_02_10_120000_create_branches_table',
            '2026_02_10_120100_add_branch_id_to_users_table', '2026_06_15_120000_add_account_deletion_fields_to_users_table',
            '2026_03_07_100000_create_clients_table', '2026_03_07_120000_create_leads_table',
            '2026_04_28_120000_create_client_sources_table', '2026_04_28_120100_add_source_to_clients_table',
            '2026_11_14_120000_create_sms_campaigns',
        ] as $name) {
            (require database_path("migrations/$name.php"))->up();
        }
        Schema::table('clients', fn (Blueprint $t) => $t->string('contact_kind')->default('buyer'));
    }

    private function user(string $role): User
    {
        $role = Role::firstOrCreate(['slug' => $role], ['name' => $role]);
        $id = DB::table('users')->insertGetId([
            'role_id' => $role->id, 'name' => $role->name, 'phone' => (string) (910000001 + User::count()), 'created_at' => now(),
        ]);

        return User::findOrFail($id);
    }

    private function client(string $phone, array $attributes = []): int
    {
        return DB::table('clients')->insertGetId($attributes + ['phone' => $phone, 'full_name' => 'Test', 'created_at' => now()]);
    }

    private function draft(array $segment = ['base' => 'clients']): array
    {
        return ['request_id' => (string) Str::uuid(), 'name' => 'Подборка', 'message' => 'Новые квартиры', 'segment' => $segment];
    }

    private function work(): void
    {
        $job = Queue::connection('database')->pop();
        $this->assertNotNull($job);
        $job->fire();
    }

    private function reviewed(array $draft): array
    {
        return $draft + ['preview_token' => $this->postJson(self::URL.'/preview', $draft)->assertOk()->json('preview_token')];
    }

    public function test_access_is_checked_before_validation_on_every_action(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        foreach (['marketing', 'admin', 'superadmin'] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson(self::URL.'/options')->assertOk();
        }
        foreach (['client', 'agent', 'rop', 'owner'] as $role) {
            Sanctum::actingAs($this->user($role));
            foreach (['', '/options', '/1/recipients', '/exclusions'] as $path) {
                $this->getJson(self::URL.$path)->assertForbidden();
            }
            foreach (['', '/preview', '/1/cancel', '/exclusions'] as $path) {
                $this->postJson(self::URL.$path)->assertForbidden();
            }
            $this->deleteJson(self::URL.'/exclusions/1')->assertForbidden();
        }
    }

    public function test_unique_snapshot_requires_new_preview_when_numbers_or_text_change(): void
    {
        Sanctum::actingAs($this->user('marketing'));
        $this->client('917000001');
        $this->client('+992 (917) 000-001', ['contact_kind' => 'both']);
        $this->client('incorrect');
        $this->client('+14155552671');
        $this->client('917000002', ['contact_kind' => 'seller']);
        $this->client('917000003');
        $this->client('917000004', ['deleted_at' => now()]);
        $this->postJson(self::URL.'/exclusions', ['phone' => '917000003'])->assertNoContent();
        $draft = $this->draft(['base' => 'clients', 'contact_kind' => 'buyer']);
        $preview = $this->postJson(self::URL.'/preview', $draft)->assertOk()
            ->assertJson(['total' => 1, 'duplicates' => 1, 'invalid' => 2, 'excluded' => 1]);
        $payload = $draft + ['preview_token' => $preview->json('preview_token')];
        $this->postJson(self::URL, ['message' => 'Другой текст'] + $payload)->assertConflict();
        DB::table('clients')->where('phone', '917000001')->update(['phone' => '917000003']);
        $this->postJson(self::URL, $payload)->assertConflict();
        $fresh = $this->reviewed($draft);
        $id = $this->postJson(self::URL, $fresh)->assertCreated()->json('id');
        $this->postJson(self::URL, $fresh)->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('sms_campaign_recipients', 1);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.0.counts.pending', 1);
        $this->getJson(self::URL."/$id/recipients")->assertOk()->assertJsonPath('data.0.phone', '••• 0001');
        $this->postJson(self::URL.'/preview', $this->draft(['base' => 'accounts', 'contact_kind' => 'buyer']))->assertUnprocessable();
    }

    public function test_accounts_and_leads_use_distinct_filters_and_exclude_inactive_accounts(): void
    {
        Sanctum::actingAs($this->user('admin'));
        $this->user('client');
        DB::table('users')->where('id', $this->user('client')->id)->update(['status' => 'inactive']);
        DB::table('users')->where('id', $this->user('client')->id)->update(['deletion_requested_at' => now()]);
        $this->postJson(self::URL.'/preview', $this->draft(['base' => 'accounts']))->assertOk()->assertJsonPath('total', 1);
        foreach (['new', 'assigned'] as $status) {
            DB::table('leads')->insert(['phone' => '917000002', 'status' => $status, 'source' => 'web', 'created_at' => now()]);
        }
        $draft = $this->draft(['base' => 'leads', 'status' => 'new', 'source' => 'web', 'from' => now()->toDateString()]);
        $this->postJson(self::URL.'/preview', $draft)->assertOk()->assertJson(['total' => 1, 'duplicates' => 0]);
        $empty = $this->reviewed($this->draft(['base' => 'leads', 'status' => 'lost']));
        $this->postJson(self::URL, $empty)->assertUnprocessable();
    }

    public function test_scheduling_worker_delivery_and_cancellation(): void
    {
        Sanctum::actingAs($this->user('admin'));
        $this->client('917000001');
        $draft = $this->draft() + ['scheduled_at' => now()->addHour()->utc()->format('Y-m-d\\TH:i:s.v\\Z')];
        $id = $this->postJson(self::URL, $this->reviewed($draft))->assertCreated()->json('id');
        $this->artisan('sms:dispatch-campaigns')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        $this->travel(61)->minutes();
        $this->artisan('sms:dispatch-campaigns')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
        $recipient = SmsCampaignRecipient::firstOrFail();
        $txn = "campaign_{$id}_{$recipient->id}";
        Http::fake([
            'https://api.osonsms.com/sendsms_v1.php*' => Http::response(['status' => 'ok', 'msg_id' => 87], 201),
            'https://api.osonsms.com/query_sms.php*' => Http::response(['message_state' => 'DELIVERED']),
        ]);
        $this->work();
        $this->assertSame('accepted', $recipient->fresh()->status);
        app()->call([new ProcessCampaignSms($recipient->id), 'handle']);
        $this->assertSame('accepted', $recipient->fresh()->status);
        $this->travel(3)->minutes();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r['phone_number'] === '992917000001' && $r['txn_id'] === $txn
            && $r['msg'] === $draft['message'] && $r['from'] === config('osonsmsservice.sender_name')
            && hash_equals(hash('sha256', implode(';', [$txn, config('osonsmsservice.login'), $r['from'], $r['phone_number'],
                config('osonsmsservice.pass_salt_hash')])), $r['str_hash']));
        $this->artisan('sms:dispatch-campaigns')->assertSuccessful();
        $this->work();
        $this->assertSame('delivered', $recipient->fresh()->status);
        Http::assertSentCount(2);
        $id = $this->postJson(self::URL, $this->reviewed($this->draft()))->assertCreated()->json('id');
        $this->artisan('sms:dispatch-campaigns')->assertSuccessful();
        $this->postJson(self::URL."/$id/cancel")->assertNoContent();
        $this->work();
        $this->assertDatabaseHas('sms_campaign_recipients', ['campaign_id' => $id, 'status' => 'cancelled']);
    }

    public function test_provider_timeout_reuses_transaction_and_rejection_is_recorded_without_fallback(): void
    {
        config(['osonsmsservice.bulk_token' => 'test-only']);
        Sanctum::actingAs($this->user('admin'));
        $this->client('917000001');
        $id = $this->postJson(self::URL, $this->reviewed($this->draft()))->assertCreated()->json('id');
        $transactions = [];
        Http::fake(function (Request $request) use (&$transactions) {
            preg_match('/name="txn_id"\r\n[^\r]*\r?\n?\r\n([^\r]+)/', $request->body(), $match);
            $transactions[] = $match[1] ?? null;
            if (count($transactions) === 1) {
                return Http::failedConnection()($request);
            }

            return Http::response(['message' => 'Validation errors'], 422);
        });
        $this->artisan('sms:dispatch-campaigns')->assertSuccessful();
        $this->work();
        $this->assertCount(2, $transactions);
        $this->assertStringStartsWith("bulk_{$id}_", $transactions[0]);
        $this->assertSame($transactions[0], $transactions[1]);
        $this->assertDatabaseHas('sms_campaign_recipients', ['campaign_id' => $id, 'status' => 'failed', 'next_action_at' => null]);
        $this->artisan('sms:dispatch-campaigns')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_bulk_multipart_checks_exclusions_and_current_phone_before_submission(): void
    {
        config(['osonsmsservice.bulk_token' => 'test-only']);
        Sanctum::actingAs($this->user('marketing'));
        $this->client('917000001');
        $this->client('917000002');
        $changed = $this->client('917000003');
        $draft = $this->draft();
        $id = $this->postJson(self::URL, $this->reviewed($draft))->assertCreated()->json('id');
        $this->artisan('sms:dispatch-campaigns')->assertSuccessful();
        $this->postJson(self::URL.'/exclusions', ['phone' => '917000002'])->assertNoContent();
        DB::table('clients')->where('id', $changed)->update(['phone' => '917000004']);
        Http::fake(['https://cab.osonsms.com/api/v1/bulk-sms/create' => Http::response(['status' => 'success', 'sms_bulk_name' => 'bulk-01'])]);
        $this->work();
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->hasHeader('Authorization', 'Bearer test-only')
            && $r->isMultipart() && str_contains($r->body(), '992917000001') && ! str_contains($r->body(), '992917000002')
            && ! str_contains($r->body(), '992917000003') && str_contains($r->body(), 'name="send_at"'));
        app()->call([new SubmitBulkCampaignSms($id, SmsCampaignRecipient::pluck('id')->all()), 'handle']);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('sms_campaign_recipients', ['campaign_id' => $id, 'status' => 'accepted', 'bulk_name' => 'bulk-01', 'next_action_at' => null]);
        $this->assertSame(2, SmsCampaignRecipient::where('status', 'skipped')->count());
    }
}

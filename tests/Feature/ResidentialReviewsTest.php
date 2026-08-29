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

class ResidentialReviewsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ResidentialSchema::create();
        foreach (['2025_06_23_004505_create_reviews_table.php', '2026_03_03_150000_prepare_reviews_table.php', '2026_08_28_160000_add_residential_review_moderation.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        $this->withoutMiddleware([EnsureDailyReportSubmitted::class, LogApiRequest::class]);
        Http::preventStrayRequests();
    }

    private function actor(string $slug = 'client', ?int $branch = null): User
    {
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);

        return User::create(['name' => $slug, 'phone' => '+992'.random_int(100000000, 999999999), 'role_id' => $role->id, 'branch_id' => $branch]);
    }

    private function building(array $data = []): NewBuilding
    {
        return NewBuilding::create(['title' => 'Reviews fixture', 'publication_status' => 'published', ...$data]);
    }

    private function submit(User $author, NewBuilding $building): int
    {
        Sanctum::actingAs($author);

        return $this->postJson('/api/new-buildings/'.$building->id.'/reviews', ['rating' => 5, 'text' => 'Удобный двор и расположение.'])->assertCreated()->assertJsonPath('status', 'pending')->json('id');
    }

    public function test_review_needs_auth_and_moderation_and_edit_removes_old_rating_until_reapproved(): void
    {
        $building = $this->building();
        $path = '/api/new-buildings/'.$building->id.'/reviews';
        $this->getJson($path)->assertOk()->assertJsonPath('summary.count', 0)->assertJsonPath('summary.average', null);
        $this->postJson($path, ['rating' => 5, 'text' => 'Хороший проект.'])->assertUnauthorized();
        $author = $this->actor();
        $id = $this->submit($author, $building);
        $this->postJson($path, ['rating' => 5, 'text' => 'Повторный отзыв.'])->assertConflict();
        $this->getJson($path)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('summary.count', 0);
        $this->getJson($path.'/mine')->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.version', 1);
        $moderation = '/api/admin/new-buildings/'.$building->id.'/reviews/'.$id.'/moderation';
        Sanctum::actingAs($this->actor('admin'));
        $this->patchJson($moderation, ['version' => 1, 'status' => 'approved', 'reason' => 'Проверено по правилам'])->assertOk()->assertJsonPath('version', 2);
        $this->getJson($path)->assertOk()->assertJsonPath('summary.count', 1)->assertJsonPath('summary.average', 5)->assertJsonMissingPath('data.0.author_phone')->assertJsonMissingPath('data.0.author_user_id')->assertJsonMissingPath('data.0.moderation_reason');
        Sanctum::actingAs($author);
        $this->patchJson($path.'/'.$id, ['version' => 1, 'rating' => 3, 'text' => 'Обновил впечатления от комплекса.'])->assertConflict();
        $this->patchJson($path.'/'.$id, ['version' => 2, 'rating' => 3, 'text' => 'Обновил впечатления от комплекса.'])->assertOk()->assertJsonPath('version', 3)->assertJsonPath('status', 'pending');
        $this->getJson($path)->assertOk()->assertJsonPath('summary.count', 0)->assertJsonPath('summary.average', null);
        $this->assertDatabaseHas('crm_audit_logs', ['event' => 'residential.review.edited']);
        $this->assertDatabaseHas('crm_audit_logs', ['event' => 'residential.review.moderated']);
    }

    public function test_branch_scope_and_author_ownership_apply_to_every_review_action(): void
    {
        $branch = DB::table('branches')->insertGetId(['name' => 'A']);
        $foreign = DB::table('branches')->insertGetId(['name' => 'B']);
        $building = $this->building(['branch_id' => $branch]);
        $other = $this->building(['branch_id' => $foreign]);
        $id = $this->submit($this->actor(), $building);
        foreach (['client', 'agent', 'mop', 'hr', 'accountant'] as $role) {
            Sanctum::actingAs($this->actor($role, $branch));
            $this->getJson('/api/admin/new-buildings/'.$building->id.'/reviews')->assertForbidden();
            $this->patchJson('/api/admin/new-buildings/'.$building->id.'/reviews/'.$id.'/moderation', ['version' => 1, 'status' => 'approved', 'reason' => 'Проверено'])->assertForbidden();
            $this->patchJson('/api/new-buildings/'.$building->id.'/reviews/'.$id, ['version' => 1, 'rating' => 1, 'text' => 'Нельзя редактировать чужое.'])->assertForbidden();
        }
        foreach (['rop', 'branch_director'] as $role) {
            Sanctum::actingAs($this->actor($role, $foreign));
            $this->getJson('/api/admin/new-buildings/'.$building->id.'/reviews')->assertForbidden();
        }
        Sanctum::actingAs($this->actor('rop', $branch));
        $this->patchJson('/api/admin/new-buildings/'.$building->id.'/reviews/'.$id.'/moderation', ['version' => 1, 'status' => 'approved', 'reason' => 'Проверено'])->assertOk();
        Sanctum::actingAs($this->actor('admin'));
        $this->patchJson('/api/admin/new-buildings/'.$other->id.'/reviews/'.$id.'/moderation', ['version' => 2, 'status' => 'approved', 'reason' => 'Проверено'])->assertNotFound();
    }

    public function test_complaints_are_idempotent_scoped_and_resolution_does_not_silently_hide_review(): void
    {
        $building = $this->building();
        $other = $this->building();
        $id = $this->submit($this->actor(), $building);
        $admin = $this->actor('admin');
        Sanctum::actingAs($admin);
        $report = '/api/new-buildings/'.$building->id.'/reviews/'.$id.'/complaints';
        $this->postJson($report, ['reason' => 'Отзыв содержит недостоверную информацию.'])->assertNotFound();
        $this->patchJson('/api/admin/new-buildings/'.$building->id.'/reviews/'.$id.'/moderation', ['version' => 1, 'status' => 'approved', 'reason' => 'Проверено'])->assertOk();
        Sanctum::actingAs($this->actor());
        $complaint = $this->postJson($report, ['reason' => 'Отзыв содержит недостоверную информацию.'])->assertCreated()->json('id');
        $this->postJson($report, ['reason' => 'Отзыв содержит недостоверную информацию.'])->assertOk()->assertJsonPath('id', $complaint);
        $this->getJson('/api/admin/new-buildings/'.$building->id.'/review-complaints')->assertForbidden();
        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/new-buildings/'.$building->id.'/review-complaints')->assertOk()->assertJsonPath('total', 1);
        $payload = ['version' => 1, 'status' => 'dismissed', 'resolution' => 'Нарушений не обнаружено.'];
        $this->patchJson('/api/admin/new-buildings/'.$other->id.'/review-complaints/'.$complaint, $payload)->assertNotFound();
        $path = '/api/admin/new-buildings/'.$building->id.'/review-complaints/'.$complaint;
        $this->patchJson($path, $payload)->assertOk()->assertJsonPath('version', 2);
        $this->patchJson($path, $payload)->assertConflict();
        $this->getJson('/api/new-buildings/'.$building->id.'/reviews')->assertOk()->assertJsonPath('summary.count', 1);
        $this->assertDatabaseCount('review_complaints', 1);
        $this->assertDatabaseHas('crm_audit_logs', ['event' => 'residential.review.complaint_resolved']);
    }

    public function test_moderator_cannot_approve_own_review_and_hidden_complex_is_not_exposed(): void
    {
        $building = $this->building();
        $admin = $this->actor('admin');
        $id = $this->submit($admin, $building);
        $this->patchJson('/api/admin/new-buildings/'.$building->id.'/reviews/'.$id.'/moderation', ['version' => 1, 'status' => 'approved', 'reason' => 'Самопроверка'])->assertForbidden();
        $this->postJson('/api/new-buildings/'.$building->id.'/reviews', ['rating' => 6, 'text' => 'Достаточно длинный текст'])->assertUnprocessable();
        $building->update(['publication_status' => 'draft']);
        $this->getJson('/api/new-buildings/'.$building->id.'/reviews')->assertNotFound();
        $this->getJson('/api/new-buildings/'.$building->id.'/reviews/mine')->assertNotFound();
    }
}

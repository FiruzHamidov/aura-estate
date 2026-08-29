<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDailyReportSubmitted;
use App\Http\Middleware\LogApiRequest;
use App\Models\Feature;
use App\Models\NewBuilding;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ResidentialSchema;
use Tests\TestCase;

class ResidentialMediaAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ResidentialSchema::create();
        $this->withoutMiddleware([EnsureDailyReportSubmitted::class, LogApiRequest::class]);
        Storage::fake('residential');
        Storage::fake('public');
        DB::table('branches')->insert([['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']]);
        DB::table('locations')->insert(['id' => 1, 'city' => 'QA city']);
    }

    public static function accessCases(): array
    {
        $cases = [];
        foreach (['admin', 'superadmin', 'owner'] as $role) {
            $cases[$role.' global'] = [$role, 'foreign', true, true];
        }
        foreach (['rop', 'branch_director'] as $role) {
            $cases[$role.' own branch'] = [$role, 'unrelated', true, true];
            $cases[$role.' foreign'] = [$role, 'foreign', false, false];
            $cases[$role.' without branch'] = [$role, 'branchless', false, false];
        }
        foreach (['agent', 'mop'] as $role) {
            $cases[$role.' creator'] = [$role, 'created', true, false];
            $cases[$role.' assigned'] = [$role, 'assigned', true, false];
            $cases[$role.' unrelated'] = [$role, 'unrelated', false, false];
        }
        foreach (['client', 'hr', 'accountant', 'external_agent', 'unknown'] as $role) {
            $cases[$role.' assigned'] = [$role, 'assigned', false, false];
        }
        $cases['guest'] = [null, 'unrelated', false, false];

        return $cases;
    }

    private function actor(string $slug, ?int $branch): User
    {
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);

        return User::create(['name' => 'QA '.$slug, 'phone' => '+992'.random_int(100000000, 999999999), 'role_id' => $role->id, 'branch_id' => $branch]);
    }

    private function fixture(?User $actor, string $scope): array
    {
        $branch = $scope === 'foreign' ? 2 : 1;
        $manager = $this->actor('admin', $branch);
        $building = NewBuilding::create([
            'title' => 'QA scoped content', 'publication_status' => 'published', 'moderation_status' => 'approved',
            'created_by' => $scope === 'created' ? $actor->id : $manager->id,
            'responsible_agent_id' => $scope === 'assigned' ? $actor->id : $manager->id,
            'branch_id' => $branch, 'location_id' => 1, 'address' => 'QA address', 'data_verified_at' => now(),
        ])->fresh();
        $block = $building->blocks()->create(['name' => 'A', 'floors_from' => 1, 'floors_to' => 5])->fresh();
        $entrance = $building->entrances()->create(['block_id' => $block->id, 'name' => '1', 'residential_floor_from' => 1, 'residential_floor_to' => 5]);
        $layout = $building->layouts()->create(['code' => 'A2', 'rooms' => 2, 'typical_area' => 50])->fresh();
        $plan = $building->floorPlans()->create(['block_id' => $block->id, 'entrance_id' => $entrance->id, 'floor_from' => 1, 'floor_to' => 1])->fresh();
        $unit = $building->units()->create([
            'name' => 'QA unit', 'block_id' => $block->id, 'entrance_id' => $entrance->id, 'layout_id' => $layout->id,
            'area' => 50, 'rooms' => 2, 'bedrooms' => 2, 'floor' => 1, 'number' => '1',
            'publication_status' => 'published', 'availability_status' => 'available', 'moderation_status' => 'available',
            'is_available' => true, 'pricing_basis' => 'total', 'total_price' => 100000,
        ])->fresh();
        $photo = $building->photos()->create(['path' => 'qa.webp', 'kind' => 'masterplan', 'alt' => 'Before'])->fresh();
        $unitPhoto = $unit->photos()->create(['path' => 'qa-unit.webp', 'kind' => 'plan', 'alt' => 'Before'])->fresh();
        $feature = Feature::create(['name' => 'QA feature '.$building->id, 'slug' => 'qa-'.$building->id]);
        $newFeature = Feature::create(['name' => 'QA new feature '.$building->id, 'slug' => 'qa-new-'.$building->id]);
        $building->features()->attach($feature->id);

        return compact('building', 'block', 'layout', 'plan', 'unit', 'photo', 'unitPhoto', 'feature', 'newFeature');
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['new_buildings', 'new_building_blocks', 'new_building_entrances', 'unit_layouts', 'building_floor_plans', 'developer_units', 'new_building_photos', 'developer_unit_photos', 'features', 'feature_new_building', 'crm_audit_logs'] as $table) {
            $result[$table] = DB::table($table)->get()->toJson();
        }
        $result['files'] = Storage::disk('residential')->allFiles();

        return $result;
    }

    public function test_author_edits_already_pending_structure_still_advance_parent_version(): void
    {
        $actor = $this->actor('agent', 1);
        Sanctum::actingAs($actor);
        ['building' => $building, 'block' => $block, 'unit' => $unit] = $this->fixture($actor, 'created');
        $unitBefore = $unit->getAttributes();
        $path = '/api/new-buildings/'.$building->id.'/blocks/'.$block->id;
        $this->patchJson($path, ['version' => 1, 'name' => 'First edit'])->assertOk();
        $this->assertSame('pending', $building->fresh()->publication_status);
        $this->assertSame(2, $building->fresh()->version);
        $this->patchJson($path, ['version' => 2, 'name' => 'Second edit'])->assertOk();
        $this->assertSame('pending', $building->fresh()->publication_status);
        $this->assertSame(3, $building->fresh()->version);
        $this->assertSame(3, $block->fresh()->version);
        $this->assertSame('Second edit', $block->fresh()->name);
        $this->assertSame($unitBefore, $unit->fresh()->getAttributes());
        $after = $this->snapshot();
        $this->patchJson($path, ['version' => 2, 'name' => 'Stale edit'])->assertConflict();
        $this->assertSame($after, $this->snapshot());
    }

    public static function structureActors(): array
    {
        return [['admin'], ['agent']];
    }

    #[DataProvider('structureActors')]
    public function test_global_feature_attachment_preserves_other_buildings_and_nested_resource_scope(string $role): void
    {
        $actor = $this->actor($role, 1);
        Sanctum::actingAs($actor);
        $owned = $this->fixture($actor, 'created');
        $foreign = $this->fixture($this->actor('admin', 2), 'foreign');
        $feature = $owned['newFeature'];
        $foreign['building']->features()->attach($feature->id);
        $foreignBefore = $foreign['building']->fresh()->getAttributes();
        $path = '/api/new-buildings/'.$owned['building']->id;
        $featurePath = $path.'/features/'.$feature->id;

        $before = $this->snapshot();
        $this->postJson($featurePath)->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
        $this->postJson($featurePath, ['version' => 1])->assertOk();
        $this->assertSame($role === 'admin' ? 'published' : 'pending', $owned['building']->fresh()->publication_status);
        $this->assertSame($foreignBefore, $foreign['building']->fresh()->getAttributes());
        $this->assertTrue($foreign['building']->features()->whereKey($feature->id)->exists());
        $attached = $this->snapshot();
        $this->postJson($featurePath, ['version' => 1])->assertConflict();
        $this->assertSame($attached, $this->snapshot());

        // A current-version repeat cannot duplicate the pivot or remove existing features.
        $this->postJson($featurePath, ['version' => 2])->assertOk();
        $this->assertSame(2, $owned['building']->features()->count());
        $this->assertSame(2, $foreign['building']->features()->count());
        $this->assertSame(2, DB::table('feature_new_building')->where('feature_id', $feature->id)->count());
        $this->assertSame($foreignBefore, $foreign['building']->fresh()->getAttributes());

        // Only global feature attachment is unscoped; private child resources remain scoped.
        $beforeForeignWrites = $this->snapshot();
        $this->patchJson($path.'/blocks/'.$foreign['block']->id, ['version' => 1, 'name' => 'Wrong parent'])->assertNotFound();
        $this->patchJson($path.'/units/'.$foreign['unit']->id, ['version' => 1, 'name' => 'Wrong parent'])->assertNotFound();
        $this->patchJson($path.'/photos/'.$foreign['photo']->id, ['version' => 1, 'alt' => 'Wrong parent'])->assertNotFound();
        $this->assertSame($beforeForeignWrites, $this->snapshot());

        $this->deleteJson($featurePath, ['version' => 3])->assertOk();
        $this->assertFalse($owned['building']->features()->whereKey($feature->id)->exists());
        $this->assertTrue($owned['building']->features()->whereKey($owned['feature']->id)->exists());
        $this->assertTrue($foreign['building']->features()->whereKey($feature->id)->exists());
        $this->assertSame($foreignBefore, $foreign['building']->fresh()->getAttributes());
        $detached = $this->snapshot();
        $this->deleteJson($featurePath, ['version' => 4])->assertNotFound();
        $this->postJson($path.'/features/999999', ['version' => 4])->assertNotFound();
        $this->assertSame($detached, $this->snapshot());
    }

    #[DataProvider('structureActors')]
    public function test_failed_child_audit_rolls_back_structure_and_parent_version(string $role): void
    {
        $actor = $this->actor($role, 1);
        Sanctum::actingAs($actor);
        ['building' => $building, 'block' => $block] = $this->fixture($actor, 'created');
        $before = $this->snapshot();
        // The parent audit has a message; fail the child audit after both records were saved.
        DB::statement("CREATE TRIGGER qa_reject_structure_audit BEFORE INSERT ON crm_audit_logs WHEN NEW.message IS NULL BEGIN SELECT RAISE(ABORT, 'QA child audit failure'); END");
        $path = '/api/new-buildings/'.$building->id.'/blocks/'.$block->id;
        try {
            $this->patchJson($path, ['version' => 1, 'name' => 'Must roll back'])->assertStatus(500);
            $this->assertSame($before, $this->snapshot());
        } finally {
            DB::statement('DROP TRIGGER qa_reject_structure_audit');
        }
        $this->patchJson($path, ['version' => 1, 'name' => 'Retry succeeds'])->assertOk();
        $this->assertSame('Retry succeeds', $block->fresh()->name);
        $this->assertSame(2, $building->fresh()->version);
        $this->assertSame($role === 'admin' ? 'published' : 'pending', $building->fresh()->publication_status);
        $this->assertDatabaseCount('crm_audit_logs', 2);
    }

    #[DataProvider('accessCases')]
    public function test_nested_content_operations_enforce_scope_moderation_and_versions(?string $role, string $scope, bool $allowed, bool $moderator): void
    {
        $actor = $role ? $this->actor($role, $scope === 'branchless' || $role === 'client' ? null : 1) : null;
        if ($actor) {
            Sanctum::actingAs($actor);
        }
        foreach (['building-photo', 'unit-photo', 'masterplan', 'layout-image', 'floor-image', 'block', 'feature', 'feature-attach'] as $operation) {
            $f = $this->fixture($actor, $scope);
            ['building' => $building, 'unit' => $unit] = $f;
            $public = '/api/new-buildings/'.$building->id;
            $admin = '/api/admin/new-buildings/'.$building->id;
            $before = $this->snapshot();
            $unitBefore = $unit->getAttributes();
            $request = match ($operation) {
                'building-photo' => ['PATCH', $public.'/photos/'.$f['photo']->id, ['version' => 1, 'alt' => 'Reviewed change']],
                'unit-photo' => ['PATCH', $public.'/units/'.$unit->id.'/photos/'.$f['unitPhoto']->id, ['version' => 1, 'alt' => 'Reviewed change']],
                'masterplan' => ['PATCH', $admin.'/masterplan/'.$f['photo']->id.'/regions', ['version' => 1, 'block_regions' => [['block_id' => $f['block']->id, 'points' => [[0, 0], [100, 0], [100, 100]]]]]],
                'layout-image' => ['POST', $admin.'/layouts/'.$f['layout']->id.'/image', ['version' => 1, 'file' => UploadedFile::fake()->image('layout.png', 100, 100)]],
                'floor-image' => ['POST', $admin.'/floor-plans/'.$f['plan']->id.'/image', ['version' => 1, 'file' => UploadedFile::fake()->image('floor.png', 100, 100)]],
                'block' => ['PATCH', $public.'/blocks/'.$f['block']->id, ['version' => 1, 'name' => 'B']],
                'feature' => ['DELETE', $public.'/features/'.$f['feature']->id, ['version' => 1]],
                'feature-attach' => ['POST', $public.'/features/'.$f['newFeature']->id, ['version' => 1]],
            };
            $response = $this->json(...$request);
            if (! $allowed) {
                $response->assertStatus($actor ? 403 : 401);
                foreach ([$admin, $admin.'/photos', $admin.'/units/'.$unit->id.'/photos', $admin.'/layouts', $admin.'/floor-plans', $admin.'/blocks'] as $readPath) {
                    $this->getJson($readPath)->assertStatus($actor ? 403 : 401);
                }
                $this->assertSame($before, $this->snapshot(), $operation.' changed data after denial');

                continue;
            }
            $response->assertOk();
            $target = $operation === 'unit-photo' ? $unit->fresh() : $building->fresh();
            $this->assertSame(2, $target->version, $operation);
            $this->assertSame($moderator ? 'published' : 'pending', $target->publication_status, $operation);
            match ($operation) {
                'building-photo' => $this->assertSame('Reviewed change', $f['photo']->fresh()->alt),
                'unit-photo' => $this->assertSame('Reviewed change', $f['unitPhoto']->fresh()->alt),
                'masterplan' => $this->assertSame($f['block']->id, $f['photo']->fresh()->block_regions[0]['block_id']),
                'layout-image', 'floor-image' => Storage::disk('residential')->assertExists($f[$operation === 'layout-image' ? 'layout' : 'plan']->fresh()->image_path),
                'block' => $this->assertSame('B', $f['block']->fresh()->name),
                'feature' => $this->assertFalse($building->features()->whereKey($f['feature']->id)->exists()),
                'feature-attach' => $this->assertSame([$f['feature']->id, $f['newFeature']->id], $building->features()->orderBy('features.id')->pluck('features.id')->all()),
            };
            if ($operation === 'unit-photo') {
                $this->assertSame('published', $building->fresh()->publication_status);
                $this->assertSame($unitBefore['total_price'], $unit->fresh()->getAttributes()['total_price']);
                $this->assertSame($unitBefore['availability_status'], $unit->fresh()->availability_status);
            } else {
                $this->assertSame($unitBefore, $unit->fresh()->getAttributes());
            }
            $this->getJson($admin)->assertOk()->assertJsonPath('capabilities.publish', $moderator);
            $this->getJson($public.'/units/'.$unit->id)->assertStatus($moderator ? 200 : 404);
            $this->assertDatabaseHas('crm_audit_logs', ['actor_id' => $actor->id]);
            $after = $this->snapshot();
            // A detached relation no longer binds; other old versions conflict. Neither may write.
            $replay = $this->json(...$request);
            $this->assertSame($operation === 'feature' ? 404 : 409, $replay->status(), $operation.' stale replay: '.$replay->getContent());
            $this->assertSame($after, $this->snapshot(), $operation.' changed data after stale replay');
            if (! $moderator) {
                $targetPath = $operation === 'unit-photo' ? $public.'/units/'.$unit->id : $public;
                $this->patchJson($targetPath, ['version' => 2, 'publication_status' => 'published'])->assertForbidden();
                $this->assertSame($after, $this->snapshot(), $operation.' bypassed moderation');
            }
        }
    }
}

<?php

namespace Tests\Feature;

use App\Models\Developer;
use Tests\Support\ResidentialSchema;
use Tests\TestCase;

class DeveloperProjectCountsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ResidentialSchema::create();
        // These tests exercise validation and persistence, not route authorization.
        $this->withoutMiddleware([
            \Illuminate\Auth\Middleware\Authenticate::class,
            \App\Http\Middleware\EnsureUserIsActive::class,
            \App\Http\Middleware\EnsureDailyReportSubmitted::class,
            \App\Http\Middleware\EnsureUserIsNotClient::class,
        ]);
    }

    public function test_creation_rejects_inconsistent_totals_and_preserves_real_zero(): void
    {
        $this->postJson('/api/developers', ['name' => 'Invalid', 'total_projects' => 2,
            'under_construction_count' => 2, 'built_count' => 2])->assertUnprocessable()->assertJsonStructure(['details' => ['errors' => ['total_projects']]]);
        $this->assertDatabaseCount('developers', 0);
        $this->postJson('/api/developers', ['name' => 'Empty', 'total_projects' => 0,
            'under_construction_count' => 0, 'built_count' => 0])->assertCreated()->assertJsonPath('total_projects', 0);
    }

    public function test_partial_update_validates_against_saved_counts_without_blocking_unrelated_legacy_edits(): void
    {
        $developer = Developer::create(['name' => 'Existing', 'total_projects' => 4, 'under_construction_count' => 2, 'built_count' => 2]);
        $this->patchJson('/api/developers/'.$developer->id, ['total_projects' => 3])->assertUnprocessable();
        $this->assertSame(4, (int) $developer->fresh()->total_projects);
        $this->patchJson('/api/developers/'.$developer->id, ['total_projects' => 5])->assertOk()->assertJsonPath('total_projects', 5);
        $developer->update(['total_projects' => 2]);
        $this->patchJson('/api/developers/'.$developer->id, ['name' => 'Renamed'])->assertOk();
        $this->patchJson('/api/developers/'.$developer->id, ['total_projects' => 4])->assertOk();
    }
}

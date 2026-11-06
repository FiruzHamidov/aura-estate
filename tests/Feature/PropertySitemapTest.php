<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PropertySitemapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\LogApiRequest::class);
        Schema::dropAllTables();
        Schema::create('properties', function (Blueprint $t) {
            $t->id();
            $t->string('moderation_status')->default('approved');
            $t->string('publication_status')->default('published');
            $t->string('deal_status')->default('available');
            $t->timestamp('sold_at')->nullable();
            $t->string('owner_phone')->nullable();
            $t->timestamps();
        });
        Schema::create('property_moderation_cases', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('property_id');
            $t->string('status');
            $t->boolean('blocking');
        });
        Schema::create('property_duplicate_candidates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('moderation_case_id');
            $t->string('decision');
            $t->timestamp('reversed_at')->nullable();
        });
    }

    public function test_only_public_listings_are_discoverable_without_private_fields(): void
    {
        foreach ([[], ['publication_status' => 'draft'], ['publication_status' => 'pending'],
            ['publication_status' => 'hidden'], ['publication_status' => 'archived'],
            ['deal_status' => 'sold'], ['deal_status' => 'rented'], ['moderation_status' => 'sold_by_owner'],
            ['sold_at' => now()], [], [], []] as $i => $attributes) {
            DB::table('properties')->insert(['id' => $i + 1, 'owner_phone' => 'private',
                'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-11-01 10:00:00', ...$attributes]);
        }
        DB::table('property_moderation_cases')->insert([
            ['id' => 1, 'property_id' => 10, 'status' => 'open', 'blocking' => true],
            ['id' => 2, 'property_id' => 11, 'status' => 'approved', 'blocking' => false],
            ['id' => 3, 'property_id' => 12, 'status' => 'rejected', 'blocking' => true],
        ]);
        DB::table('property_duplicate_candidates')->insert(['moderation_case_id' => 2, 'decision' => \App\Models\PropertyDuplicateCandidate::DECISION_CONFIRMED]);
        $this->getJson('/api/property-sitemap')->assertOk()->assertExactJson(['page_size' => 500, 'pages' => 1]);
        $this->getJson('/api/property-sitemap?page=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.path', '/apartment/1')->assertJsonMissingPath('data.0.owner_phone')
            ->assertJsonPath('data.0.last_modified', '2026-11-01T10:00:00+05:00');
    }

    public function test_pagination_is_bounded_ordered_and_validated(): void
    {
        DB::table('properties')->insert(array_map(fn ($id) => ['id' => $id], range(1, 501)));
        $this->getJson('/api/property-sitemap')->assertJsonPath('pages', 2);
        $this->getJson('/api/property-sitemap?page=1')->assertJsonCount(500, 'data');
        $this->getJson('/api/property-sitemap?page=2')->assertJsonCount(1, 'data')->assertJsonPath('data.0.path', '/apartment/501');
        $this->getJson('/api/property-sitemap?page=3')->assertNotFound();
        $this->getJson('/api/property-sitemap?page=0')->assertUnprocessable();
        $this->getJson('/api/property-sitemap?page=1000001')->assertUnprocessable();
    }
}

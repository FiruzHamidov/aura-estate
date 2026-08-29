<?php

namespace Tests\Feature;

use App\Models\DeveloperUnit;
use App\Models\NewBuilding;
use App\Models\NewBuildingBlock;
use Tests\Support\ResidentialSchema;
use Tests\TestCase;

class ResidentialSitemapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ResidentialSchema::create();
        $this->withoutMiddleware(\App\Http\Middleware\LogApiRequest::class);
    }

    public function test_sitemap_contains_only_public_objects_including_sold_but_not_hidden_parent_or_block(): void
    {
        $building = NewBuilding::create(['title' => 'QA', 'publication_status' => 'published']);
        $draft = NewBuilding::create(['title' => 'QA private', 'publication_status' => 'draft']);
        $block = NewBuildingBlock::create(['new_building_id' => $building->id, 'name' => 'Archive', 'archived_at' => now()]);
        foreach ([['published', $building->id, null], ['draft', $building->id, null], ['published', $draft->id, null], ['published', $building->id, $block->id]] as [$status, $parent, $blockId]) {
            DeveloperUnit::create(['new_building_id' => $parent, 'block_id' => $blockId, 'name' => 'QA lot', 'area' => 50, 'publication_status' => $status, 'availability_status' => 'sold']);
        }
        $this->getJson('/api/residential-sitemap')->assertOk()->assertJsonPath('pages.buildings', 1)->assertJsonPath('pages.units', 1);
        $this->getJson('/api/residential-sitemap?type=buildings')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.path', '/new-buildings/'.$building->id)->assertJsonMissingPath('data.0.title');
        $this->getJson('/api/residential-sitemap?type=units')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.path', '/new-buildings/'.$building->id.'/units/1');
        $this->getJson('/api/residential-sitemap?type=units&page=0')->assertUnprocessable();
        $this->getJson('/api/residential-sitemap?type=private')->assertUnprocessable();
        $building->update(['publication_status' => 'archived']);
        $this->getJson('/api/residential-sitemap?type=units')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_sitemap_is_bounded_and_deterministically_paginated(): void
    {
        for ($i = 1; $i <= 501; $i++) {
            NewBuilding::create(['title' => 'QA '.$i, 'publication_status' => 'published']);
        }
        $this->getJson('/api/residential-sitemap')->assertOk()->assertJsonPath('pages.buildings', 2);
        $this->getJson('/api/residential-sitemap?type=buildings')->assertOk()->assertJsonCount(500, 'data');
        $this->getJson('/api/residential-sitemap?type=buildings&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.path', '/new-buildings/501');
        $this->getJson('/api/residential-sitemap?type=buildings&page=3')->assertNotFound();
    }
}

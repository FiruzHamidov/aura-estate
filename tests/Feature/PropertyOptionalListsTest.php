<?php

namespace Tests\Feature;

use App\Http\Controllers\PropertyController;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PropertyOptionalListsTest extends TestCase
{
    public function test_omitted_lists_remain_absent_from_patch(): void
    {
        $validated = app(PropertyController::class)->validateProperty(Request::create('/', 'POST', ['price' => 800000]), true);
        $this->assertArrayNotHasKey('features', $validated);
        $this->assertArrayNotHasKey('tags', $validated);
    }

    public function test_empty_arrays_null_and_legacy_multipart_empty_arrays_are_optional(): void
    {
        foreach ([[], null, '', '[]', ' [] '] as $empty) {
            $validated = app(PropertyController::class)->validateProperty(Request::create('/', 'POST', ['features' => $empty, 'tags' => $empty]), true);
            $this->assertSame([], $validated['features'] ?? []);
            $this->assertSame([], $validated['tags'] ?? []);
            $this->assertArrayHasKey('features', $validated);
            $this->assertArrayHasKey('tags', $validated);
        }
    }

    public function test_create_accepts_omitted_optional_lists(): void
    {
        \Illuminate\Support\Facades\Schema::create('property_types', function ($table) { $table->id(); });
        \Illuminate\Support\Facades\DB::table('property_types')->insert(['id' => 1]);
        $validated = app(PropertyController::class)->validateProperty(Request::create('/', 'POST', [
            'type_id' => 1, 'price' => 800000, 'currency' => 'TJS', 'offer_type' => 'sale',
        ]));
        $this->assertArrayNotHasKey('features', $validated);
        $this->assertArrayNotHasKey('tags', $validated);
    }

    public function test_non_array_values_are_still_rejected(): void
    {
        foreach (['invalid', '1', '{}'] as $invalid) {
            try {
                app(PropertyController::class)->validateProperty(Request::create('/', 'POST', ['features' => $invalid, 'tags' => $invalid]), true);
                $this->fail('Invalid selection must not be accepted.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('features', $e->errors());
                $this->assertArrayHasKey('tags', $e->errors());
            }
        }
    }
}

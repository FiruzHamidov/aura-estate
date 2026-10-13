<?php

namespace Tests\Feature;

use App\Http\Controllers\PropertyController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PropertyOwnerPhoneTest extends TestCase
{
    public function test_creation_and_edit_accept_copied_owner_phone_and_strip_invisible_marks(): void
    {
        Schema::create('property_types', function ($table) { $table->id(); });
        DB::table('property_types')->insert(['id' => 1]);
        foreach ([false, true] as $update) {
            $validated = app(PropertyController::class)->validateProperty(Request::create('/', 'POST', [
                'type_id' => 1, 'price' => 800000, 'currency' => 'TJS', 'offer_type' => 'sale',
                'owner_phone' => "\u{202a}+992001234567\u{202c}",
            ]), $update);
            $this->assertSame('+992001234567', $validated['owner_phone']);
        }
    }

    public function test_invalid_owner_number_remains_rejected(): void
    {
        foreach (["\u{202a}+99200123456\u{202c}", '+9920012345678', '+992001234567 ext 2'] as $raw) {
            try {
                app(PropertyController::class)->validateProperty(Request::create('/', 'POST', ['owner_phone' => $raw]), true);
                $this->fail('Invalid owner number accepted');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('owner_phone', $exception->errors());
            }
        }
    }
}

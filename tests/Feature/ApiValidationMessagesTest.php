<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ApiValidationMessagesTest extends TestCase
{
    public function test_numeric_limits_and_dates_have_readable_russian_reasons(): void
    {
        app()->setLocale('ru');
        $validator = Validator::make([
            'price' => -1,
            'total_area' => 1001,
            'data_verified_at' => 'not-a-date',
        ], [
            'price' => ['numeric', 'min:0'],
            'total_area' => ['numeric', 'max:1000'],
            'data_verified_at' => ['date'],
        ]);

        $errors = $validator->errors()->toArray();
        $this->assertStringContainsString('не меньше 0', $errors['price'][0]);
        $this->assertStringContainsString('не должно превышать 1000', $errors['total_area'][0]);
        $this->assertStringContainsString('корректную дату', $errors['data_verified_at'][0]);
    }
}

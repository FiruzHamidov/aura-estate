<?php

namespace App\Rules;

use App\Support\InternationalPhone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class InternationalPhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || InternationalPhone::e164($value) === null) {
            $fail('Введите полный номер с кодом страны: после +992 нужно 9 цифр, после +7 - 10.');
        }
    }
}

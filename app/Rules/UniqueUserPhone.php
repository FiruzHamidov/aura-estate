<?php

namespace App\Rules;

use App\Support\UserPhoneIdentity;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class UniqueUserPhone implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreUserId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }
        $query = UserPhoneIdentity::query($value);
        if ($this->ignoreUserId !== null) {
            $query->whereKeyNot($this->ignoreUserId);
        }
        if ($query->exists()) {
            $fail('Этот номер уже связан с аккаунтом. Используйте существующий аккаунт или обратитесь к администратору.');
        }
    }
}

<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;

final class UserPhoneIdentity
{
    public static function variants(string $phone): array
    {
        $e164 = InternationalPhone::e164($phone);
        if ($e164 === null) {
            return [trim($phone)];
        }

        $digits = substr($e164, 1);
        $variants = [trim($phone), $e164, $digits, '00'.$digits];
        if (str_starts_with($e164, '+992')) {
            $national = substr($e164, 4);
            $variants[] = $national;
            $variants[] = '0'.$national;
        }

        return array_values(array_unique($variants));
    }

    public static function query(string $phone): Builder
    {
        $variants = self::variants($phone);
        // REPLACE is supported by both production MySQL and test SQLite.
        // Match legacy presentation separators without changing stored contacts.
        $expression = 'phone';
        foreach ([' ', '+', '-', '(', ')', '.'] as $separator) {
            $expression = "REPLACE({$expression}, '{$separator}', '')";
        }
        $digits = array_values(array_unique(array_map(
            fn (string $value) => str_replace([' ', '+', '-', '(', ')', '.'], '', $value),
            $variants
        )));

        return User::query()->where(function (Builder $query) use ($phone, $expression, $digits) {
            $query->where('phone', $phone)->orWhereRaw(
                $expression.' IN ('.implode(',', array_fill(0, count($digits), '?')).')',
                $digits
            );
        });
    }

    public static function resolve(string $phone): ?User
    {
        $matches = self::query($phone)->with('role')->get();
        $active = $matches->filter(fn (User $user) => $user->status === User::STATUS_ACTIVE && ! $user->isDeletedAccount());
        if ($active->count() > 1) {
            throw new HttpResponseException(response()->json([
                'code' => 'PHONE_IDENTITY_CONFLICT',
                'message' => 'Этот номер связан с несколькими активными аккаунтами. Обратитесь к администратору Aura.',
            ], 409));
        }

        return $active->first() ?? $matches->first();
    }
}

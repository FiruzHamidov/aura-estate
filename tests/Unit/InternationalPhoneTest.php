<?php

namespace Tests\Unit;

use App\Support\ClientPhone;
use App\Support\InternationalPhone;
use PHPUnit\Framework\TestCase;

class InternationalPhoneTest extends TestCase
{
    public function test_leading_zero_account_numbers_survive_repeated_normalization(): void
    {
        foreach (['001103103', '000123456', '012345678'] as $national) {
            foreach ([$national, '+992'.$national, '992'.$national, '00992'.$national] as $input) {
                $this->assertSame('+992'.$national, InternationalPhone::e164($input));
                $account = InternationalPhone::accountValue($input);
                $this->assertSame($national, $account);
                $this->assertSame('+992'.$national, InternationalPhone::e164($account));
                $this->assertSame('992'.$national, ClientPhone::normalize($input));
            }
        }
    }

    public function test_copied_phone_ignores_direction_marks_but_not_invalid_visible_text(): void
    {
        foreach (["\u{202a}+992001234567\u{202c}", "\u{200e}+992\u{00a0}001234567\u{200f}"] as $raw) {
            $this->assertSame('+992001234567', InternationalPhone::e164($raw));
            $this->assertSame('001234567', InternationalPhone::accountValue($raw));
            $this->assertSame('992001234567', ClientPhone::normalize($raw));
        }
        $this->assertNull(InternationalPhone::e164("\u{202a}+99200123456\u{202c}"));
        $this->assertNull(InternationalPhone::e164("\u{202a}+992001234567x\u{202c}"));
    }

    public function test_country_lengths_and_legacy_formats(): void
    {
        foreach (['+99290123456', '+9929012345678', '+7912345678', '+791234567890', '+99890123456', '+999123456789', 'abc', '+992901234567 ext 1', '+99290+1234567'] as $invalid) {
            $this->assertNull(InternationalPhone::e164($invalid), $invalid);
        }
        foreach ([
            '901234567' => '+992901234567',
            '0901234567' => '+992901234567',
            '992901234567' => '+992901234567',
            '00 992 90 123 45 67' => '+992901234567',
            '+7 (912) 345-67-89' => '+79123456789',
            '+77011234567' => '+77011234567',
            '+998901234567' => '+998901234567',
            '+33 1 23 45 67 89' => '+33123456789',
            '+49 30 123456' => '+4930123456',
        ] as $raw => $expected) {
            $this->assertSame($expected, InternationalPhone::e164((string) $raw));
            $this->assertSame(substr($expected, 1), ClientPhone::normalize((string) $raw));
        }
        $this->assertSame('901234567', InternationalPhone::accountValue('+992901234567'));
        $this->assertSame('79123456789', InternationalPhone::accountValue('+79123456789'));
        $this->assertNull(InternationalPhone::e164(null));
        $this->assertNull(ClientPhone::normalize(''));
        // Search fragments are deliberately not rejected by the normalizer.
        $this->assertSame('9012', ClientPhone::normalize('9012'));
    }
}

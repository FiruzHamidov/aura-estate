<?php

namespace Tests\Unit;

use App\Services\Chat\ApartmentSearchIntent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ApartmentSearchIntentTest extends TestCase
{
    public static function apartmentRequests(): array
    {
        return array_map(fn ($message) => [$message], [
            'podberi 10 samix malenkix kvartir korobok s razrisheniyami na remonta',
            'Подбери 10 самых маленьких квартир-коробок с разрешением на ремонт',
            'Покажи квартиру', 'Нужны квартиры', 'kvartira', 'kvartiry', 'show apartments',
            'Квартира в этом доме',
        ]);
    }

    #[DataProvider('apartmentRequests')]
    public function test_explicit_apartments_survive_omitted_or_wrong_tool_type(string $message): void
    {
        foreach ([[], ['property_type' => null], ['property_type' => 'house']] as $args) {
            $this->assertSame('apartment', (new ApartmentSearchIntent)->apply($args, [$message, 'prodaju', 'davay', 'Покажи эти коробки в районе Сино'])['property_type']);
        }
    }

    public static function changedRequests(): array
    {
        return array_map(fn ($message) => [$message], [
            'Теперь дома', 'Покажи квартиры и дома', 'Не квартиры', 'Теперь участки',
            'Теперь офисы', 'Любой тип', 'Любую недвижимость', 'Новый поиск',
            'Квартиру в новостройке', 'Теперь вторичка', 'show houses', 'new search',
        ]);
    }

    #[DataProvider('changedRequests')]
    public function test_changed_intent_releases_the_old_apartment_requirement(string $message): void
    {
        $args = ['property_type' => 'chosen-by-provider'];
        $this->assertSame($args, (new ApartmentSearchIntent)->apply($args, ['Нужны квартиры', $message, 'davay']));
    }
}

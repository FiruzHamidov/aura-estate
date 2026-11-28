<?php

namespace Tests\Unit;

use App\Services\Chat\PropertySearchFilters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PropertySearchFiltersTest extends TestCase
{
    public function test_null_and_zero_placeholders_do_not_become_search_constraints(): void
    {
        $this->assertSame(['sort_by' => 'area_asc', 'limit' => 10], (new PropertySearchFilters)->normalize([
            'city' => '', 'property_type' => '', 'districts' => [], 'currency' => null,
            'priceFrom' => 0, 'priceTo' => null, 'roomsFrom' => 0, 'roomsTo' => 0,
            'areaFrom' => 0, 'areaTo' => 0, 'rooms_min' => 0, 'rooms_max' => 0,
            'repair_condition' => null, 'renovation_permission_status' => null,
            'sort_by' => 'area_asc', 'limit' => 10,
        ]));
    }

    public static function continuations(): array
    {
        return [
            ['prodaju', 'sale'], ['На продажу!', 'sale'], ['arenda', 'rent'],
            ['davay', 'rent'], ['Давай', 'rent'], ['Покажи ещё', 'rent'],
        ];
    }

    #[DataProvider('continuations')]
    public function test_short_follow_up_keeps_prior_constraints(string $message, string $offer): void
    {
        $previous = ['property_type' => 'apartment', 'districts' => ['Сино'], 'offer_type' => 'rent', 'roomsFrom' => 2, 'roomsTo' => 2, 'areaTo' => 80, 'limit' => 10, 'sort_by' => 'area_asc'];
        $this->assertSame(array_replace($previous, ['offer_type' => $offer]), (new PropertySearchFilters)->forMessage([
            'property_type' => '', 'areaTo' => 0, 'roomsTo' => 0, 'sort_by' => 'recommended', 'limit' => 6,
        ], $message, $previous));
    }

    public function test_new_explicit_search_can_change_or_remove_previous_filters(): void
    {
        $args = ['property_type' => 'house', 'repair_condition' => 'any', 'areaFrom' => 100];
        $this->assertSame($args, (new PropertySearchFilters)->forMessage($args, 'Теперь дома от 100 квадратов с любым ремонтом', [
            'property_type' => 'apartment', 'areaTo' => 50, 'repair_condition' => 'shell',
        ]));
        $this->assertSame(['offer_type' => 'sale'], (new PropertySearchFilters)->forMessage(['offer_type' => 'sale'], 'prodaju'));
    }

    public function test_district_refinement_preserves_the_previous_property_type(): void
    {
        $this->assertSame(['districts' => ['Сино'], 'property_type' => 'apartment'], (new PropertySearchFilters)->forMessage([
            'districts' => ['Сино'], 'property_type' => null,
        ], 'Покажи эти коробки на продажу в районе Сино', ['property_type' => 'apartment']));
    }

    public static function changedTypeRequests(): array
    {
        return array_map(fn ($message) => [$message], [
            'Новый поиск в Сино', 'Начнём заново', 'Теперь дома в Сино',
            'Покажи земельные участки', 'Любую недвижимость в Сино', 'Любой тип',
            'Теперь новостройки', 'Теперь вторичка', 'new search', 'show houses', 'Покажи квартиры',
        ]);
    }

    #[DataProvider('changedTypeRequests')]
    public function test_explicit_type_change_or_reset_does_not_restore_old_type(string $message): void
    {
        $this->assertSame([], (new PropertySearchFilters)->forMessage(['property_type' => null], $message, ['property_type' => 'apartment']));
    }
}

<?php

namespace Tests\Unit;

use App\Models\Property;
use App\Services\PropertyDuplicateService;
use ReflectionMethod;
use Tests\TestCase;

class PropertyDuplicateServiceTest extends TestCase
{
    private function listing(array $changes = []): array
    {
        return array_replace([
            'id' => 999, 'type_id' => 1, 'location_id' => 1,
            'owner_phone' => '900000001', 'owner_client_id' => 1,
            'rooms' => 2, 'total_area' => 60, 'floor' => 3, 'total_floors' => 12,
            'repair_type_id' => 1, 'developer_id' => 1, 'year_built' => 2024,
            'address' => 'улица Сомони, дом 10, кв. 12',
            'description' => 'Светлая просторная квартира рядом школа магазин',
            'latitude' => 38.57, 'longitude' => 68.78,
            'currency' => 'TJS', 'price' => 600000,
        ], $changes);
    }

    private function score(array $source, array $other): ?array
    {
        $candidate = new Property;
        $candidate->setRawAttributes($other);
        $candidate->setRelation('photos', collect());

        return (new ReflectionMethod(PropertyDuplicateService::class, 'score'))
            ->invoke(new PropertyDuplicateService, $source, $candidate);
    }

    public function test_conflicting_facts_override_all_positive_signals(): void
    {
        foreach ([
            ['floor' => 9], ['rooms' => 4], ['total_area' => 120],
            ['total_floors' => 20], ['type_id' => 2], ['location_id' => 2],
            ['address' => 'улица Сомони, дом 10, кв. 45'],
            ['address' => 'улица Сомони, дом 11, кв. 12'],
            ['address' => 'улица Сомони, дом 10, кв. 12а'],
            ['latitude' => 38.67],
        ] as $changes) {
            $this->assertNull($this->score($this->listing(), $this->listing($changes)), json_encode($changes));
        }
    }

    public function test_typical_apartments_and_template_text_do_not_identify_a_unit(): void
    {
        $source = $this->listing(['address' => 'улица Сомони, дом 10']);
        $other = array_replace($source, ['owner_phone' => '900000002', 'owner_client_id' => 2]);
        $this->assertNull($this->score($source, $other));
    }

    public function test_neighbouring_buildings_with_same_owner_are_not_duplicates(): void
    {
        $this->assertNull($this->score(
            $this->listing(['address' => 'Сино']),
            $this->listing(['address' => 'Сино', 'latitude' => 38.571])
        ));
    }

    public function test_owner_without_physical_or_location_evidence_is_insufficient(): void
    {
        $this->assertNull($this->score(
            $this->listing(['rooms' => null, 'floor' => null]), $this->listing()
        ));
        $this->assertNull($this->score(
            $this->listing(['address' => 'Сино', 'latitude' => null, 'longitude' => null]),
            $this->listing(['address' => 'Сино'])
        ));
    }

    public function test_same_owner_and_property_is_detected_despite_price_and_format_changes(): void
    {
        $result = $this->score($this->listing(), $this->listing([
            'owner_phone' => '+992 (90) 000-00-01', 'total_area' => 61.5, 'price' => 900000,
        ]));
        $this->assertNotNull($result);
        $this->assertGreaterThan(90, $result['score']);
        $ownerSignals = collect($result['signals'])->whereIn('code', ['phone', 'owner_client']);
        $this->assertEquals(45, $ownerSignals->sum('weight'));
    }

    public function test_exact_unit_and_address_can_identify_duplicate_without_shared_owner(): void
    {
        $this->assertNotNull($this->score($this->listing(), $this->listing([
            'owner_phone' => '900000002', 'owner_client_id' => 2,
            'latitude' => null, 'longitude' => null,
        ])));
    }

    public function test_unit_number_alone_is_not_a_complete_address(): void
    {
        $this->assertNull($this->score(
            $this->listing(['address' => 'кв. 12']),
            $this->listing(['address' => 'кв. 12', 'owner_phone' => '900000002', 'owner_client_id' => 2])
        ));
    }

    public function test_room_count_in_description_is_not_an_apartment_number(): void
    {
        $this->assertNotNull($this->score(
            $this->listing(['description' => 'Квартира 2 комнатная']),
            $this->listing(['description' => 'Квартира 3 комнаты до перепланировки'])
        ));
    }
}

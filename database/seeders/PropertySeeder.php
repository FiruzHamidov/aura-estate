<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Property;
use App\Models\User;
use App\Models\PropertyType;
use App\Models\PropertyStatus;
use App\Models\Location;

class PropertySeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@example.com')->first();
        $typeApartment = PropertyType::where('slug', 'apartment')->first();
        $statusAvailable = PropertyStatus::where('slug', 'available')->first();
        $location = Location::first();

        Property::create([
            'title' => '2-комн квартира в центре Душанбе',
            'description' => 'Светлая квартира с евроремонтом в центре города.',
            'type_id' => $typeApartment->id,
            'status_id' => $statusAvailable->id,
            'location_id' => $location?->id,
            'price' => 75000,
            'total_area' => 80,
            'living_area' => 60,
            'floor' => 5,
            'total_floors' => 9,
            'year_built' => 2015,
            'condition' => 'Отличное',
            'apartment_type' => '2-комн',
            'repair_type_id' => 1,
            'has_garden' => false,
            'has_parking' => true,
            'is_mortgage_available' => true,
            'is_from_developer' => false,
            'landmark' => 'Площадь Озоди',
            'moderation_status' => 'approved',
            'created_by' => $admin->id,
        ]);

        Property::create([
            'title' => 'Новостройка от застройщика',
            'description' => 'Современный жилой комплекс с развитой инфраструктурой.',
            'type_id' => $typeApartment->id,
            'status_id' => $statusAvailable->id,
            'location_id' => $location?->id,
            'price' => 95000,
            'total_area' => 100,
            'living_area' => 75,
            'floor' => 12,
            'total_floors' => 16,
            'year_built' => 2024,
            'condition' => 'Новостройка',
            'apartment_type' => '3-комн',
            'repair_type_id' => 1,
            'has_garden' => true,
            'has_parking' => true,
            'is_mortgage_available' => true,
            'is_from_developer' => true,
            'landmark' => 'ТЦ Душанбе Молл',
            'moderation_status' => 'approved',
            'created_by' => $admin->id,
        ]);
    }
}

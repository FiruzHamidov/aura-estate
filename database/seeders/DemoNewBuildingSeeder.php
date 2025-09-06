<?php

namespace Database\Seeders;

use App\Models\{NewBuilding, Developer, ConstructionStage, Material, NewBuildingBlock, DeveloperUnit, Feature};
use Illuminate\Database\Seeder;

class DemoNewBuildingSeeder extends Seeder
{
    public function run(): void
    {
        $developer = Developer::first();
        $stage = ConstructionStage::where('name','Сдан')->first() ?? ConstructionStage::first();
        $material = Material::first();

        $nb = NewBuilding::updateOrCreate(
            ['title' => 'ЖК «Сияние»'],
            [
                'description' => 'Современный жилой комплекс в центре города.',
                'developer_id' => optional($developer)->id,
                'construction_stage_id' => optional($stage)->id,
                'material_id' => optional($material)->id,
                'installment_available' => true,
                'heating' => true,
                'has_terrace' => false,
                'floors_range' => '3-14',
                'completion_at' => now()->subYear(),
                'address' => 'г. Душанбе, ул. Рудаки, 10',
                'latitude' => 38.56000000,
                'longitude' => 68.78000000,
                'moderation_status' => 'approved',
            ]
        );

        // Привяжем несколько фич
        $featureIds = Feature::inRandomOrder()->take(3)->pluck('id')->toArray();
        $nb->features()->sync($featureIds);

        // Блоки
        $blockA = NewBuildingBlock::updateOrCreate(
            ['new_building_id'=>$nb->id, 'name'=>'А'],
            ['floors_from'=>3, 'floors_to'=>14, 'completion_at'=>$nb->completion_at]
        );
        $blockB = NewBuildingBlock::updateOrCreate(
            ['new_building_id'=>$nb->id, 'name'=>'Б'],
            ['floors_from'=>3, 'floors_to'=>12, 'completion_at'=>$nb->completion_at]
        );

        // Квартиры
        DeveloperUnit::updateOrCreate(
            ['new_building_id'=>$nb->id, 'name'=>'2А'],
            [
                'block_id'=>$blockA->id,
                'bedrooms'=>2, 'bathrooms'=>1, 'area'=>74.5, 'floor'=>7,
                'price_per_sqm'=>900.00, 'total_price'=>67050.00,
                'description'=>'Уютная двушка с видом во двор', 'is_available'=>true,
            ]
        );
        DeveloperUnit::updateOrCreate(
            ['new_building_id'=>$nb->id, 'name'=>'3Б'],
            [
                'block_id'=>$blockB->id,
                'bedrooms'=>3, 'bathrooms'=>2, 'area'=>96.0, 'floor'=>10,
                'price_per_sqm'=>950.00, 'total_price'=>91200.00,
                'description'=>'Просторная трёшка', 'is_available'=>true,
            ]
        );
    }
}

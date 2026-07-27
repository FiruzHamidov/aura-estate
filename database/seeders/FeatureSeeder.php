<?php

namespace Database\Seeders;

use App\Models\Feature;
use Illuminate\Database\Seeder;

class FeatureSeeder extends Seeder
{
    public function run(): void
    {
        $features = [
            ['name' => 'Охраняемая территория', 'slug' => 'secured-area', 'icon' => 'shield-check'],
            ['name' => 'Видеонаблюдение', 'slug' => 'video-surveillance', 'icon' => 'video'],
            ['name' => 'Консьерж', 'slug' => 'concierge', 'icon' => 'bell-ring'],
            ['name' => 'Домофон', 'slug' => 'intercom', 'icon' => 'phone-call'],
            ['name' => 'Лифт', 'slug' => 'elevator', 'icon' => 'arrow-up-down'],
            ['name' => 'Грузовой лифт', 'slug' => 'freight-elevator', 'icon' => 'package'],
            ['name' => 'Подземный паркинг', 'slug' => 'underground-parking', 'icon' => 'car'],
            ['name' => 'Наземный паркинг', 'slug' => 'surface-parking', 'icon' => 'circle-parking'],
            ['name' => 'Гараж', 'slug' => 'garage', 'icon' => 'warehouse'],
            ['name' => 'Детская площадка', 'slug' => 'playground', 'icon' => 'baby'],
            ['name' => 'Спортивная площадка', 'slug' => 'sports-ground', 'icon' => 'dumbbell'],
            ['name' => 'Бассейн', 'slug' => 'swimming-pool', 'icon' => 'waves'],
            ['name' => 'Сауна', 'slug' => 'sauna', 'icon' => 'flame'],
            ['name' => 'Тренажёрный зал', 'slug' => 'gym', 'icon' => 'dumbbell'],
            ['name' => 'Сад', 'slug' => 'garden', 'icon' => 'trees'],
            ['name' => 'Терраса', 'slug' => 'terrace', 'icon' => 'panel-top'],
            ['name' => 'Балкон', 'slug' => 'balcony', 'icon' => 'panels-top-left'],
            ['name' => 'Панорамные окна', 'slug' => 'panoramic-windows', 'icon' => 'panels-top-left'],
            ['name' => 'Мебель', 'slug' => 'furniture', 'icon' => 'armchair'],
            ['name' => 'Бытовая техника', 'slug' => 'appliances', 'icon' => 'microwave'],
            ['name' => 'Кондиционер', 'slug' => 'air-conditioning', 'icon' => 'snowflake'],
            ['name' => 'Интернет', 'slug' => 'internet', 'icon' => 'wifi'],
            ['name' => 'Генератор', 'slug' => 'generator', 'icon' => 'zap'],
            ['name' => 'Резервуар для воды', 'slug' => 'water-tank', 'icon' => 'droplets'],
            ['name' => 'Газ', 'slug' => 'gas', 'icon' => 'flame'],
            ['name' => 'Отдельный вход', 'slug' => 'private-entrance', 'icon' => 'door-open'],
            ['name' => 'Подвал', 'slug' => 'basement', 'icon' => 'archive'],
            ['name' => 'Вид на горы', 'slug' => 'mountain-view', 'icon' => 'mountain'],
            ['name' => 'Вид на город', 'slug' => 'city-view', 'icon' => 'building-2'],
            ['name' => 'Рядом школа', 'slug' => 'near-school', 'icon' => 'graduation-cap'],
            ['name' => 'Рядом парк', 'slug' => 'near-park', 'icon' => 'trees'],
            ['name' => 'Рядом общественный транспорт', 'slug' => 'near-public-transport', 'icon' => 'bus-front'],
            ['name' => 'Доступная среда', 'slug' => 'accessible', 'icon' => 'accessibility'],
            ['name' => 'Можно с животными', 'slug' => 'pets-allowed', 'icon' => 'paw-print'],
        ];

        foreach ($features as $feature) {
            $model = Feature::query()->where('slug', $feature['slug'])->first()
                ?? Feature::query()->where('name', $feature['name'])->first()
                ?? new Feature();

            if (!$model->exists) {
                $model->fill($feature)->save();
                continue;
            }

            // Preserve administrator edits on subsequent runs. Only migrate
            // legacy seeded rows to the stable slug and fill a missing icon.
            if ($model->slug !== $feature['slug'] && $model->name === $feature['name']) {
                $model->slug = $feature['slug'];
            }

            if (blank($model->icon)) {
                $model->icon = $feature['icon'];
            }

            if ($model->isDirty()) {
                $model->save();
            }
        }
    }
}

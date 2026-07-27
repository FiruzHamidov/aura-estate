<?php

namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Seeder;

class TagSeeder extends Seeder
{
    public function run(): void
    {
        $tags = [
            ['name' => 'Срочно', 'slug' => 'urgent', 'color' => '#DC2626'],
            ['name' => 'Эксклюзив', 'slug' => 'exclusive', 'color' => '#7C3AED'],
            ['name' => 'Возможен торг', 'slug' => 'negotiable', 'color' => '#D97706'],
            ['name' => 'Новая цена', 'slug' => 'new-price', 'color' => '#2563EB'],
            ['name' => 'Горячее предложение', 'slug' => 'hot-offer', 'color' => '#EA580C'],
            ['name' => 'Для инвестиций', 'slug' => 'investment', 'color' => '#059669'],
            ['name' => 'От собственника', 'slug' => 'from-owner', 'color' => '#0891B2'],
            ['name' => 'Проверено Aura', 'slug' => 'aura-verified', 'color' => '#16A34A'],
            ['name' => 'Новый объект', 'slug' => 'new-listing', 'color' => '#0284C7'],
            ['name' => 'Цена снижена', 'slug' => 'price-reduced', 'color' => '#E11D48'],
        ];

        foreach ($tags as $tag) {
            $model = Tag::query()->where('slug', $tag['slug'])->first()
                ?? Tag::query()->where('name', $tag['name'])->first()
                ?? new Tag();

            if (!$model->exists) {
                $model->fill($tag)->save();
                continue;
            }

            if (blank($model->color)) {
                $model->color = $tag['color'];
            }

            if ($model->isDirty()) {
                $model->save();
            }
        }
    }
}

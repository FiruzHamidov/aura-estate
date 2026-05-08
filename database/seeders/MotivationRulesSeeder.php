<?php

namespace Database\Seeders;

use App\Models\MotivationRule;
use Illuminate\Database\Seeder;

class MotivationRulesSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            [
                'scope' => 'agent',
                'metric_key' => 'sales_count',
                'threshold_value' => 5,
                'reward_type' => 'trip_tashkent',
                'name' => '5 продаж — Ташкент',
                'description' => '5-я личная продажа агента -> поездка в Ташкент.',
                'period_type' => 'month',
                'date_from' => '2026-05-01',
                'date_to' => '2026-05-31',
                'ui_meta' => [
                    'title' => 'Поездка в Ташкент',
                    'short_label' => 'Ташкент',
                ],
                'is_active' => true,
            ],
            [
                'scope' => 'agent',
                'metric_key' => 'sales_count',
                'threshold_value' => 8,
                'reward_type' => 'trip_umra',
                'name' => '8 продаж — Умра',
                'description' => '8-я личная продажа агента -> поездка в Умру.',
                'period_type' => 'month',
                'date_from' => '2026-05-01',
                'date_to' => '2026-05-31',
                'ui_meta' => [
                    'title' => 'Поездка в Умру',
                    'short_label' => 'Умра',
                ],
                'is_active' => true,
            ],
            [
                'scope' => 'company',
                'metric_key' => 'sales_count',
                'threshold_value' => 100,
                'reward_type' => 'company_party',
                'name' => '100 продаж компании — Вечеринка Aura',
                'description' => '100-я продажа всей компании Aura -> вечеринка на даче для всего Aura.',
                'period_type' => 'month',
                'date_from' => '2026-05-01',
                'date_to' => '2026-05-31',
                'ui_meta' => [
                    'title' => 'Вечеринка Aura',
                    'short_label' => 'вечеринки Aura',
                ],
                'is_active' => true,
            ],
        ];

        foreach ($rules as $rule) {
            $existing = MotivationRule::query()
                ->where('scope', $rule['scope'])
                ->where('reward_type', $rule['reward_type'])
                ->where('period_type', $rule['period_type'])
                ->whereDate('date_from', $rule['date_from'])
                ->whereDate('date_to', $rule['date_to'])
                ->first();

            if ($existing) {
                $existing->fill($rule)->save();
            } else {
                MotivationRule::query()->create($rule);
            }
        }
    }
}

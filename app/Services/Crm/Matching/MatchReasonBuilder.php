<?php

namespace App\Services\Crm\Matching;

class MatchReasonBuilder
{
    public function level(int $score): string
    {
        return match (true) {
            $score >= 80 => 'excellent',
            $score >= 60 => 'good',
            $score >= 40 => 'possible',
            default => 'weak',
        };
    }

    public function build(array $reasonCodes): array
    {
        $labels = [
            'offer_type_match' => 'Подходит по типу сделки',
            'location_match' => 'Совпадает локация',
            'district_match' => 'Совпадает район',
            'district_partial' => 'Район частично совпадает',
            'property_type_match' => 'Совпадает тип недвижимости',
            'budget_in_range' => 'Цена входит в бюджет',
            'budget_near_range' => 'Цена близка к бюджету',
            'rooms_match' => 'Подходит по комнатам',
            'rooms_near' => 'Комнатность близка к запросу',
            'area_match' => 'Площадь подходит',
            'area_near' => 'Площадь близка к запросу',
            'repair_match' => 'Совпадает ремонт',
            'mortgage_match' => 'Есть ипотека',
            'invest_developer_bonus' => 'Подходит для инвестиционного запроса',
            'fresh_property' => 'Свежий объект',
            'fresh_need' => 'Свежая потребность клиента',
        ];

        return collect($reasonCodes)
            ->map(fn (string $code) => $labels[$code] ?? null)
            ->filter()
            ->values()
            ->all();
    }
}

<?php

namespace Database\Seeders;

use App\Models\AttendanceHoliday;
use Illuminate\Database\Seeder;

final class AttendanceHolidaySeeder extends Seeder
{
    public function run(): void
    {
        // Official 2026 production calendar of the Ministry of Labour:
        // https://mehnat.tj/tg/news/dt/17409287-0c68-401b-abd1-62845700aa36
        $holidays = [
            ['2026-01-01', 'Новый год', 'official'],
            ['2026-03-08', 'День матери', 'official'],
            ['2026-03-09', 'Перенос выходного за День матери', 'transfer'],
            ['2026-03-20', 'Иди Рамазон', 'official'],
            ['2026-03-21', 'Международный праздник Навруз', 'official'],
            ['2026-03-22', 'Международный праздник Навруз', 'official'],
            ['2026-03-23', 'Международный праздник Навруз', 'official'],
            ['2026-03-24', 'Международный праздник Навруз', 'official'],
            ['2026-03-25', 'Перенос выходного за Навруз', 'transfer'],
            ['2026-03-26', 'Перенос выходного за Навруз', 'transfer'],
            ['2026-05-09', 'День Победы', 'official'],
            ['2026-05-11', 'Перенос выходного за День Победы', 'transfer'],
            ['2026-05-27', 'Иди Курбон', 'official'],
            ['2026-06-27', 'День национального единства', 'official'],
            ['2026-06-29', 'Перенос выходного за День национального единства', 'transfer'],
            ['2026-09-09', 'День государственной независимости Республики Таджикистан', 'official'],
            ['2026-11-06', 'День Конституции Республики Таджикистан', 'official'],
        ];

        foreach ($holidays as [$date, $name, $kind]) {
            AttendanceHoliday::query()->updateOrCreate(
                ['holiday_date' => $date],
                ['name' => $name, 'kind' => $kind, 'note' => 'Производственный календарь Республики Таджикистан на 2026 год']
            );
        }
    }
}

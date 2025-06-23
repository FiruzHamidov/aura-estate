<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Location;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $cities = [
            ['city' => 'Душанбе', 'latitude' => 38.5598, 'longitude' => 68.7870],
            ['city' => 'Худжанд', 'latitude' => 40.2826, 'longitude' => 69.6319],
            ['city' => 'Бохтар', 'latitude' => 37.8364, 'longitude' => 68.7829],
            ['city' => 'Куляб', 'latitude' => 37.9114, 'longitude' => 69.7809],
            ['city' => 'Хорог', 'latitude' => 37.4897, 'longitude' => 71.5530],
            ['city' => 'Исфара', 'latitude' => 39.7990, 'longitude' => 70.6359],
            ['city' => 'Пенджикент', 'latitude' => 39.4950, 'longitude' => 67.6095],
            ['city' => 'Турсунзаде', 'latitude' => 38.5141, 'longitude' => 68.2312],
            ['city' => 'Истаравшан', 'latitude' => 39.9151, 'longitude' => 69.0045],
            ['city' => 'Гиссар', 'latitude' => 38.5250, 'longitude' => 68.5669],
            ['city' => 'Вахдат', 'latitude' => 38.5569, 'longitude' => 69.0211],
            ['city' => 'Яван', 'latitude' => 38.3201, 'longitude' => 69.0332],
            ['city' => 'Нурек', 'latitude' => 38.3875, 'longitude' => 69.3221],
            ['city' => 'Рогун', 'latitude' => 38.5067, 'longitude' => 69.3708],
            ['city' => 'Леваканд', 'latitude' => 37.9560, 'longitude' => 68.9004],
            ['city' => 'Сарбанд (Курган-Тюбе)', 'latitude' => 37.6691, 'longitude' => 68.6822],
            ['city' => 'Дангара', 'latitude' => 38.0911, 'longitude' => 69.3436],
            ['city' => 'Кумсангир (Джайхун)', 'latitude' => 37.2548, 'longitude' => 68.7907],
            ['city' => 'Бешкент (Носири Хусрав)', 'latitude' => 37.2072, 'longitude' => 68.6905],
        ];

        foreach ($cities as $city) {
            Location::create([
                'city' => $city['city'],
                'district' => null,
                'latitude' => $city['latitude'],
                'longitude' => $city['longitude'],
            ]);
        }
    }
}

<?php

namespace Tests\Unit;

use App\Services\Chat\RepairSearchIntent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RepairSearchIntentTest extends TestCase
{
    public static function requests(): array
    {
        return [
            'customer screenshot' => ['podberi 10 samix malenkix kvartir korobok s razrisheniyami na remonta', 'shell', 'allowed'],
            'Russian' => ['Подбери квартиры коробки с разрешением на ремонт', 'shell', 'allowed'],
            'without renovation' => ['Покажи без ремонта', 'shell', null],
            'Latin letters' => ['kvartiry bez remonta', 'shell', null],
            'no finish' => ['Квартиры без отделки', 'shell', null],
            'permission is not renovation' => ['С разрешением на ремонт', null, 'allowed'],
            'no permission' => ['Коробки без разрешения на ремонт', 'shell', 'not_allowed'],
            'Latin no permission' => ['korobki bez razresheniya na remont', 'shell', 'not_allowed'],
            'missing permission' => ['Коробка, разрешения на ремонт нет', 'shell', 'not_allowed'],
            'not shell' => ['Не коробки, с ремонтом', 'renovated', 'any'],
            'not renovated' => ['Не с ремонтом, а коробки', 'shell', null],
            'Latin renovated' => ['s remontom', 'renovated', 'any'],
            'either condition' => ['Коробка или с ремонтом', 'any', 'any'],
            'any condition' => ['Любое состояние', 'any', 'any'],
            'unknown permission accepted' => ['Коробка, разрешение на ремонт неважно', 'shell', 'any'],
            'permission forbidden' => ['Коробки, ремонт не разрешен', 'shell', 'not_allowed'],
            'permission allowed' => ['Коробка, можно делать ремонт', 'shell', 'allowed'],
            'unrelated words' => ['Покажи ремонтные мастерские', null, null],
        ];
    }

    #[DataProvider('requests')]
    public function test_explicit_requirements_are_normalized(string $message, ?string $condition, ?string $permission): void
    {
        $args = (new RepairSearchIntent)->apply(['rooms_min' => 2], [$message]);
        $this->assertSame($condition, $args['repair_condition'] ?? null);
        $this->assertSame($permission, $args['renovation_permission_status'] ?? null);
        $this->assertSame(2, $args['rooms_min']);
    }

    public function test_short_follow_ups_cannot_lose_or_reverse_the_explicit_requirements(): void
    {
        $messages = ['podberi korobok s razrisheniyami na remonta', 'prodaju', 'davay'];
        $intent = new RepairSearchIntent;
        $this->assertSame(['repair_condition' => 'shell', 'renovation_permission_status' => 'allowed'], $intent->apply([
            'repair_condition' => 'renovated', 'renovation_permission_status' => 'any',
        ], $messages));
        $this->assertSame(['repair_condition' => 'renovated', 'renovation_permission_status' => 'any'], $intent->apply([], [...$messages, 'Теперь с ремонтом']));
        $this->assertSame(['repair_condition' => 'renovated', 'renovation_permission_status' => 'any'], $intent->apply([], [...$messages, 'Лучше с дизайнерским ремонтом']));
        $this->assertSame(['repair_condition' => 'any', 'renovation_permission_status' => 'any'], $intent->apply([], [...$messages, 'Любой ремонт']));
        $this->assertSame(['repair_condition' => 'any', 'renovation_permission_status' => 'any'], $intent->apply([], [...$messages, 'Новый поиск, покажи дома']));
    }
}

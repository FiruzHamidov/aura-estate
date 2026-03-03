<?php

namespace App\Console\Commands;

use App\Models\Property;
use Illuminate\Console\Command;

class FindPropertyCandidate extends Command
{
    protected $signature = 'property:find-candidate
        {--phone=750762020 : Телефон агента или владельца}
        {--agent=Искандар : Имя агента}
        {--district=И.Сомони : Район}
        {--address=Альфемо : Адрес или ориентир}
        {--type=Новостройка : Тип недвижимости или текстовый маркер}
        {--document=Шартнома : Тип документа/договора}
        {--repair=Новый : Ремонт/состояние}
        {--rooms=2 : Количество комнат}
        {--area=91 : Площадь}
        {--floor=10 : Этаж}
        {--total-floors=26 : Этажность}
        {--price=120000 : Цена}
        {--limit=20 : Максимум кандидатов}';

    protected $description = 'Find likely property matches by lead text parameters';

    public function handle(): int
    {
        $phone = $this->normalizePhone((string) $this->option('phone'));
        $agent = $this->normalizeText((string) $this->option('agent'));
        $district = $this->normalizeText((string) $this->option('district'));
        $address = $this->normalizeText((string) $this->option('address'));
        $type = $this->normalizeText((string) $this->option('type'));
        $document = $this->normalizeText((string) $this->option('document'));
        $repair = $this->normalizeText((string) $this->option('repair'));
        $rooms = $this->nullableInt($this->option('rooms'));
        $area = $this->nullableFloat($this->option('area'));
        $floor = $this->nullableInt($this->option('floor'));
        $totalFloors = $this->nullableInt($this->option('total-floors'));
        $price = $this->nullableFloat($this->option('price'));
        $limit = max(1, (int) $this->option('limit'));

        $query = Property::query()
            ->with(['type', 'status', 'repairType', 'contractType', 'creator', 'agent'])
            ->leftJoin('users as agents', 'agents.id', '=', 'properties.agent_id')
            ->leftJoin('users as creators', 'creators.id', '=', 'properties.created_by')
            ->leftJoin('repair_types', 'repair_types.id', '=', 'properties.repair_type_id')
            ->leftJoin('contract_types', 'contract_types.id', '=', 'properties.contract_type_id')
            ->leftJoin('property_types', 'property_types.id', '=', 'properties.type_id')
            ->select('properties.*');

        $query->where(function ($q) use ($phone, $agent, $district, $address, $type, $document, $repair, $rooms, $area, $floor, $totalFloors, $price) {
            if ($phone !== '') {
                $q->orWhereRaw($this->normalizedPhoneSql('properties.owner_phone') . ' like ?', ['%' . $phone . '%'])
                    ->orWhereRaw($this->normalizedPhoneSql('agents.phone') . ' like ?', ['%' . $phone . '%'])
                    ->orWhereRaw($this->normalizedPhoneSql('creators.phone') . ' like ?', ['%' . $phone . '%']);
            }

            if ($agent !== '') {
                $q->orWhereRaw('LOWER(COALESCE(agents.name, "")) like ?', ['%' . $agent . '%'])
                    ->orWhereRaw('LOWER(COALESCE(creators.name, "")) like ?', ['%' . $agent . '%']);
            }

            if ($district !== '') {
                $q->orWhereRaw('LOWER(COALESCE(properties.district, "")) like ?', ['%' . $district . '%']);
            }

            if ($address !== '') {
                $q->orWhereRaw('LOWER(COALESCE(properties.address, "")) like ?', ['%' . $address . '%'])
                    ->orWhereRaw('LOWER(COALESCE(properties.landmark, "")) like ?', ['%' . $address . '%'])
                    ->orWhereRaw('LOWER(COALESCE(properties.title, "")) like ?', ['%' . $address . '%'])
                    ->orWhereRaw('LOWER(COALESCE(properties.description, "")) like ?', ['%' . $address . '%']);
            }

            if ($type !== '') {
                $q->orWhereRaw('LOWER(COALESCE(property_types.name, "")) like ?', ['%' . $type . '%'])
                    ->orWhereRaw('LOWER(COALESCE(properties.apartment_type, "")) like ?', ['%' . $type . '%'])
                    ->orWhereRaw('LOWER(COALESCE(properties.title, "")) like ?', ['%' . $type . '%'])
                    ->orWhereRaw('LOWER(COALESCE(properties.description, "")) like ?', ['%' . $type . '%']);
            }

            if ($document !== '') {
                $q->orWhereRaw('LOWER(COALESCE(contract_types.name, "")) like ?', ['%' . $document . '%'])
                    ->orWhereRaw('LOWER(COALESCE(contract_types.slug, "")) like ?', ['%' . $document . '%'])
                    ->orWhereRaw('LOWER(COALESCE(properties.description, "")) like ?', ['%' . $document . '%']);
            }

            if ($repair !== '') {
                $q->orWhereRaw('LOWER(COALESCE(repair_types.name, "")) like ?', ['%' . $repair . '%'])
                    ->orWhereRaw('LOWER(COALESCE(properties.condition, "")) like ?', ['%' . $repair . '%'])
                    ->orWhereRaw('LOWER(COALESCE(properties.description, "")) like ?', ['%' . $repair . '%']);
            }

            if ($rooms !== null) {
                $q->orWhereBetween('properties.rooms', [max(0, $rooms - 1), $rooms + 1]);
            }

            if ($area !== null) {
                $q->orWhereBetween('properties.total_area', [$area - 3, $area + 3]);
            }

            if ($floor !== null) {
                $q->orWhere('properties.floor', $floor);
            }

            if ($totalFloors !== null) {
                $q->orWhereBetween('properties.total_floors', [max(1, $totalFloors - 1), $totalFloors + 1]);
            }

            if ($price !== null) {
                $delta = max(5000, $price * 0.07);
                $q->orWhereBetween('properties.price', [$price - $delta, $price + $delta]);
            }
        });

        $candidates = $query
            ->orderByDesc('properties.created_at')
            ->limit(200)
            ->get()
            ->map(function (Property $property) use ($phone, $agent, $district, $address, $type, $document, $repair, $rooms, $area, $floor, $totalFloors, $price) {
                $score = 0;
                $signals = [];

                $agentNames = collect([
                    $property->agent?->name,
                    $property->creator?->name,
                ])->filter()->map(fn ($value) => $this->normalizeText((string) $value))->all();

                $textPool = collect([
                    $property->title,
                    $property->description,
                    $property->district,
                    $property->address,
                    $property->landmark,
                    $property->apartment_type,
                    $property->condition,
                    $property->repairType?->name,
                    $property->contractType?->name,
                    $property->type?->name,
                ])->filter()->map(fn ($value) => $this->normalizeText((string) $value))->implode(' | ');

                if ($phone !== '') {
                    $phones = [
                        $this->normalizePhone((string) $property->owner_phone),
                        $this->normalizePhone((string) ($property->agent?->phone ?? '')),
                        $this->normalizePhone((string) ($property->creator?->phone ?? '')),
                    ];

                    if (collect($phones)->filter()->contains(fn ($candidate) => str_contains($candidate, $phone) || str_contains($phone, $candidate))) {
                        $score += 40;
                        $signals[] = 'phone';
                    }
                }

                if ($agent !== '' && collect($agentNames)->contains(fn ($name) => str_contains($name, $agent))) {
                    $score += 20;
                    $signals[] = 'agent';
                }

                if ($district !== '' && str_contains($this->normalizeText((string) $property->district), $district)) {
                    $score += 12;
                    $signals[] = 'district';
                }

                if ($address !== '' && str_contains($textPool, $address)) {
                    $score += 14;
                    $signals[] = 'address';
                }

                if ($type !== '' && str_contains($textPool, $type)) {
                    $score += 8;
                    $signals[] = 'type';
                }

                if ($document !== '' && str_contains($textPool, $document)) {
                    $score += 7;
                    $signals[] = 'document';
                }

                if ($repair !== '' && str_contains($textPool, $repair)) {
                    $score += 6;
                    $signals[] = 'repair';
                }

                if ($rooms !== null && $property->rooms !== null) {
                    $delta = abs((int) $property->rooms - $rooms);
                    if ($delta === 0) {
                        $score += 10;
                        $signals[] = 'rooms';
                    } elseif ($delta === 1) {
                        $score += 4;
                        $signals[] = 'rooms~';
                    }
                }

                if ($area !== null && $property->total_area !== null) {
                    $delta = abs((float) $property->total_area - $area);
                    if ($delta <= 1.5) {
                        $score += 12;
                        $signals[] = 'area';
                    } elseif ($delta <= 4) {
                        $score += 5;
                        $signals[] = 'area~';
                    }
                }

                if ($floor !== null && $property->floor !== null && (int) $property->floor === $floor) {
                    $score += 8;
                    $signals[] = 'floor';
                }

                if ($totalFloors !== null && $property->total_floors !== null) {
                    $delta = abs((int) $property->total_floors - $totalFloors);
                    if ($delta === 0) {
                        $score += 5;
                        $signals[] = 'floors';
                    } elseif ($delta === 1) {
                        $score += 2;
                        $signals[] = 'floors~';
                    }
                }

                if ($price !== null && $property->price !== null) {
                    $delta = abs((float) $property->price - $price);
                    if ($delta <= 2000) {
                        $score += 12;
                        $signals[] = 'price';
                    } elseif ($delta <= max(5000, $price * 0.07)) {
                        $score += 6;
                        $signals[] = 'price~';
                    }
                }

                return [
                    'id' => $property->id,
                    'score' => $score,
                    'status' => $property->moderation_status,
                    'price' => $property->price,
                    'area' => $property->total_area,
                    'floor' => $property->floor,
                    'floors' => $property->total_floors,
                    'rooms' => $property->rooms,
                    'district' => $property->district,
                    'address' => $property->address,
                    'agent' => $property->agent?->name ?? $property->creator?->name,
                    'phone' => $property->agent?->phone ?? $property->creator?->phone ?? $property->owner_phone,
                    'signals' => implode(',', $signals),
                    'created_at' => optional($property->created_at)->toDateTimeString(),
                ];
            })
            ->filter(fn (array $candidate) => $candidate['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->values();

        if ($candidates->isEmpty()) {
            $this->warn('Совпадений не найдено.');
            $this->line('Попробуйте ослабить параметры: --address, --repair, --document или увеличить --limit.');

            return self::SUCCESS;
        }

        $this->info('Найдены кандидаты: ' . $candidates->count());
        $this->table(
            ['ID', 'Score', 'Status', 'Price', 'Area', 'Floor', 'Rooms', 'District', 'Agent', 'Phone', 'Signals'],
            $candidates->map(fn (array $candidate) => [
                $candidate['id'],
                $candidate['score'],
                $candidate['status'],
                $candidate['price'],
                $candidate['area'],
                ($candidate['floor'] ?? '-') . '/' . ($candidate['floors'] ?? '-'),
                $candidate['rooms'],
                $candidate['district'],
                $candidate['agent'],
                $candidate['phone'],
                $candidate['signals'],
            ])->all()
        );

        foreach ($candidates as $candidate) {
            $this->line(sprintf(
                '#%d | %s | %s | %s | created_at=%s',
                $candidate['id'],
                $candidate['address'] ?: '-',
                $candidate['district'] ?: '-',
                $candidate['status'],
                $candidate['created_at'] ?: '-'
            ));
        }

        return self::SUCCESS;
    }

    private function normalizePhone(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function normalizeText(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = str_replace(['ё'], ['е'], $value);

        return preg_replace('/\s+/u', ' ', $value) ?? '';
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function normalizedPhoneSql(string $column): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$column}, ''), '+', ''), '-', ''), ' ', ''), '(', ''), ')', ''), '.', '')";
    }
}

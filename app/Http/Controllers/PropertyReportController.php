<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PropertyReportController extends Controller
{
    // --- Helper: нормализация входа (array или "1,2,3")
    private function toArray($value): array
    {
        if ($value === null || $value === '') return [];
        if (is_array($value)) return array_values(array_filter($value, fn($v) => $v !== '' && $v !== null));
        return array_values(array_filter(array_map('trim', explode(',', $value)), fn($v) => $v !== ''));
    }

    // --- Базовый фильтр, общий для всех отчётов
    private function applyCommonFilters(Request $request, $query)
    {
        // Диапазон дат и поле даты
        $dateField = $request->input('date_field', 'created_at'); // created_at | updated_at
        if (!in_array($dateField, ['created_at','updated_at'], true)) {
            $dateField = 'created_at';
        }

        $dateFrom = $request->input('date_from'); // '2025-01-01'
        $dateTo   = $request->input('date_to');   // '2025-01-31'
        if ($dateFrom) $query->whereDate($dateField, '>=', $dateFrom);
        if ($dateTo)   $query->whereDate($dateField, '<=', $dateTo);

        // Мультиселекты
        $multiFields = [
            'type_id','status_id','location_id','repair_type_id',
            'currency','offer_type','listing_type','contract_type_id',
            'created_by','agent_id','moderation_status','district'
        ];
        foreach ($multiFields as $f) {
            if ($request->has($f)) {
                $vals = $this->toArray($request->input($f));
                if (!empty($vals)) $query->whereIn($f, $vals);
            }
        }

        // Диапазоны
        foreach ([
                     'price' => 'price',
                     'rooms' => 'rooms',
                     'total_area' => 'total_area',
                     'living_area' => 'living_area',
                     'floor' => 'floor',
                     'total_floors' => 'total_floors',
                     'year_built' => 'year_built',
                 ] as $param => $col) {
            $from = $request->input($param.'From');
            $to   = $request->input($param.'To');
            if ($from !== null && $from !== '') $query->where($col, '>=', $from);
            if ($to   !== null && $to   !== '') $query->where($col, '<=', $to);
        }

        return [$query, $dateField];
    }

    // --- 2.1 Сводка
    public function summary(Request $request)
    {
        $base = Property::query();
        [$q] = $this->applyCommonFilters($request, $base);

        $total = (clone $q)->count();
        $byStatus = (clone $q)->select('moderation_status', DB::raw('COUNT(*) as cnt'))
            ->groupBy('moderation_status')->get();

        $byOffer = (clone $q)->select('offer_type', DB::raw('COUNT(*) as cnt'))
            ->groupBy('offer_type')->get();

        $avgPrice = (clone $q)->avg('price');
        $avgArea  = (clone $q)->avg('total_area');

        return response()->json([
            'total' => $total,
            'by_status' => $byStatus,
            'by_offer_type' => $byOffer,
            'avg_price' => round((float)$avgPrice, 2),
            'avg_total_area' => round((float)$avgArea, 2),
        ]);
    }

    // --- 2.2 Эффективность менеджеров/агентов
    // Группировка по agent_id (или created_by, если agent_id не заполнен)
    public function managerEfficiency(Request $request)
    {
        $groupBy = $request->input('group_by', 'agent_id'); // 'agent_id' | 'created_by'
        if (!in_array($groupBy, ['agent_id','created_by'], true)) $groupBy = 'agent_id';

        $base = Property::query();
        [$q, $dateField] = $this->applyCommonFilters($request, $base);

        $data = (clone $q)
            ->select([
                $groupBy,
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN moderation_status = 'approved' THEN 1 ELSE 0 END) as approved"),
                DB::raw("SUM(CASE WHEN moderation_status IN ('sold','rented') THEN 1 ELSE 0 END) as closed"),
                DB::raw("AVG(NULLIF(price,0)) as avg_price")
            ])
            ->groupBy($groupBy)
            ->get();

        // Подтянем имена пользователей
        $userIds = $data->pluck($groupBy)->filter()->unique()->values();
        $users = User::whereIn('id', $userIds)->get(['id','name','email'])->keyBy('id');

        // Пример метрики конверсии
        $result = $data->map(function ($row) use ($users, $groupBy) {
            $total = (int)$row->total;
            $closed = (int)$row->closed;
            return [
                'id' => $row->$groupBy,
                'name' => $users[$row->$groupBy]->name ?? '—',
                'email' => $users[$row->$groupBy]->email ?? null,
                'total' => $total,
                'approved' => (int)$row->approved,
                'closed' => $closed,
                'close_rate' => $total ? round($closed / $total * 100, 2) : 0,
                'avg_price' => round((float)$row->avg_price, 2),
            ];
        });

        return response()->json($result);
    }

    // --- 2.3 Распределение по статусам
    public function byStatus(Request $request)
    {
        $base = Property::query();
        [$q] = $this->applyCommonFilters($request, $base);

        $rows = (clone $q)->select('moderation_status', DB::raw('COUNT(*) as cnt'))
            ->groupBy('moderation_status')->orderByDesc('cnt')->get();

        return response()->json($rows);
    }

    // --- 2.4 По типам
    public function byType(Request $request)
    {
        $base = Property::query()->with('type:id,name');
        [$q] = $this->applyCommonFilters($request, $base);

        $rows = (clone $q)->select('type_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('type_id')->orderByDesc('cnt')->get();

        // Добавим названия типов
        $rows->transform(function($r){
            $r->type_name = optional($r->type)->name ?? null;
            unset($r->type);
            return $r;
        });

        return response()->json($rows);
    }

    // --- 2.5 По локациям
    public function byLocation(Request $request)
    {
        $base = Property::query()->with('location:id,name');
        [$q] = $this->applyCommonFilters($request, $base);

        $rows = (clone $q)->select('location_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('location_id')->orderByDesc('cnt')->get();

        $rows->transform(function($r){
            $r->location_name = optional($r->location)->name ?? null;
            unset($r->location);
            return $r;
        });

        return response()->json($rows);
    }

    // --- 2.6 Тайм-серия (день/неделя/месяц)
    public function timeSeries(Request $request)
    {
        $interval = $request->input('interval', 'day'); // day|week|month
        if (!in_array($interval, ['day','week','month'], true)) $interval = 'day';

        $base = Property::query();
        [$q, $dateField] = $this->applyCommonFilters($request, $base);

        $format = match ($interval) {
            'month' => '%Y-%m',
            'week'  => '%x-W%v',
            default => '%Y-%m-%d',
        };

        $rows = (clone $q)
            ->select(
                DB::raw("DATE_FORMAT($dateField, '$format') as bucket"),
                DB::raw("COUNT(*) as total"),
                DB::raw("SUM(CASE WHEN moderation_status IN ('sold','rented') THEN 1 ELSE 0 END) as closed")
            )
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        return response()->json($rows);
    }

    // --- 2.7 Вёдра цен
    public function priceBuckets(Request $request)
    {
        // по умолчанию 8 «корзин»
        $buckets = max(1, (int)$request->input('buckets', 8));

        $base = Property::query()->whereNotNull('price')->where('price','>',0);
        [$q] = $this->applyCommonFilters($request, $base);

        $min = (clone $q)->min('price');
        $max = (clone $q)->max('price');
        if ($min === null || $max === null || $min == $max) {
            return response()->json([
                'range' => [$min, $max],
                'buckets' => [],
            ]);
        }

        $size = ($max - $min) / $buckets;
        $edges = [];
        for ($i=0;$i<=$buckets;$i++) $edges[] = $min + $size * $i;

        // Считаем «на лету»
        $result = [];
        for ($i=0;$i<$buckets;$i++) {
            $from = $edges[$i];
            $to   = $edges[$i+1] - ($i+1 == $buckets ? 0 : 0.000001);
            $cnt = (clone $q)->whereBetween('price', [$from, $to])->count();
            $result[] = [
                'bucket' => $i+1,
                'from' => round($from,2),
                'to'   => round($edges[$i+1],2),
                'count'=> $cnt,
            ];
        }

        return response()->json([
            'range' => [round($min,2), round($max,2)],
            'buckets' => $result,
        ]);
    }

    // --- 2.8 Гистограмма по комнатам
    public function roomsHistogram(Request $request)
    {
        $base = Property::query()->whereNotNull('rooms');
        [$q] = $this->applyCommonFilters($request, $base);

        $rows = (clone $q)
            ->select('rooms', DB::raw('COUNT(*) as cnt'))
            ->groupBy('rooms')
            ->orderBy('rooms')
            ->get();

        return response()->json($rows);
    }

    // --- 2.9 Лидерборд агентов (по закрытым сделкам)
    public function agentsLeaderboard(Request $request)
    {
        $limit = (int)$request->input('limit', 10);

        $base = Property::query();
        [$q] = $this->applyCommonFilters($request, $base);

        $rows = (clone $q)
            ->select('agent_id',
                DB::raw("SUM(CASE WHEN moderation_status IN ('sold','rented') THEN 1 ELSE 0 END) as closed"),
                DB::raw('COUNT(*) as total'),
                DB::raw('AVG(NULLIF(price,0)) as avg_price')
            )
            ->groupBy('agent_id')
            ->orderByDesc('closed')
            ->limit($limit)
            ->get();

        // имена агентов
        $ids = $rows->pluck('agent_id')->filter()->unique();
        $users = User::whereIn('id', $ids)->get(['id','name'])->keyBy('id');

        $rows->transform(function($r) use ($users){
            $r->agent_name = $users[$r->agent_id]->name ?? '—';
            $r->avg_price = round((float)$r->avg_price, 2);
            return $r;
        });

        return response()->json($rows);
    }

    // --- 2.10 Воронка конверсии (сколько объектов на каждом этапе)
    public function conversionFunnel(Request $request)
    {
        $base = Property::query();
        [$q] = $this->applyCommonFilters($request, $base);

        $funnel = (clone $q)->select(
            DB::raw("SUM(CASE WHEN moderation_status = 'draft' THEN 1 ELSE 0 END) as draft"),
            DB::raw("SUM(CASE WHEN moderation_status = 'pending' THEN 1 ELSE 0 END) as pending"),
            DB::raw("SUM(CASE WHEN moderation_status = 'approved' THEN 1 ELSE 0 END) as approved"),
            DB::raw("SUM(CASE WHEN moderation_status = 'rejected' THEN 1 ELSE 0 END) as rejected"),
            DB::raw("SUM(CASE WHEN moderation_status IN ('sold','rented') THEN 1 ELSE 0 END) as closed")
        )->first();

        return response()->json($funnel);
    }
}

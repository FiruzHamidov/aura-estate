<?php

namespace App\Services\AgentWorkspace;

use App\Models\User;
use App\Services\KpiModuleService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AgentDashboard
{
    public function __construct(private ListingPerformance $listings, private RatingAccess $access) {}

    public function get(User $actor, string $month, string $kind): array
    {
        abort_unless($actor->hasPermission('kpi.reports.view'), 403);
        $overview = $actor->hasPermission('agent_rating.scope.all') && ! $actor->usesAssignedGroupScope();
        $start = CarbonImmutable::parse($month.'-01', 'Asia/Dushanbe');
        $next = $start->addMonth();
        $now = CarbonImmutable::now('Asia/Dushanbe');
        $end = $now->lessThan($next) ? $now : $next;
        $lastDay = $end->equalTo($next) ? $next->subDay() : $end;
        $days = [];
        for ($day = $start; $day->lessThanOrEqualTo($lastDay); $day = $day->addDay()) {
            $days[] = $day->format('Y-m-d');
        }
        $definitions = [
            'revenue' => ['label' => 'Комиссии участников', 'unit' => 'TJS', 'method' => 'Комиссии агентов из списка участников в модерации проданных и сданных объявлений. Период определяется датой закрытия; USD переводится по сохранённому курсу объявления.'],
            'deals' => ['label' => $kind === 'rental' ? 'Арендные сделки' : ($kind === 'sale' ? 'Продажи' : 'Закрытые сделки'), 'unit' => 'count', 'method' => 'Проданные и сданные объявления по дате закрытия. Каждый объект учитывается один раз независимо от числа участников и заполнения комиссии.'],
            'deposits' => ['label' => 'Объекты с задатком', 'unit' => 'count', 'method' => 'Объекты с датой получения задатка в периоде, где сотрудник указан получателем; исключены отменённые стадии. Один объект учитывается один раз.'],
            'shows' => ['label' => 'Проведённые показы', 'unit' => 'count', 'method' => 'Показы со статусом «Проведён», по дате начала; участие агентом или соагентом не дублирует один показ.'],
            'objects' => ['label' => 'Новые объекты', 'unit' => 'count', 'method' => 'Созданные за период неудалённые объекты, закреплённые за агентом. Это события создания, а не остаток объектов в продаже.'],
            'contracts' => ['label' => 'Подписанные эксклюзивы', 'unit' => 'count', 'method' => 'Эксклюзивные договоры объектов агента с датой подписания в периоде. Это подписанные договоры, а не активные на сегодня.'],
        ];
        $metrics = [];
        foreach ($definitions as $key => $definition) {
            $metrics[$key] = $definition + ['key' => $key, 'value' => 0, 'status' => 'complete', 'comparison' => null, 'series' => array_fill_keys($days, 0)];
        }
        $add = function (string $metric, string $date, int $value = 1) use (&$metrics) {
            if (array_key_exists($date, $metrics[$metric]['series'])) {
                $metrics[$metric]['series'][$date] += $value;
                $metrics[$metric]['value'] += $value;
            }
        };
        foreach ($this->listings->forMonth($month) as $closing) {
            if ($kind !== 'all' && $closing['kind'] !== $kind) {
                continue;
            }
            $participants = array_filter($closing['participants'], fn ($p) => $p['agent_id'] &&
                $this->access->matches($actor, $p, $overview ? 'all' : 'own'));
            if (! $participants) {
                continue;
            }
            if (! $closing['closed_at']) {
                $metrics['revenue']['status'] = $metrics['deals']['status'] = 'partial';

                continue;
            }
            $date = CarbonImmutable::parse($closing['closed_at'], 'UTC')->setTimezone('Asia/Dushanbe')->format('Y-m-d');
            $add('deals', $date);
            foreach ($participants as $participant) {
                if ($participant['credit_minor'] === null) {
                    $metrics['revenue']['status'] = 'partial';
                } else {
                    $add('revenue', $date, $participant['credit_minor']);
                }
            }
        }
        $storageTz = config('app.timezone', 'Asia/Dushanbe');
        $fromStorage = $start->setTimezone($storageTz)->toDateTimeString();
        $toStorage = $end->setTimezone($storageTz)->toDateTimeString();
        $dateOf = fn ($value) => CarbonImmutable::parse($value, $storageTz)->setTimezone('Asia/Dushanbe')->format('Y-m-d');
        $properties = DB::table('properties');
        if (! $overview) {
            $properties->whereRaw(app(\App\Services\SalesAttributionService::class)->listingAgentExpression().' = ?', [$actor->id]);
        }
        if (Schema::hasColumn('properties', 'deleted_at')) {
            $properties->whereNull('deleted_at');
        }
        if ($kind !== 'all') {
            $properties->where('offer_type', $kind === 'rental' ? 'rent' : 'sale');
        }
        \App\Support\BranchVisibility::records($properties, 'properties');
        if (Schema::hasColumn('properties', 'created_at')) {
            foreach ((clone $properties)->where('created_at', '>=', $fromStorage)->where('created_at', '<', $toStorage)->pluck('created_at') as $date) {
                $add('objects', $dateOf($date));
            }
        } else {
            $metrics['objects']['status'] = 'unavailable';
        }
        if (Schema::hasColumn('properties', 'contract_signed_at')) {
            foreach ((clone $properties)->where('contract_type_id', 2)->where('contract_signed_at', '>=', $fromStorage)->where('contract_signed_at', '<', $toStorage)->pluck('contract_signed_at') as $date) {
                $add('contracts', $dateOf($date));
            }
            if ((clone $properties)->where('contract_type_id', 2)->whereNull('contract_signed_at')->exists()) {
                $metrics['contracts']['status'] = 'partial';
            }
        } else {
            $metrics['contracts']['status'] = 'unavailable';
        }
        if (Schema::hasColumn('properties', 'deposit_received_at') && Schema::hasColumn('properties', 'deposit_user_id')) {
            $deposits = DB::table('properties')->when(! $overview, fn ($q) => $q->where('deposit_user_id', $actor->id))->whereIn('moderation_status', ['deposit', 'sold', 'rented']);
            if (Schema::hasColumn('properties', 'deleted_at')) {
                $deposits->whereNull('deleted_at');
            }
            if ($kind !== 'all') {
                $deposits->where('offer_type', $kind === 'rental' ? 'rent' : 'sale');
            }
            \App\Support\BranchVisibility::records($deposits, 'properties');
            $dateField = Schema::hasColumn('properties', 'deposit_taken_at') ? 'COALESCE(deposit_received_at, deposit_taken_at)' : 'deposit_received_at';
            foreach ((clone $deposits)->whereRaw($dateField.' >= ? AND '.$dateField.' < ?', [$fromStorage, $toStorage])->selectRaw($dateField.' as deposit_date')->get() as $d) {
                $add('deposits', $dateOf($d->deposit_date));
            }
            if ((clone $deposits)->whereRaw($dateField.' IS NULL')->exists()) {
                $metrics['deposits']['status'] = 'partial';
            }
        } else {
            $metrics['deposits']['status'] = 'unavailable';
        }
        if (Schema::hasTable('bookings')) {
            $bookings = DB::table('bookings')->where('status', 'completed')->where('start_time', '>=', $fromStorage)->where('start_time', '<', $toStorage)
                ->when(! $overview, fn ($q) => $q->where(fn ($q) => $q->where('agent_id', $actor->id)->orWhere('co_agent_id', $actor->id)));
            if ($kind !== 'all') {
                $bookings->whereIn('property_id', DB::table('properties')->where('offer_type', $kind === 'rental' ? 'rent' : 'sale')->select('id'));
            }
            \App\Support\BranchVisibility::records($bookings, 'bookings');
            foreach ($bookings->pluck('start_time') as $date) {
                $add('shows', $dateOf($date));
            }
        } else {
            $metrics['shows']['status'] = 'unavailable';
        }
        $plans = ! $overview && $actor->hasPermission('kpi.plans.user.view') ? app(KpiModuleService::class)->effectivePlanForUser($actor->id, Carbon::parse($month.'-01', 'Asia/Dushanbe'))['items'] : collect();
        $goals = [];
        foreach ($overview ? [] : ['deposits', 'shows', 'contracts'] as $key) {
            $plan = collect($plans)->first(fn ($p) => $p['metric_key'] === $key && ($p['plan_period'] ?? 'month') === 'month');
            $target = $plan ? (float) $plan['monthly_plan'] : null;
            $goals[] = ['key' => $key, 'label' => $metrics[$key]['label'], 'fact' => $metrics[$key]['status'] === 'unavailable' ? null : $metrics[$key]['value'], 'target' => $target,
                'remaining' => $target !== null ? max(0, $target - $metrics[$key]['value']) : null];
        }
        foreach ($metrics as &$metric) {
            if ($metric['status'] === 'unavailable') {
                $metric['value'] = null;
            }
            $metric['series'] = array_map(fn ($date, $value) => ['date' => $date, 'value' => $metric['status'] === 'unavailable' ? null : $value], array_keys($metric['series']), $metric['series']);
        }

        return ['month' => $month, 'kind' => $kind, 'timezone' => 'Asia/Dushanbe', 'currency' => 'TJS', 'as_of' => $now->utc()->toIso8601String(),
            'scope' => $overview ? 'all' : 'own', 'scope_label' => $overview ? 'Вся компания' : 'Ваши результаты', 'data_source' => 'listing_participants', 'agent_id' => $actor->id, 'metrics' => array_values($metrics), 'goals' => $goals, 'can_view_rating' => $actor->hasPermission('agent_rating.view')];
    }
}

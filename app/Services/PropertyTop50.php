<?php

namespace App\Services;

use App\Models\Property;
use App\Support\BranchVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Read-only rankings of available properties within the caller's existing catalog scope. */
final class PropertyTop50
{
    public function build(Request $request, Builder $query): array
    {
        $today = CarbonImmutable::now('Asia/Dushanbe');
        $v = $request->validate([
            'tab' => ['sometimes', Rule::in(['shown', 'viewed', 'urgent'])],
            'period' => ['sometimes', Rule::in(['today', 'week', 'month', 'all', 'custom'])],
            'date_from' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString()],
            'date_to' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:date_from', 'before_or_equal:'.$today->toDateString()],
            'offer_type' => ['sometimes', Rule::in(['all', 'sale', 'rent'])],
        ]);
        $tab = $v['tab'] ?? 'shown';
        $period = $v['period'] ?? 'month';
        $from = match ($period) {
            'today' => $today->startOfDay(),
            'week' => $today->startOfWeek(\Carbon\Carbon::MONDAY),
            'month' => $today->startOfMonth(),
            'custom' => CarbonImmutable::parse($v['date_from'], 'Asia/Dushanbe')->startOfDay(),
            default => null,
        };
        $to = $period === 'custom' ? CarbonImmutable::parse($v['date_to'], 'Asia/Dushanbe')->endOfDay()->min($today) : $today;
        if ($from && $from->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['date_from' => 'Выберите период не длиннее 366 дней.']);
        }
        // Timestamps are stored in the application timezone; daily analytics use Dushanbe dates.
        $dbFrom = $from?->timezone(config('app.timezone'))->toDateTimeString();
        $dbTo = $to->timezone(config('app.timezone'))->toDateTimeString();
        $now = $today->timezone(config('app.timezone'))->toDateTimeString();
        $query->publicSearchable()->select('properties.*');
        BranchVisibility::records($query);
        $query->where(fn ($q) => $q->whereNull('properties.moderation_status')->orWhereNotIn('properties.moderation_status', [...Property::CLOSED_MODERATION_STATUSES, 'pending', 'deposit']))
            ->where('properties.deal_status', 'available')
            ->where(fn ($q) => $q->whereNull('properties.publication_expires_at')->orWhere('properties.publication_expires_at', '>', $now))
            ->whereNull('properties.duplicate_of_property_id');
        if (($v['offer_type'] ?? 'all') !== 'all') {
            $query->where('properties.offer_type', $v['offer_type']);
        }

        $coverage = DB::table('property_analytics_coverage')->where('id', 1)->value('started_at');
        $quality = 'complete';
        if ($tab === 'shown') {
            $counts = DB::table('bookings')->select('property_id')->selectRaw('COUNT(*) as metric_count')
                ->where('status', 'completed')->where('start_time', '<=', $dbTo)
                ->when($dbFrom, fn ($q) => $q->where('start_time', '>=', $dbFrom))->groupBy('property_id');
            $query->joinSub($counts, 'rank_metric', 'rank_metric.property_id', '=', 'properties.id')
                ->addSelect('rank_metric.metric_count')->orderByDesc('rank_metric.metric_count');
        } elseif ($tab === 'viewed') {
            if ($from) {
                $counts = DB::table('property_analytics_daily')->select('property_id')->selectRaw('SUM(views_count) as metric_count')
                    ->whereBetween('day', [$from->toDateString(), $to->toDateString()])->groupBy('property_id');
                $query->joinSub($counts, 'rank_metric', 'rank_metric.property_id', '=', 'properties.id')
                    ->where('rank_metric.metric_count', '>', 0)->addSelect('rank_metric.metric_count')->orderByDesc('rank_metric.metric_count');
                $quality = ! $coverage ? 'unknown' : ($from->lt(CarbonImmutable::parse($coverage)) ? 'partial' : 'complete');
            } else {
                $query->where('properties.views_count', '>', 0)->selectRaw('properties.views_count as metric_count')->orderByDesc('properties.views_count');
            }
        } else {
            $promotions = DB::table('property_promotions')->select('property_id')
                ->selectRaw('MAX(starts_at) as urgent_since')->selectRaw('MAX(ends_at) as urgent_until')
                ->where('type', 'urgent')->where('status', 'active')->where('starts_at', '<=', $now)->where('ends_at', '>', $now)
                ->when($dbFrom, fn ($q) => $q->where('starts_at', '>=', $dbFrom))->where('starts_at', '<=', $dbTo)->groupBy('property_id');
            $query->joinSub($promotions, 'rank_metric', 'rank_metric.property_id', '=', 'properties.id')
                ->addSelect('rank_metric.urgent_since', 'rank_metric.urgent_until')->orderByDesc('rank_metric.urgent_since');
        }
        $presenter = app(PublicPropertyPresenter::class);
        $properties = $query->with($presenter->relations())->orderBy('properties.id')->limit(50)->get();
        $branches = DB::table('branches')->whereIn('id', $properties->pluck('branch_id')->filter()->unique())->pluck('name', 'id');
        $rows = $properties->values()->map(function (Property $p, int $i) use ($presenter, $branches, $tab): array {
            $card = $presenter->present($p);
            // A property card is a public-safe projection, never a CRM record or client contact.
            $card['office_name'] = $branches[$p->branch_id] ?? null;

            return ['position' => $i + 1, 'metric_count' => $tab === 'urgent' ? null : (int) $p->metric_count,
                'urgent_since' => $p->urgent_since, 'urgent_until' => $p->urgent_until, 'property' => $card];
        })->all();

        return ['data' => $rows, 'limit' => 50, 'count' => count($rows), 'as_of' => $today->toIso8601String(),
            'filters' => ['tab' => $tab, 'period' => $period, 'date_from' => $from?->toDateString(), 'date_to' => $from ? $to->toDateString() : null,
                'offer_type' => $v['offer_type'] ?? 'all', 'timezone' => 'Asia/Dushanbe'],
            'data_quality' => $quality, 'views_tracking_started_at' => $coverage,
            'rules' => [
                'shown' => 'Только проведённые показы по дате встречи. Один показ считается один раз, независимо от соагентов. При равенстве — по ID объекта.',
                'viewed' => 'Просмотры объявлений, включая сотрудников. Повтор с одного IP и браузера учитывается один раз в сутки. За всё время — накопленный счётчик; за период — ежедневная статистика. При равенстве — по ID объекта.',
                'urgent' => 'Только действующие срочные продвижения. Период относится к дате их начала; сначала более новые. Срочность не означает высокую ликвидность.',
            ],
        ];
    }
}

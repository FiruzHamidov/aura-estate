<?php

namespace App\Http\Controllers;

use App\Jobs\RecalculatePropertyLiquidity;
use App\Models\Property;
use App\Models\PropertyLiquiditySnapshot;
use App\Models\PropertySocialPromotion;
use App\Models\User;
use App\Services\PropertyLiquidity\PropertyLiquidityAccess;
use App\Services\PropertyLiquidity\PropertyLiquidityCalculator;
use App\Services\PropertyModeration\PropertyModerationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PropertyLiquidityController extends Controller
{
    public function __construct(
        private readonly PropertyLiquidityAccess $access,
        private readonly PropertyLiquidityCalculator $calculator,
        private readonly PropertyModerationService $moderation,
    ) {}

    public function show(Request $request, Property $property)
    {
        /** @var User|null $user */
        $user = auth('sanctum')->user();

        if (! $user || $user->hasRole('client') || ! $this->access->isInternal($user)) {
            $this->moderation->publicOrFail($property, null);
            return response()->json(['data' => [
                'public_price_badge' => $property->publicPriceBadge(),
                'calculated_at' => $property->publicPriceBadge() ? $property->liquidity_calculated_at?->toJSON() : null,
            ]]);
        }

        if ($user->hasRole('rop')) app(\App\Support\RopGroupAccess::class)->ensureVisible($user, $property);
        abort_unless($this->access->canView($user, $property), 403, 'Доступ запрещён');
        $property->loadMissing(['photos', 'type', 'agent', 'creator', 'latestSocialPromotion']);
        $snapshot = $user->hasRole('rop')
            ? $this->calculator->previewForRop($property, $user)
            : ($property->liquidity_score !== null ? $property->latestLiquiditySnapshot()->first() : null);
        $eligibility = $this->calculator->eligibility($property);

        return response()->json(['data' => $snapshot ? $this->serialize($property, $snapshot, true) : [
            'status' => $eligibility['status'] === 'eligible' ? 'not_calculated' : $eligibility['status'],
            'reasons' => $eligibility['reasons'] === [] ? ['Недостаточно сопоставимых объектов для расчета.'] : $eligibility['reasons'],
        ]]);
    }

    public function feed(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->access->isInternal($user), 403, 'Доступ запрещён');

        $validated = $request->validate([
            'purpose' => ['sometimes', Rule::in(['portfolio', 'social'])],
            'category' => ['sometimes', Rule::in(['very_high', 'high', 'medium', 'low', 'very_low'])],
            'price_position' => ['sometimes', Rule::in(['below_market', 'at_market', 'above_market'])],
            'district_id' => ['sometimes', 'integer'],
            'agent_id' => ['sometimes', 'integer'],
            'promotion_eligibility' => ['sometimes', Rule::in(['eligible', 'content_needed', 'not_eligible'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $purpose = $validated['purpose'] ?? 'portfolio';
        $query = $this->scopedActiveQuery($user)
            ->with(['photos', 'type:id,name,slug', 'agent:id,name,branch_id,branch_group_id', 'creator:id,name,branch_id,branch_group_id', 'latestSocialPromotion']);
        $query->select('properties.*');
        if ($user->hasRole('rop')) {
            $query->addSelect('scoped_liquidity.snapshot as rop_liquidity_snapshot');
        } else {
            $query->with('latestLiquiditySnapshot')->whereHas('latestLiquiditySnapshot');
        }

        if ($purpose === 'social') {
            abort_unless(in_array($user->role?->slug, ['marketing', 'reels_manager', 'rop', 'mop', 'branch_director', 'admin', 'superadmin'], true), 403);
            $query->whereIn($this->liquidityColumn($user, 'promotion_eligibility'), ['eligible', 'content_needed'])
                ->orderByDesc($this->liquidityColumn($user, 'promotion_priority_score'));
        } else {
            $query->orderByDesc('properties.liquidity_business_priority')->orderByDesc($this->liquidityColumn($user, 'liquidity_score'));
        }

        foreach (['liquidity_category' => 'category', 'price_position' => 'price_position', 'district_id' => 'district_id', 'agent_id' => 'agent_id', 'promotion_eligibility' => 'promotion_eligibility'] as $column => $key) {
            if (array_key_exists($key, $validated)) {
                $query->where($this->liquidityColumn($user, $column), $validated[$key]);
            }
        }

        $page = $query->orderByDesc('properties.id')->paginate($validated['per_page'] ?? 20);
        $page->through(fn (Property $property) => $this->serialize($property, $user->hasRole('rop')
            ? new PropertyLiquiditySnapshot(json_decode($property->rop_liquidity_snapshot, true, flags: JSON_THROW_ON_ERROR))
            : $property->latestLiquiditySnapshot, false));

        return response()->json($page);
    }

    public function report(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->access->isInternal($user), 403, 'Доступ запрещён');
        $query = $this->scopedActiveQuery($user);

        $category = $this->liquidityColumn($user, 'liquidity_category');
        $score = $this->liquidityColumn($user, 'liquidity_score');
        $position = $this->liquidityColumn($user, 'price_position');
        $confidence = $this->liquidityColumn($user, 'liquidity_confidence');

        $categories = (clone $query)
            ->selectRaw("{$category} as liquidity_category, COUNT(*) as aggregate")
            ->groupBy($category)
            ->pluck('aggregate', 'liquidity_category')
            ->map(fn ($value) => (int) $value);
        $byAgent = (clone $query)
            ->leftJoin('users as liquidity_agents', 'liquidity_agents.id', '=', 'properties.agent_id')
            ->selectRaw("properties.agent_id, COALESCE(liquidity_agents.name, ?) as agent_name, COUNT(*) as total, SUM(CASE WHEN {$position} = ? THEN 1 ELSE 0 END) as below_market", ['Не назначен', 'below_market'])
            ->groupBy('properties.agent_id', 'liquidity_agents.name')
            ->orderByDesc('total')
            ->get();
        $byDistrict = (clone $query)
            ->selectRaw("properties.district, COUNT(*) as total, ROUND(AVG({$score}), 1) as average_score")
            ->groupBy('district')
            ->orderByDesc('total')
            ->get();

        return response()->json(['data' => [
            'summary' => [
                'total' => (clone $query)->count(),
                'below_market' => (clone $query)->where($position, 'below_market')->count(),
                'high_liquidity' => (clone $query)->where($score, '>=', config('property-liquidity.liquid_score_threshold', 65))->count(),
                'low_confidence' => (clone $query)->where($confidence, '<', config('property-liquidity.public_badge_minimum_confidence', 45))->count(),
                'stalled' => (clone $query)->where($score, '<', 45)->where('listed_at', '<=', now()->subDays(60))->count(),
            ],
            'categories' => $categories,
            'by_agent' => $byAgent,
            'by_district' => $byDistrict,
            'model_version' => config('property-liquidity.model_version'),
            'generated_at' => now()->toJSON(),
        ]]);
    }

    public function history(Request $request, Property $property)
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->hasRole('rop')) app(\App\Support\RopGroupAccess::class)->ensureVisible($user, $property);
        abort_unless($this->access->isInternal($user) && $this->access->canView($user, $property), 403, 'Доступ запрещён');

        if ($user->hasRole('rop')) {
            return response()->json(['data' => app(\App\Services\PropertyLiquidity\RopLiquidityResults::class)
                ->history($user, $property)->get(['score', 'price_delta_pct', 'price_position', 'confidence_score', 'calculated_at'])
                ->map(function ($row) {
                    $row->calculated_at = \Illuminate\Support\Carbon::parse($row->calculated_at)->toJSON();
                    return $row;
                })]);
        }

        return response()->json(['data' => $property->liquiditySnapshots()
            ->latest('calculated_at')
            ->limit(90)
            ->get(['score', 'price_delta_pct', 'price_position', 'confidence_score', 'calculated_at'])]);
    }

    public function recalculate(Request $request, Property $property)
    {
        abort_unless(in_array($request->user()?->role?->slug, ['admin', 'superadmin'], true), 403, 'Доступ запрещён');
        RecalculatePropertyLiquidity::dispatch($property->id);

        return response()->json(['message' => 'Пересчет поставлен в очередь.'], 202);
    }

    public function updatePromotion(Request $request, Property $property)
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->access->canManagePromotion($user), 403, 'Доступ запрещён');

        $data = $request->validate([
            'channel' => ['nullable', Rule::in(PropertySocialPromotion::CHANNELS)],
            'status' => ['required', Rule::in(PropertySocialPromotion::STATUSES)],
            'planned_at' => ['nullable', 'date'],
            'published_at' => ['nullable', 'date'],
            'published_url' => ['nullable', 'url', 'max:2000'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'skip_reason' => ['nullable', 'required_if:status,skipped', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'metrics' => ['nullable', 'array'],
        ]);

        $promotion = PropertySocialPromotion::query()->create($data + [
            'property_id' => $property->id,
            'priority_score_snapshot' => $property->promotion_priority_score,
            'liquidity_score_snapshot' => $property->liquidity_score,
            'published_by' => $data['status'] === 'published' ? $user->id : null,
            'published_at' => $data['status'] === 'published' ? ($data['published_at'] ?? now()) : ($data['published_at'] ?? null),
        ]);
        RecalculatePropertyLiquidity::dispatch($property->id);

        return response()->json(['data' => $promotion], 201);
    }

    public function updateBusinessPriority(Request $request, Property $property)
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'comment' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        return DB::transaction(function () use ($request, $property, $data) {
            [$user, $property] = app(\App\Services\GroupAccess\GroupRecordWriteLock::class)->acquire($request->user(), $property, null, null);
            if ($user->hasRole('rop')) app(\App\Support\RopGroupAccess::class)->ensureVisible($user, $property);
            abort_unless($this->access->canSetBusinessPriority($user) && $this->access->canView($user, $property), 403, 'Доступ запрещён');
            $changedAt = now();

            $property->updateQuietly([
                'liquidity_business_priority' => $data['enabled'],
                'liquidity_business_priority_comment' => $data['comment'],
                'liquidity_business_priority_by' => $user->id,
                'liquidity_business_priority_at' => $changedAt,
            ]);
            DB::table('property_liquidity_priority_logs')->insert([
                'property_id' => $property->id,
                'enabled' => $data['enabled'],
                'comment' => $data['comment'],
                'changed_by' => $user->id,
                'created_at' => $changedAt,
                'updated_at' => $changedAt,
            ]);

            return response()->json(['data' => [
                'enabled' => (bool) $data['enabled'],
                'comment' => $data['comment'],
                'changed_by' => $user->id,
                'changed_at' => $changedAt->toJSON(),
            ]]);
        });
    }

    private function serialize(Property $property, PropertyLiquiditySnapshot $snapshot, bool $detailed): array
    {
        $payload = [
            'id' => $property->id,
            'title' => $property->title,
            'price' => (float) $property->price,
            'discount_price' => $property->discount_price !== null ? (float) $property->discount_price : null,
            'currency' => $property->currency,
            'district' => $property->district,
            'type' => $property->type ? [
                'id' => $property->type->id,
                'name' => $property->type->name,
                'slug' => $property->type->slug,
            ] : null,
            'rooms' => $property->rooms,
            'total_area' => $property->total_area !== null ? (float) $property->total_area : null,
            'photos' => $property->photos->map(fn ($photo) => ['id' => $photo->id, 'url' => $photo->file_path ? asset('storage/'.ltrim($photo->file_path, '/')) : null]),
            'agent' => $property->agent ? ['id' => $property->agent->id, 'name' => $property->agent->name] : null,
            'score' => $snapshot->score,
            'category' => $snapshot->category,
            'confidence' => ['score' => $snapshot->confidence_score, 'level' => $snapshot->confidence_level],
            'price_position' => [
                'code' => $snapshot->price_position,
                'label' => match ($snapshot->price_position) {
                    'below_market' => 'Цена ниже рынка',
                    'at_market' => 'Цена в рынке',
                    default => 'Цена выше рынка',
                },
                'delta_pct' => (float) $snapshot->price_delta_pct,
            ],
            'interest' => $snapshot->interest,
            'promotion' => [
                'eligible' => ($snapshot->exists ? $property->promotion_eligibility : $snapshot->promotion_eligibility) === 'eligible',
                'eligibility' => $snapshot->exists ? $property->promotion_eligibility : $snapshot->promotion_eligibility,
                'priority_score' => $snapshot->exists ? $property->promotion_priority_score : $snapshot->promotion_priority_score,
                'latest' => $property->latestSocialPromotion,
            ],
            'business_priority' => [
                'enabled' => (bool) $property->liquidity_business_priority,
                'comment' => $property->liquidity_business_priority_comment,
            ],
            'calculated_at' => $snapshot->calculated_at?->toJSON(),
        ];

        if ($detailed) {
            $payload += [
                'predicted_sale_days' => $snapshot->predicted_days_from ? ['from' => $snapshot->predicted_days_from, 'to' => $snapshot->predicted_days_to] : null,
                'components' => [
                    'district_market' => $snapshot->district_market_score,
                    'price' => $snapshot->price_score,
                    'demand' => $snapshot->demand_score,
                    'apartment_fit' => $snapshot->apartment_fit_score,
                ],
                'market' => array_merge($snapshot->market ?? [], [
                    'sold_comparables' => $snapshot->cohort_sold_count,
                    'active_comparables' => $snapshot->cohort_active_count,
                    'median_days_on_market' => $snapshot->cohort_median_dom,
                    'median_price_per_sqm' => (float) $snapshot->cohort_median_price_sqm,
                ]),
                'factors' => $snapshot->factors ?? [],
                'recommendations' => $snapshot->recommendations ?? [],
                'model_version' => $snapshot->model_version,
            ];
        }

        return $payload;
    }

    private function liquidityColumn(User $user, string $column): string
    {
        $scalars = ['liquidity_score' => 'score', 'liquidity_category' => 'category', 'liquidity_confidence' => 'confidence_score',
            'price_position' => 'price_position', 'promotion_priority_score' => 'promotion_priority_score', 'promotion_eligibility' => 'promotion_eligibility'];
        return $user->hasRole('rop') && isset($scalars[$column]) ? 'scoped_liquidity.'.$scalars[$column] : 'properties.'.$column;
    }

    private function scopedActiveQuery(User $user): Builder
    {
        if ($user->hasRole('rop')) {
            $results = app(\App\Services\PropertyLiquidity\RopLiquidityResults::class);
            $results->refresh($user);
            return Property::query()->joinSub($results->query($user), 'scoped_liquidity',
                fn ($join) => $join->on('properties.id', '=', 'scoped_liquidity.property_id'));
        }

        $query = Property::query()
            ->publicSearchable()
            ->whereNotNull('liquidity_score')
            ->whereNull('sold_at')
            ->where('offer_type', 'sale');
        $this->access->scope($query, $user);

        return $query;
    }
}

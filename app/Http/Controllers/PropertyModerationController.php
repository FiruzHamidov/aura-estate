<?php

namespace App\Http\Controllers;

use App\Models\EmployeeTrustEvent;
use App\Models\Property;
use App\Models\PropertyDuplicateCandidate;
use App\Models\PropertyModerationCase;
use App\Models\PropertyPromotion;
use App\Services\PropertyModeration\PropertyModerationAccess;
use App\Services\PropertyModeration\PropertyModerationService;
use App\Services\PropertyModeration\PropertyPromotionService;
use App\Services\PropertyQualityService;
use Illuminate\Http\Request;

final class PropertyModerationController extends Controller
{
    public function __construct(
        private readonly PropertyModerationService $moderation,
        private readonly PropertyPromotionService $promotions,
        private readonly PropertyQualityService $quality,
        private readonly PropertyModerationAccess $access,
    ) {}

    public function submit(Request $request, Property $property)
    {
        $data = $request->validate(['version' => 'required|integer|min:0']);

        return response()->json(['data' => $this->moderation->submit(
            $property,
            $request->user(),
            $this->quality->inspect($property->getAttributes()),
            $data['version'],
        )]);
    }

    public function queue(Request $request)
    {
        $user = $request->user();
        abort_unless(in_array($user->role?->slug, config('property-moderation.moderator_roles', []), true), 403);
        $data = $request->validate(['type' => 'nullable|in:initial_review,price_increase,duplicate_review,content_review,appeal', 'per_page' => 'nullable|integer|min:1|max:100']);
        $query = PropertyModerationCase::query()->open()->with(['property.photos', 'submitter.role', 'duplicateCandidates.candidateProperty.photos'])->oldest('submitted_at');
        $query->whereHas('property', fn ($properties) => $this->access->scopeModeratable($properties, $user));
        $query->when($data['type'] ?? null, fn ($cases, $type) => $cases->where('type', $type));

        return response()->json($query->paginate($data['per_page'] ?? 25));
    }

    public function promotionQueue(Request $request)
    {
        $user = $request->user();
        abort_unless(in_array($user->role?->slug, config('property-moderation.moderator_roles', []), true), 403);
        $query = PropertyPromotion::query()->where('status', PropertyPromotion::STATUS_REQUESTED)->with(['property.photos', 'requester.role'])->oldest('requested_at');
        $query->whereHas('property', fn ($properties) => $this->access->scopeModeratable($properties, $user));

        return response()->json($query->paginate(25));
    }

    public function trustReport(Request $request)
    {
        $user = $request->user();
        abort_unless(in_array($user->role?->slug, config('property-moderation.moderator_roles', []), true), 403);
        $data = $request->validate(['per_page' => 'nullable|integer|min:1|max:100']);
        $query = EmployeeTrustEvent::query()
            ->select('user_id')
            ->selectRaw('SUM(points_delta) as points_delta')
            ->selectRaw('COUNT(*) as events_count')
            ->where(function ($events): void {
                $events->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->with('user:id,name,phone,branch_id')
            ->groupBy('user_id')
            ->orderBy('points_delta');
        if (! in_array($user->role?->slug, config('property-moderation.global_moderator_roles', []), true)) {
            $query->whereHas('user', fn ($employees) => $employees->where('branch_id', $user->branch_id));
        }
        $page = $query->paginate($data['per_page'] ?? 25);
        $page->getCollection()->transform(function (EmployeeTrustEvent $row): array {
            $delta = (float) $row->getAttribute('points_delta');

            return [
                'user' => $row->user,
                'score' => max(0, round(100 + $delta, 2)),
                'points_delta' => $delta,
                'events_count' => (int) $row->getAttribute('events_count'),
            ];
        });

        return response()->json($page);
    }

    public function approve(Request $request, PropertyModerationCase $case)
    {
        $data = $request->validate(['comment' => 'nullable|string|max:2000', 'version' => 'required|integer|min:1']);

        return response()->json(['data' => $this->moderation->approveCase($case, $request->user(), $data['comment'] ?? null, $data['version'])]);
    }

    public function reject(Request $request, PropertyModerationCase $case)
    {
        $data = $request->validate([
            'comment' => 'required|string|max:2000',
            'version' => 'required|integer|min:1',
            'action' => 'nullable|in:keep_hidden,restore_and_publish',
            'confirmed_violation' => 'nullable|boolean',
        ]);

        return response()->json(['data' => $this->moderation->rejectCase(
            $case,
            $request->user(),
            $data['comment'],
            $data['version'],
            $data['action'] ?? 'keep_hidden',
            (bool) ($data['confirmed_violation'] ?? false),
        )]);
    }

    public function breakGlassApprove(Request $request, PropertyModerationCase $case)
    {
        $data = $request->validate(['reason' => 'required|string|min:10|max:2000', 'version' => 'required|integer|min:1']);

        return response()->json(['data' => $this->moderation->breakGlassApprove($case, $request->user(), $data['reason'], $data['version'])]);
    }

    public function decideDuplicate(Request $request, PropertyDuplicateCandidate $candidate)
    {
        $data = $request->validate([
            'decision' => 'required|in:not_duplicate,confirmed_duplicate',
            'comment' => 'required|string|max:2000',
            'version' => 'required|integer|min:1',
        ]);

        return response()->json(['data' => $this->moderation->decideDuplicate($candidate, $request->user(), $data['decision'], $data['comment'], $data['version'])]);
    }

    public function appeal(Request $request, PropertyModerationCase $case)
    {
        $data = $request->validate(['comment' => 'required|string|max:2000', 'version' => 'required|integer|min:1']);

        return response()->json(['data' => $this->moderation->appeal($case, $request->user(), $data['comment'], $data['version'])], 201);
    }

    public function mergeDuplicate(Request $request, PropertyDuplicateCandidate $candidate)
    {
        $data = $request->validate(['comment' => 'required|string|max:2000', 'version' => 'required|integer|min:1']);

        return response()->json(['data' => $this->moderation->mergeDuplicate($candidate, $request->user(), $data['comment'], $data['version'])]);
    }

    public function rejectDuplicate(Request $request, PropertyDuplicateCandidate $candidate)
    {
        $data = $request->validate(['comment' => 'required|string|max:2000', 'version' => 'required|integer|min:1']);

        return response()->json(['data' => $this->moderation->rejectDuplicate(
            $candidate,
            $request->user(),
            $data['comment'],
            $data['version'],
        )]);
    }

    public function approveForProperty(Request $request, Property $property, PropertyModerationCase $case)
    {
        abort_unless((int) $case->property_id === (int) $property->id, 404);

        return $this->approve($request, $case);
    }

    public function rejectForProperty(Request $request, Property $property, PropertyModerationCase $case)
    {
        abort_unless((int) $case->property_id === (int) $property->id, 404);

        return $this->reject($request, $case);
    }

    public function withdrawCaseForProperty(Request $request, Property $property, PropertyModerationCase $case)
    {
        abort_unless((int) $case->property_id === (int) $property->id, 404);

        return $this->withdrawCase($request, $case);
    }

    public function appealForProperty(Request $request, Property $property, PropertyModerationCase $case)
    {
        abort_unless((int) $case->property_id === (int) $property->id, 404);

        return $this->appeal($request, $case);
    }

    public function resolveAppeal(Request $request, Property $property, PropertyModerationCase $case)
    {
        abort_unless((int) $case->property_id === (int) $property->id && $case->type === PropertyModerationCase::TYPE_APPEAL, 404);
        $data = $request->validate([
            'decision' => 'required|in:approved,rejected',
            'comment' => 'required|string|max:2000',
            'version' => 'required|integer|min:1',
        ]);

        $resolved = $data['decision'] === 'approved'
            ? $this->moderation->approveCase($case, $request->user(), $data['comment'], $data['version'])
            : $this->moderation->rejectCase($case, $request->user(), $data['comment'], $data['version']);

        return response()->json(['data' => $resolved]);
    }

    public function decideDuplicateForProperty(Request $request, Property $property, PropertyDuplicateCandidate $candidate, string $decision)
    {
        $candidate->loadMissing('moderationCase');
        abort_unless((int) $candidate->moderationCase?->property_id === (int) $property->id, 404);
        $data = $request->validate(['comment' => 'required|string|max:2000', 'version' => 'required|integer|min:1']);

        return response()->json(['data' => $this->moderation->decideDuplicate(
            $candidate,
            $request->user(),
            $decision,
            $data['comment'],
            $data['version'],
        )]);
    }

    public function confirmDuplicateForProperty(Request $request, Property $property, PropertyDuplicateCandidate $candidate)
    {
        return $this->decideDuplicateForProperty($request, $property, $candidate, PropertyDuplicateCandidate::DECISION_CONFIRMED);
    }

    public function dismissDuplicateForProperty(Request $request, Property $property, PropertyDuplicateCandidate $candidate)
    {
        return $this->decideDuplicateForProperty($request, $property, $candidate, PropertyDuplicateCandidate::DECISION_NOT_DUPLICATE);
    }

    public function mergeDuplicateForProperty(Request $request, Property $property, PropertyDuplicateCandidate $candidate)
    {
        $candidate->loadMissing('moderationCase');
        abort_unless((int) $candidate->moderationCase?->property_id === (int) $property->id, 404);

        return $this->mergeDuplicate($request, $candidate);
    }

    public function rejectDuplicateForProperty(Request $request, Property $property, PropertyDuplicateCandidate $candidate)
    {
        $candidate->loadMissing('moderationCase');
        abort_unless((int) $candidate->moderationCase?->property_id === (int) $property->id, 404);

        return $this->rejectDuplicate($request, $candidate);
    }

    public function withdrawChanges(Request $request, Property $property)
    {
        $data = $request->validate(['version' => 'required|integer|min:0']);

        return response()->json(['data' => $this->moderation->withdrawChanges($property, $request->user(), $data['version'])]);
    }

    public function withdrawCase(Request $request, PropertyModerationCase $case)
    {
        $data = $request->validate(['version' => 'required|integer|min:1']);

        return response()->json(['data' => $this->moderation->withdrawCase(
            $case,
            $request->user(),
            $data['version'],
        )]);
    }

    public function withdrawListing(Request $request, Property $property)
    {
        $data = $request->validate(['target' => 'required|in:draft,archived', 'version' => 'required|integer|min:0']);

        return response()->json(['data' => $this->moderation->withdrawListing($property, $request->user(), $data['target'], $data['version'])]);
    }

    public function transfer(Request $request, Property $property)
    {
        $data = $request->validate([
            'agent_id' => 'sometimes|required_without:co_owner_user_id|nullable|integer|exists:users,id',
            'co_owner_user_id' => 'sometimes|required_without:agent_id|nullable|integer|exists:users,id',
            'reason' => 'required|string|min:5|max:2000',
            'version' => 'required|integer|min:0',
        ]);
        $changes = array_intersect_key($data, array_flip(['agent_id', 'co_owner_user_id']));

        return response()->json(['data' => $this->moderation->transfer(
            $property,
            $request->user(),
            $changes,
            $data['reason'],
            $data['version'],
        )]);
    }

    public function requestPromotion(Request $request, Property $property)
    {
        $data = $request->validate([
            'type' => 'required|in:vip,urgent',
            'comment' => 'required|string|min:3|max:2000',
            'days' => 'required|integer|min:1|max:30',
            'version' => 'required|integer|min:0',
        ]);

        return response()->json(['data' => $this->promotions->request(
            $property,
            $request->user(),
            $data['type'],
            $data['comment'],
            $data['days'],
            $data['version'],
        )], 201);
    }

    public function approvePromotion(Request $request, PropertyPromotion $promotion)
    {
        $data = $request->validate(['days' => 'nullable|integer|min:1|max:30', 'comment' => 'nullable|string|max:2000', 'version' => 'required|integer|min:1']);

        return response()->json(['data' => $this->promotions->approve(
            $promotion,
            $request->user(),
            $data['days'] ?? $promotion->requested_days ?? (int) config('property-moderation.promotion_default_days', 7),
            $data['comment'] ?? null,
            $data['version'],
        )]);
    }

    public function rejectPromotion(Request $request, PropertyPromotion $promotion)
    {
        $data = $request->validate(['comment' => 'required|string|max:2000', 'version' => 'required|integer|min:1']);

        return response()->json(['data' => $this->promotions->reject($promotion, $request->user(), $data['comment'], $data['version'])]);
    }

    public function revokePromotion(Request $request, PropertyPromotion $promotion)
    {
        $data = $request->validate(['comment' => 'required|string|max:2000', 'version' => 'required|integer|min:1']);

        return response()->json(['data' => $this->promotions->revoke($promotion, $request->user(), $data['comment'], $data['version'])]);
    }

    public function approvePromotionForProperty(Request $request, Property $property, PropertyPromotion $promotion)
    {
        abort_unless((int) $promotion->property_id === (int) $property->id, 404);

        return $this->approvePromotion($request, $promotion);
    }

    public function rejectPromotionForProperty(Request $request, Property $property, PropertyPromotion $promotion)
    {
        abort_unless((int) $promotion->property_id === (int) $property->id, 404);

        return $this->rejectPromotion($request, $promotion);
    }

    public function revokePromotionForProperty(Request $request, Property $property, PropertyPromotion $promotion)
    {
        abort_unless((int) $promotion->property_id === (int) $property->id, 404);

        return $this->revokePromotion($request, $promotion);
    }
}

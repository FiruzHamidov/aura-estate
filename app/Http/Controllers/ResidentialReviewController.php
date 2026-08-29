<?php

namespace App\Http\Controllers;

use App\Models\NewBuilding;
use App\Models\ReviewComplaint;
use App\Services\Residential\ResidentialAccess;
use App\Services\Residential\ResidentialReviews;
use Illuminate\Http\Request;

final class ResidentialReviewController extends Controller
{
    public function __construct(private readonly ResidentialReviews $reviews, private readonly ResidentialAccess $access) {}

    public function index(Request $request, NewBuilding $new_building)
    {
        $this->reviews->ensurePublic($new_building);
        $data = $request->validate(['page' => 'integer|min:1', 'per_page' => 'integer|between:1,50']);
        $query = $this->reviews->query($new_building)->approved();
        $summary = (clone $query)->selectRaw('COUNT(*) as count, AVG(rating) as average')->first();
        $page = $query->orderByDesc('published_at')->orderByDesc('id')->paginate($data['per_page'] ?? 10);
        $page->through(fn ($review) => $this->reviews->serialize($review));

        return $page->toArray() + ['summary' => ['count' => (int) $summary->count, 'average' => $summary->average === null ? null : round((float) $summary->average, 2)]];
    }

    public function mine(Request $request, NewBuilding $new_building)
    {
        $this->reviews->ensurePublic($new_building);
        $review = $this->reviews->query($new_building)->where('author_user_id', $request->user()->id)->first();

        return ['data' => $review ? $this->reviews->serialize($review, true) : null];
    }

    public function store(Request $request, NewBuilding $new_building)
    {
        return response()->json($this->reviews->serialize($this->reviews->save($request->user(), $new_building, $request->all()), true), 201);
    }

    public function update(Request $request, NewBuilding $new_building, int $review)
    {
        return $this->reviews->serialize($this->reviews->save($request->user(), $new_building, $request->all(), $review), true);
    }

    public function complain(Request $request, NewBuilding $new_building, int $review)
    {
        $complaint = $this->reviews->complain($request->user(), $new_building, $review, $request->all());

        return response()->json(['id' => $complaint->id, 'status' => $complaint->status], $complaint->wasRecentlyCreated ? 201 : 200);
    }

    public function adminIndex(Request $request, NewBuilding $new_building)
    {
        $this->access->ensurePublish($request->user(), $new_building);
        $data = $request->validate(['page' => 'integer|min:1', 'status' => 'sometimes|in:pending,approved,rejected']);

        return $this->reviews->query($new_building)->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))->withCount(['complaints as open_complaints_count' => fn ($q) => $q->where('status', 'open')])->orderByDesc('id')->paginate(20)
            ->through(fn ($review) => $this->reviews->serialize($review, true) + ['open_complaints_count' => $review->open_complaints_count, 'can_moderate' => (int) $review->author_user_id !== (int) $request->user()->id]);
    }

    public function moderate(Request $request, NewBuilding $new_building, int $review)
    {
        return $this->reviews->serialize($this->reviews->moderate($request->user(), $new_building, $review, $request->all()), true);
    }

    public function complaints(Request $request, NewBuilding $new_building)
    {
        $this->access->ensurePublish($request->user(), $new_building);
        $data = $request->validate(['page' => 'integer|min:1', 'status' => 'sometimes|in:open,resolved,dismissed']);

        return ReviewComplaint::query()->whereHas('review', fn ($q) => $q->whereMorphedTo('reviewable', $new_building))->where('status', $data['status'] ?? 'open')->with('review')->orderByDesc('id')->paginate(20)
            ->through(fn ($item) => $item->only(['id', 'review_id', 'reason', 'status', 'version', 'created_at', 'resolution']) + ['review' => $this->reviews->serialize($item->review, true) + ['can_moderate' => (int) $item->review->author_user_id !== (int) $request->user()->id]]);
    }

    public function resolve(Request $request, NewBuilding $new_building, int $complaint)
    {
        return $this->reviews->resolveComplaint($request->user(), $new_building, $complaint, $request->all())->only(['id', 'status', 'version', 'resolution']);
    }
}

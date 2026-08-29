<?php

namespace App\Services\Residential;

use App\Models\NewBuilding;
use App\Models\Review;
use App\Models\ReviewComplaint;
use App\Models\User;
use App\Services\Crm\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ResidentialReviews
{
    public function __construct(private readonly ResidentialAccess $access, private readonly InventoryWriter $versions, private readonly AuditLogger $audit) {}

    public function query(NewBuilding $building): Builder
    {
        return Review::query()->whereMorphedTo('reviewable', $building);
    }

    public function ensurePublic(NewBuilding $building): void
    {
        abort_unless(NewBuilding::query()->published()->whereKey($building->id)->exists(), 404);
    }

    private function ensureAuthor(User $user): void
    {
        abort_unless($user->status === User::STATUS_ACTIVE && ! $user->isDeletedAccount(), 403);
    }

    public function save(User $user, NewBuilding $building, array $input, ?int $id = null): Review
    {
        $this->ensureAuthor($user);
        $this->ensurePublic($building);
        $data = Validator::make($input, ['rating' => 'required|integer|between:1,5', 'text' => 'required|string|min:10|max:5000', 'version' => [$id ? 'required' : 'sometimes', 'integer', 'min:1']])->validate();

        return DB::transaction(function () use ($user, $building, $data, $id) {
            $parent = NewBuilding::query()->lockForUpdate()->findOrFail($building->id);
            $this->ensurePublic($parent);
            $review = $id ? $this->query($parent)->lockForUpdate()->findOrFail($id) : new Review;
            if ($id) {
                abort_unless((int) $review->author_user_id === (int) $user->id, 403);
                $this->versions->checkVersion($review, $data);
            } else {
                abort_if($this->query($parent)->where('author_user_id', $user->id)->exists(), 409, 'Ваш отзыв уже сохранён. Откройте его для редактирования.');
            }
            $old = $review->getAttributes();
            $review->fill(['reviewable_type' => $parent->getMorphClass(), 'reviewable_id' => $parent->id, 'author_user_id' => $user->id,
                'author_name' => $user->name, 'rating' => $data['rating'], 'text' => trim($data['text']), 'status' => 'pending', 'published_at' => null,
                'moderated_by' => null, 'moderated_at' => null, 'moderation_reason' => null, 'version' => $id ? $review->version + 1 : 1])->save();
            $this->audit->log($review, $user, $id ? 'residential.review.edited' : 'residential.review.submitted', $old, $review->getAttributes());

            return $review;
        }, 3);
    }

    public function moderate(User $actor, NewBuilding $building, int $id, array $input): Review
    {
        $data = Validator::make($input, ['status' => 'required|in:approved,rejected', 'reason' => 'required|string|min:3|max:1000', 'version' => 'required|integer|min:1'])->validate();

        return DB::transaction(function () use ($actor, $building, $id, $data) {
            $parent = NewBuilding::query()->lockForUpdate()->findOrFail($building->id);
            $this->access->ensurePublish($actor, $parent);
            $review = $this->query($parent)->lockForUpdate()->findOrFail($id);
            abort_if((int) $review->author_user_id === (int) $actor->id, 403, 'Свой отзыв должен проверить другой модератор.');
            $this->versions->checkVersion($review, $data);
            $old = $review->getAttributes();
            $review->update(['status' => $data['status'], 'moderation_reason' => $data['reason'], 'moderated_by' => $actor->id, 'moderated_at' => now(),
                'published_at' => $data['status'] === 'approved' ? ($review->published_at ?? now()) : null, 'version' => $review->version + 1]);
            $this->audit->log($review, $actor, 'residential.review.moderated', $old, $review->getAttributes(), $data['reason']);

            return $review;
        }, 3);
    }

    public function complain(User $user, NewBuilding $building, int $id, array $input): ReviewComplaint
    {
        $this->ensureAuthor($user);
        $this->ensurePublic($building);
        $data = Validator::make($input, ['reason' => 'required|string|min:10|max:2000'])->validate();

        return DB::transaction(function () use ($user, $building, $id, $data) {
            $parent = NewBuilding::query()->lockForUpdate()->findOrFail($building->id);
            $this->ensurePublic($parent);
            $review = $this->query($parent)->approved()->lockForUpdate()->findOrFail($id);
            $complaint = ReviewComplaint::firstOrCreate(['review_id' => $review->id, 'user_id' => $user->id], ['reason' => $data['reason']]);
            if ($complaint->wasRecentlyCreated) {
                $this->audit->log($complaint, $user, 'residential.review.complaint', [], $complaint->getAttributes());
            }

            return $complaint;
        }, 3);
    }

    public function resolveComplaint(User $actor, NewBuilding $building, int $id, array $input): ReviewComplaint
    {
        $data = Validator::make($input, ['version' => 'required|integer|min:1', 'status' => 'required|in:resolved,dismissed', 'resolution' => 'required|string|min:3|max:1000'])->validate();

        return DB::transaction(function () use ($actor, $building, $id, $data) {
            $parent = NewBuilding::query()->lockForUpdate()->findOrFail($building->id);
            $this->access->ensurePublish($actor, $parent);
            $complaint = ReviewComplaint::query()->whereHas('review', fn ($q) => $q->whereMorphedTo('reviewable', $parent))->lockForUpdate()->findOrFail($id);
            $this->versions->checkVersion($complaint, $data);
            $old = $complaint->getAttributes();
            $complaint->update(['status' => $data['status'], 'resolution' => $data['resolution'], 'resolved_at' => now(), 'resolved_by' => $actor->id, 'version' => $complaint->version + 1]);
            $this->audit->log($complaint, $actor, 'residential.review.complaint_resolved', $old, $complaint->getAttributes(), $data['resolution']);

            return $complaint;
        }, 3);
    }

    public function serialize(Review $review, bool $private = false): array
    {
        $data = ['id' => $review->id, 'author' => $review->author_name, 'rating' => $review->rating, 'text' => $review->text, 'date' => ($review->published_at ?? $review->created_at)?->toIso8601String()];

        return $private ? $data + ['version' => $review->version, 'status' => $review->status, 'moderation_reason' => $review->moderation_reason] : $data;
    }
}

<?php

namespace App\Services\Kpi;

use App\Models\MotivationAchievement;
use App\Models\MotivationRewardIssue;
use App\Models\User;
use App\Support\KpiResultAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MotivationWorkflow
{
    public function ensureVisible(User $actor, MotivationAchievement $achievement): void
    {
        if ($achievement->company_scope) {
            abort_unless(! $actor->usesAssignedGroupScope() && $actor->hasPermission('motivation.achievements.company') && $actor->hasPermission('motivation.achievements.scope.all'), 403);

            return;
        }
        $scope = $achievement->meta['scope'] ?? [
            'user_id' => $achievement->user_id, 'branch_id' => $achievement->user?->branch_id,
            'branch_group_id' => $achievement->user?->branch_group_id,
        ];
        abort_unless(app(KpiResultAccess::class)->visible($actor, $scope, 'motivation.achievements'), 403);
    }

    public function transition(User $actor, int $id, array $data): MotivationAchievement
    {
        return DB::transaction(function () use ($actor, $id, $data) {
            $candidate = MotivationAchievement::findOrFail($id);
            [$actor] = app(\App\Services\GroupAccess\GroupRecordWriteLock::class)->acquireMany($actor, [], [$candidate->user_id], [$candidate->meta['scope']['branch_group_id'] ?? null]);
            $achievement = MotivationAchievement::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->ensureVisible($actor, $achievement);
            $action = $data['action'];
            $permission = match ($action) {
                'verify' => 'motivation.verify', 'approve', 'return', 'resolve_review' => 'motivation.approve',
                'issue' => 'motivation.issue',
            };
            abort_unless($actor->hasPermission($permission), 403);
            $meta = $achievement->meta ?? [];
            if ($action === 'resolve_review') {
                abort_unless($achievement->status === 'issued' && ($meta['review_required'] ?? false), 409, 'Нет незавершённой проверки.');
                abort_unless((int) ($meta['version'] ?? 0) === (int) $data['expected_version'], 409, 'MOTIVATION_VERSION_CONFLICT');
                $rule = $achievement->rule;
                $facts = app(ResultFactService::class)->forPeriod($rule->date_from->toDateString(), $rule->date_to->toDateString(), true);
                $result = app(MotivationEligibility::class)->evaluate($rule, $facts, $achievement->user, $achievement->meta['eligibility']['condition'] ?? null);
                $meta['review_required'] = false;
                $meta['reviewed_result_hash'] = hash('sha256', json_encode($result, JSON_THROW_ON_ERROR));
                $meta['review_resolution'] = ['reason' => $data['reason'], 'actor_id' => $actor->id, 'at' => now()->toIso8601String()];
                $meta['version'] = (int) ($meta['version'] ?? 0) + 1;
                $achievement->meta = $meta;
                $achievement->save();
                $this->audit($achievement, $actor, $action, $data['reason']);

                return $achievement->fresh(['rule', 'rewardIssue']);
            }
            if ($achievement->status === 'issued') {
                // Retries after a timeout are harmless only for the exact same issue command.
                $same = $action === 'issue' && hash_equals((string) ($meta['issue_key'] ?? ''), (string) ($data['idempotency_key'] ?? ''))
                    && hash_equals((string) ($meta['issue_fingerprint'] ?? ''), $this->issueFingerprint($data));
                abort_unless($same && ! empty($data['idempotency_key']), 409, 'Награда уже выдана; повторное изменение запрещено.');

                return $achievement->load('rewardIssue');
            }
            $version = (int) ($meta['version'] ?? 0);
            abort_if($version !== (int) $data['expected_version'], 409, 'MOTIVATION_VERSION_CONFLICT');
            $stage = $meta['workflow'] ?? $achievement->status;
            if ($action === 'verify') {
                abort_unless(in_array($stage, ['new', 'returned'], true), 409, 'Награда уже проверена.');
                $this->ensureStillEligible($achievement);
                $meta['workflow'] = 'verified';
                $meta['verified_by'] = $actor->id;
                $meta['verified_at'] = now()->toIso8601String();
            } elseif ($action === 'approve') {
                abort_unless($stage === 'verified', 409, 'Сначала требуется проверка РОП.');
                abort_unless(CarbonImmutable::now('Asia/Dushanbe')->toDateString() > $achievement->date_to->toDateString(), 422, 'Согласование доступно после окончания периода.');
                $this->ensureStillEligible($achievement);
                $achievement->status = 'approved';
                $achievement->approved_by = $actor->id;
                $achievement->approved_at = now();
                $meta['workflow'] = 'approved';
            } elseif ($action === 'return') {
                abort_unless(in_array($stage, ['verified', 'approved'], true), 409);
                $achievement->status = 'new';
                $achievement->approved_by = null;
                $achievement->approved_at = null;
                $meta['workflow'] = 'returned';
                $meta['return_reason'] = $data['reason'];
            } else {
                abort_unless($achievement->status === 'approved' && $stage === 'approved', 409, 'Выдача возможна только после согласования.');
                $this->ensureStillEligible($achievement);
                $issuedAt = CarbonImmutable::parse($data['issued_at'], 'Asia/Dushanbe');
                abort_if($issuedAt->isFuture() || $issuedAt->lt($achievement->approved_at), 422, 'Дата выдачи должна быть после согласования и не в будущем.');
                $issue = MotivationRewardIssue::firstOrNew(['achievement_id' => $achievement->id]);
                $issue->fill([
                    'assignee_id' => $actor->id, 'status' => 'issued', 'issued_at' => $issuedAt,
                    'comment' => $data['reason'] ?? null,
                    'meta' => ['method' => $data['method'], 'proof' => $data['proof'], 'idempotency_key' => $data['idempotency_key']],
                ])->save();
                $achievement->status = 'issued';
                $achievement->issued_by = $actor->id;
                $achievement->issued_at = $issuedAt;
                $meta['workflow'] = 'issued';
                $meta['issue_key'] = $data['idempotency_key'];
                $meta['issue_fingerprint'] = $this->issueFingerprint($data);
            }
            $meta['version'] = $version + 1;
            $achievement->meta = $meta;
            $achievement->save();
            $this->audit($achievement, $actor, $action, $data['reason'] ?? null);

            return $achievement->fresh(['rule', 'rewardIssue']);
        }, 3);
    }

    public function ensureStillEligible(MotivationAchievement $achievement): void
    {
        $rule = $achievement->rule;
        $facts = app(ResultFactService::class)->forPeriod($rule->date_from->toDateString(), $rule->date_to->toDateString(), true);
        $result = app(MotivationEligibility::class)->evaluate($rule, $facts, $achievement->user, $achievement->meta['eligibility']['condition'] ?? null);
        abort_unless($result['eligible'], 409, 'Данные изменились. Выполните пересчёт и повторную проверку.');
    }

    private function issueFingerprint(array $data): string
    {
        return hash('sha256', json_encode(array_intersect_key($data, array_flip(['issued_at', 'method', 'proof', 'reason'])), JSON_THROW_ON_ERROR));
    }

    public function audit(MotivationAchievement $achievement, ?User $actor, string $action, ?string $reason): void
    {
        if (! Schema::hasTable('motivation_events')) {
            return;
        }
        DB::table('motivation_events')->insert([
            'achievement_id' => $achievement->id, 'actor_id' => $actor?->id, 'action' => $action, 'reason' => $reason,
            'snapshot' => json_encode($achievement->toArray(), JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

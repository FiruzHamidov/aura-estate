<?php

namespace App\Services\AgentWorkspace;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RatingLedger
{
    public function __construct(private RatingAccess $access, private RatingSources $sources) {}

    public function draft(User $actor, int $id, array $input): object
    {
        abort_unless($actor->hasPermission('agent_rating.prepare'), 403);

        return DB::transaction(function () use ($actor, $id, $input) {
            DB::table('agent_rating_state')->where('key', 'facts')->lockForUpdate()->first();
            $this->sources->sync(true);
            $source = DB::table('agent_rating_sources')->where('id', $id)->lockForUpdate()->first();
            abort_unless($source, 404);
            $this->access->ensureSource($actor, $source);
            $requestHash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $existing = DB::table('agent_rating_revisions')->where('event_key', $input['event_key'])->first();
            if ($existing) {
                abort_unless((int) $existing->transaction_id === $id && (int) $existing->author_id === (int) $actor->id && hash_equals($existing->request_hash, $requestHash), 409, 'Ключ запроса уже использован.');

                return $existing;
            }
            abort_unless($source->state === 'closed' && $source->closed_at && CarbonImmutable::parse($source->closed_at, 'UTC')->lessThanOrEqualTo(CarbonImmutable::now()), 422, 'Сделка не закрыта.');
            abort_unless((int) $source->source_version === (int) $input['source_version'], 409, 'Данные сделки изменились. Обновите страницу.');
            $old = DB::table('agent_rating_revisions as r')->join('agent_rating_approvals as a', 'a.revision_id', '=', 'r.id')->where('r.transaction_id', $id)->latest('r.id')->select('r.*')->first();
            $prior = $old ? json_decode($old->payload, true) : null;
            if ($old || CommissionMath::decimal($input['refund'], 2, 'refund') > 0) {
                abort_unless($actor->hasPermission('agent_rating.correct'), 403);
            }
            if ($input['refund'] !== '0' && CommissionMath::decimal($input['refund'], 2, 'refund') > 0 && empty($input['correction_reference'])) {
                throw ValidationException::withMessages(['correction_reference' => 'Укажите документ или событие коррекции комиссии.']);
            }
            if ($prior && ($prior['currency'] !== $input['currency'] || ($prior['fx_rate'] ?? null) !== ($input['fx_rate'] ?? null)) && empty($input['correction_reference'])) {
                throw ValidationException::withMessages(['correction_reference' => 'Изменение валюты или сохранённого курса требует основания корректировки.']);
            }
            $base = CommissionMath::base($input['commission'], $input['refund'], $input['currency'], $input['fx_rate'] ?? null);
            $participants = [];
            $previousAllocations = collect($prior['participants'] ?? []);
            foreach ($input['participants'] as $p) {
                $agent = ($p['agent_id'] ?? null) ? User::find($p['agent_id']) : null;
                if (($p['agent_id'] ?? null) && ! $agent) {
                    throw ValidationException::withMessages(['participants' => 'Участник не найден.']);
                }
                if ($agent) {
                    $branch = $p['branch_id'] ?? $source->branch_id;
                    $group = $p['branch_group_id'] ?? null;
                    if (! $branch || ! DB::table('branches')->where('id', $branch)->exists()) {
                        throw ValidationException::withMessages(['participants' => 'Укажите филиал участника на дату закрытия.']);
                    }
                    if ($group && ! DB::table('branch_groups')->where('id', $group)->where('branch_id', $branch)->exists()) {
                        throw ValidationException::withMessages(['participants' => 'Группа не относится к выбранному филиалу.']);
                    }
                    $this->access->ensureSource($actor, (object) ['user_id' => $agent->id, 'branch_id' => $branch, 'branch_group_id' => $group]);
                }
                $previous = $previousAllocations->first(fn ($a) => ($a['agent_id'] ?? null) === ($p['agent_id'] ?? null) && $a['side'] === $p['side']);
                $participants[] = ['agent_id' => $agent?->id, 'side' => $p['side'], 'share_bps' => (int) $p['share_bps'],
                    'branch_id' => $previous ? $previous['branch_id'] : ($p['branch_id'] ?? $source->branch_id),
                    'branch_group_id' => $previous ? $previous['branch_group_id'] : (array_key_exists('branch_group_id', $p) ? $p['branch_group_id'] : $source->branch_group_id)];
            }
            if ($source->kind === 'unknown' && ! in_array($input['kind'] ?? null, ['sale', 'rental'], true)) {
                throw ValidationException::withMessages(['kind' => 'Укажите тип сделки: продажа или аренда.']);
            }
            $payload = ['transaction_id' => $id, 'property_id' => $source->property_id, 'deal_id' => $source->deal_id,
                'kind' => $source->kind === 'unknown' ? $input['kind'] : $source->kind, 'closed_at' => $source->closed_at, 'commission' => $input['commission'],
                'refund' => $input['refund'], 'currency' => $input['currency'], 'fx_rate' => $input['fx_rate'] ?? null,
                'correction_reference' => $input['correction_reference'] ?? null, 'base_minor' => $base,
                'participants' => CommissionMath::allocate($base, $participants)];
            $revision = DB::table('agent_rating_revisions')->insertGetId(['transaction_id' => $id, 'source_version' => $source->source_version,
                'event_key' => $input['event_key'], 'request_hash' => $requestHash, 'author_id' => $actor->id, 'reason' => $input['reason'], 'payload' => json_encode($payload), 'created_at' => now()->utc()]);
            DB::table('agent_rating_sources')->where('id', $id)->update(['pending_revision_id' => $revision, 'updated_at' => now()->utc()]);

            return DB::table('agent_rating_revisions')->find($revision);
        });
    }

    public function confirm(User $actor, int $revisionId): void
    {
        abort_unless($actor->hasPermission('agent_rating.confirm'), 403);
        DB::transaction(function () use ($actor, $revisionId) {
            DB::table('agent_rating_state')->where('key', 'facts')->lockForUpdate()->first();
            $this->sources->sync(true);
            $revision = DB::table('agent_rating_revisions')->find($revisionId);
            abort_unless($revision, 404);
            $source = DB::table('agent_rating_sources')->where('id', $revision->transaction_id)->lockForUpdate()->first();
            $this->access->ensureSource($actor, $source);
            $payload = json_decode($revision->payload, true);
            abort_if((int) $revision->author_id === (int) $actor->id || (int) $source->user_id === (int) $actor->id || in_array((int) $actor->id, array_column($payload['participants'], 'agent_id'), true), 403, 'Свою сделку или собственную правку подтверждать нельзя.');
            if (DB::table('agent_rating_approvals')->where('revision_id', $revisionId)->exists()) {
                return;
            }
            abort_unless((int) $source->pending_revision_id === $revisionId && (int) $source->source_version === (int) $revision->source_version && $source->state === 'closed', 409, 'Появилась новая версия сделки.');
            DB::table('agent_rating_approvals')->insert(['revision_id' => $revisionId, 'actor_id' => $actor->id, 'created_at' => now()->utc()]);
            DB::table('agent_rating_sources')->where('id', $source->id)->update(['approved_revision_id' => $revisionId, 'pending_revision_id' => null, 'updated_at' => now()->utc()]);
        });
    }

    public function link(User $actor, int $id, int $canonicalId, string $reason): void
    {
        abort_unless($actor->hasPermission('agent_rating.prepare') && $actor->hasPermission('agent_rating.correct'), 403);
        DB::transaction(function () use ($actor, $id, $canonicalId, $reason) {
            DB::table('agent_rating_state')->where('key', 'facts')->lockForUpdate()->first();
            $this->sources->sync(true);
            $source = DB::table('agent_rating_sources')->find($id);
            $canonical = DB::table('agent_rating_sources')->find($canonicalId);
            abort_unless($source && $canonical && $id !== $canonicalId, 422, 'Укажите существующий первичный договор.');
            $this->access->ensureSource($actor, $source);
            $this->access->ensureSource($actor, $canonical);
            abort_unless($source->state === 'needs_link' && ! $source->approved_revision_id && $canonical->state === 'closed'
                && $source->property_id && (int) $source->property_id === (int) $canonical->property_id && ! $canonical->canonical_source_id, 422, 'Связать можно только непроверенный дубль CRM с первичным договором того же объекта.');
            DB::table('agent_rating_sources')->where('id', $id)->update(['canonical_source_id' => $canonicalId, 'state' => 'duplicate',
                'link_reason' => $reason, 'linked_by' => $actor->id, 'linked_at' => now()->utc(), 'updated_at' => now()->utc()]);
        });
    }

    public function close(User $actor, string $month, string $reason, int $expectedVersion): object
    {
        abort_unless($actor->hasPermission('agent_rating.close') && $actor->hasPermission('agent_rating.scope.all') && ! $actor->usesAssignedGroupScope(), 403);
        abort_unless($month < now('Asia/Dushanbe')->format('Y-m'), 422, 'Текущий месяц ещё не завершён.');

        return DB::transaction(function () use ($actor, $month, $reason, $expectedVersion) {
            DB::table('agent_rating_state')->where('key', 'facts')->lockForUpdate()->first();
            $this->sources->sync(true);
            $last = DB::table('agent_rating_periods')->where('month', $month)->latest('version')->first();
            abort_unless(($last?->version ?? 0) === $expectedVersion, 409, 'Версия месяца изменилась.');
            if ($last) {
                abort_unless($actor->hasPermission('agent_rating.reopen'), 403);
            }
            $start = CarbonImmutable::parse($month.'-01', 'Asia/Dushanbe');
            $sources = DB::table('agent_rating_sources')->where(fn ($q) => $q->whereNull('closed_at')->orWhere(fn ($q) => $q->where('closed_at', '>=', $start->utc())->where('closed_at', '<', $start->addMonth()->utc())))->whereIn('state', ['closed', 'needs_link'])->get();
            abort_if($sources->contains(fn ($s) => ! $s->approved_revision_id || $s->pending_revision_id), 422, 'Нельзя закрыть месяц: есть неподтверждённые данные.');
            $ids = $sources->pluck('approved_revision_id')->map(fn ($v) => (int) $v)->all();
            if ($last) {
                $before = json_decode($last->revision_ids, true);
                $changed = array_diff($ids, $before);
                // The approver of an official correction cannot approve their own preparation.
                abort_if(DB::table('agent_rating_revisions')->whereIn('id', $changed)->where('author_id', $actor->id)->exists(), 403, 'Новую официальную версию должен подтвердить другой сотрудник.');
            }
            $id = DB::table('agent_rating_periods')->insertGetId(['month' => $month, 'version' => $expectedVersion + 1, 'revision_ids' => json_encode($ids), 'actor_id' => $actor->id, 'reason' => $reason, 'created_at' => now()->utc()]);

            return DB::table('agent_rating_periods')->find($id);
        });
    }
}

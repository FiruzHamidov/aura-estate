<?php

namespace App\Services\Kpi;

use App\Models\KpiPlanningDraft;
use App\Models\MotivationRule;
use App\Models\User;
use App\Services\GroupAccess\GroupRecordWriteLock;
use App\Services\MotivationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PlanningDraftService
{
    public function __construct(private readonly PlanningScope $scope) {}

    private function conflict(string $code, mixed $current = null): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Данные изменились. Ваши поля сохранены в форме. Проверьте актуальную версию перед повторной публикацией.',
            'code' => $code, 'current' => $current,
        ], 409));
    }

    public function drafts(User $actor, string $type, ?string $month = null): Collection
    {
        $this->scope->ensureManager($actor, $type);

        return KpiPlanningDraft::where('type', $type)->when($month, fn ($q) => $q->where('month', $month))
            ->orderByDesc('updated_at')->get()->filter(fn ($draft) => $this->scope->canAccess($actor, $type, $draft->payload, $draft->created_by))
            ->values();
    }

    public function save(User $actor, array $input, ?int $id = null): KpiPlanningDraft
    {
        $payload = $input['payload'];
        $type = $input['type'];
        $rules = $type === 'goal' ? ResultGoalService::rules($payload) : MotivationProgramService::rules($payload);
        // A draft may be incomplete; unknown fields and unbounded free-form values are not persisted.
        foreach ($rules as $field => $rule) {
            $parts = is_array($rule) ? $rule : explode('|', $rule);
            $rules[$field] = ['nullable', ...array_filter($parts, fn ($part) => ! ($part instanceof \Illuminate\Validation\Rules\RequiredIf)
                && (! is_string($part) || (! str_starts_with($part, 'required') && $part !== 'sometimes')))];
        }
        $rules['expected_version'] = 'nullable|integer|min:0';
        if ($type === 'goal') {
            $rules['assignment'] = 'nullable|in:user,group';
        }
        if ($type === 'program') {
            $rules['audience'] = 'nullable|in:group,branch,company';
        }
        $payload = Validator::make($payload, $rules)->validated();

        return DB::transaction(function () use ($actor, $input, $id, $type, $payload) {
            [$actor] = app(GroupRecordWriteLock::class)->acquireMany($actor, [], [$payload['user_id'] ?? null], [$payload['branch_group_id'] ?? null]);
            $draft = $id ? KpiPlanningDraft::whereKey($id)->lockForUpdate()->firstOrFail() : new KpiPlanningDraft;
            if ($id) {
                abort_unless($draft->type === $type, 422);
                $this->scope->authorize($actor, $type, $draft->payload, $draft->created_by);
                if ($draft->revision !== $input['revision']) {
                    $this->conflict('DRAFT_VERSION_CONFLICT', $draft);
                }
            }
            $scope = $this->scope->authorize($actor, $type, $payload, $draft->created_by);
            $entityId = $input['entity_id'] ?? $draft->entity_id;
            if ($entityId) {
                abort_unless($type === 'program', 422);
                $rule = MotivationRule::findOrFail($entityId);
                abort_unless(app(MotivationProgramService::class)->canManage($actor, $rule), 403);
            }
            $month = $type === 'goal' ? ($payload['month'] ?? null) : (! empty($payload['date_from']) ? substr($payload['date_from'], 0, 7) : null);
            $key = ! empty($payload['user_id']) ? 'user:'.$payload['user_id'] : (! empty($payload['branch_group_id']) ? 'group:'.$payload['branch_group_id'] : null);
            $slot = $type === 'goal' && $month && $key && ! empty($payload['kind']) ? $month.':'.$key.':'.$payload['kind'] : null;
            if ($slot) {
                $existing = KpiPlanningDraft::where('slot', $slot)->when($id, fn ($q) => $q->where('id', '!=', $id))->lockForUpdate()->first();
                if ($existing) {
                    $this->conflict('DRAFT_ALREADY_EXISTS', $existing);
                }
            }
            // Capture the published baseline once. Resuming a stale draft must never silently adopt a newer version.
            if (! isset($payload['expected_version'])) {
                $payload['expected_version'] = $type === 'goal' && $slot
                    ? (int) (DB::table('kpi_result_goals')->where('scope_key', $key)->where('month', $month.'-01')->where('kind', $payload['kind'])->max('version') ?? 0)
                    : (int) ($entityId ? MotivationRule::findOrFail($entityId)->version : 0);
            }
            $draft->fill($scope + [
                'type' => $type, 'slot' => $slot, 'entity_id' => $entityId, 'month' => $month, 'payload' => $payload,
                'step' => min($input['step'], $type === 'goal' ? 3 : 4), 'revision' => ($draft->revision ?? 0) + 1,
                'created_by' => $draft->created_by ?? $actor->id, 'updated_by' => $actor->id,
            ])->save();

            return $draft->fresh();
        }, 3);
    }

    public function roster(User $actor, string $month): array
    {
        $this->scope->ensureManager($actor, 'goal');
        $groups = $this->scope->groups($actor);
        $users = $this->scope->users($actor)->orderBy('name')->orderBy('id')->get();
        $goals = DB::table('kpi_result_goals')->where('month', $month.'-01')->orderByDesc('version')->get()->unique(fn ($g) => $g->scope_key.':'.$g->kind)->keyBy(fn ($g) => $g->scope_key.':'.$g->kind);
        $drafts = $this->drafts($actor, 'goal', $month);
        $rows = $users->map(function ($user) use ($goals, $drafts, $groups) {
            $row = ['user_id' => $user->id, 'name' => $user->name, 'branch_id' => $user->branch_id,
                'branch_group_id' => $user->branch_group_id, 'branch_group_name' => $groups->firstWhere('id', $user->branch_group_id)?->name];
            foreach (['sale', 'rental'] as $kind) {
                $goal = $goals->get('user:'.$user->id.':'.$kind) ?? $goals->get('group:'.$user->branch_group_id.':'.$kind);
                $personalDraft = $drafts->first(fn ($d) => $d->user_id === $user->id && ($d->payload['kind'] ?? null) === $kind);
                $groupDraft = $drafts->first(fn ($d) => ! $d->user_id && $d->branch_group_id === $user->branch_group_id && ($d->payload['kind'] ?? null) === $kind);
                $draft = $personalDraft ?? (! $goal?->user_id ? $groupDraft : null);
                $row[$kind] = ['goal' => $goal, 'draft_id' => $draft?->id, 'draft' => $draft,
                    'status' => $goal ? 'published' : ($draft ? 'draft' : 'no_goal'),
                    'source' => $goal ? ($goal->user_id ? 'personal' : 'group') : null];
            }

            return $row;
        });
        $counts = fn (Collection $items, string $kind) => [
            'total' => $items->count(), 'published' => $items->where($kind.'.status', 'published')->count(),
            'draft' => $items->where($kind.'.status', 'draft')->count(), 'no_goal' => $items->where($kind.'.status', 'no_goal')->count(),
        ];

        return ['month' => $month, 'rows' => $rows->values(), 'drafts' => $drafts,
            'counts' => ['sale' => $counts($rows, 'sale'), 'rental' => $counts($rows, 'rental')],
            'groups' => $groups->map(fn ($g) => $g->toArray() + [
                'sale' => $counts($rows->where('branch_group_id', $g->id), 'sale'),
                'rental' => $counts($rows->where('branch_group_id', $g->id), 'rental'),
            ])->values()];
    }

    public function readiness(User $actor, array $data): array
    {
        $this->scope->authorize($actor, 'program', $data);
        $members = $this->scope->recipients($actor, $data);
        $drafts = $this->draftsForReadiness($data['month'], false);
        $rows = $members->map(function ($user) use ($drafts, $data) {
            $goal = app(ResultGoalService::class)->resolve($user->id, $user->branch_group_id, $data['month'], $data['kind']);
            $hasDraft = $drafts->contains(fn ($d) => ($d->payload['kind'] ?? null) === $data['kind'] && ($d->user_id === $user->id || (! $d->user_id && $d->branch_group_id === $user->branch_group_id)));

            return ['user_id' => $user->id, 'name' => $user->name, 'status' => $goal ? 'published' : ($hasDraft ? 'draft' : 'no_goal')];
        });

        return ['recipients' => $rows, 'ready' => $rows->where('status', 'published')->count(),
            'waiting' => $rows->where('status', '!=', 'published')->count()];
    }

    /** Validate complete input and calculate exactly what the publication will affect. */
    private function review(User $actor, KpiPlanningDraft $draft, bool $lock): array
    {
        $data = $draft->payload;
        $this->scope->authorize($actor, $draft->type, $data, $draft->created_by);
        $rules = $draft->type === 'goal' ? ResultGoalService::rules($data) : MotivationProgramService::rules($data, (bool) $draft->entity_id);
        if ($draft->type === 'program') {
            // This editor always creates explicit result-based programs, never a legacy rule by accident.
            $rules['condition'] = 'required|array:source,kind,mode,count_target,amount_target,currency,rental_basis,amount_basis';
            $rules['condition.source'] = 'required|in:kpi_goal,independent';
            if (($data['reward_type'] ?? null) !== 'cash') {
                $rules['ui_meta.reward_details.title'] = 'required|string|max:255';
                $rules['ui_meta.reward_details.included'] = 'required|string|max:1500';
                $rules['ui_meta.reward_details.arrangement'] = 'required|string|max:1000';
            }
            $rules['audience'] = 'required|in:group,branch,company';
            if (($data['audience'] ?? null) !== 'group') {
                $rules['branch_group_id'] = 'prohibited';
            }
            if (($data['audience'] ?? null) === 'company') {
                $rules['branch_id'] = 'prohibited';
            }
            if (! $draft->entity_id) {
                unset($data['expected_version']);
            }
            if (($data['audience'] ?? null) === 'group') {
                $rules['branch_group_id'] = 'required|integer|exists:branch_groups,id';
            }
            if (($data['audience'] ?? null) === 'branch') {
                $rules['branch_id'] = 'required|integer|exists:branches,id';
            }
        }
        $data = Validator::make($data, $rules)->validated();
        $recipients = $this->scope->recipients($actor, $data, $lock);
        $members = $recipients->map(fn ($u) => ['user_id' => $u->id, 'name' => $u->name, 'branch_id' => $u->branch_id, 'branch_group_id' => $u->branch_group_id]);
        $today = CarbonImmutable::now(MotivationService::TZ);
        if ($draft->type === 'goal') {
            $key = ! empty($data['user_id']) ? 'user:'.$data['user_id'] : 'group:'.$data['branch_group_id'];
            $query = DB::table('kpi_result_goals')->where('scope_key', $key)->where('month', $data['month'].'-01')->where('kind', $data['kind'])->latest('version');
            $current = ($lock ? $query->lockForUpdate() : $query)->first();
            if ((int) ($current?->version ?? 0) !== (int) $data['expected_version']) {
                $this->conflict('KPI_GOAL_VERSION_CONFLICT', $current);
            }
            $personalUser = ! empty($data['user_id']) ? $recipients->first() : null;
            $appliedGoal = $personalUser ? app(ResultGoalService::class)->resolve($personalUser->id, $personalUser->branch_group_id, $data['month'], $data['kind'], $lock) : null;
            if ($data['month'] < $today->format('Y-m') || (($current || $appliedGoal) && $data['month'] === $today->format('Y-m'))) {
                throw ValidationException::withMessages(['month' => 'Опубликованный текущий месяц и прошлые периоды зафиксированы. Скопируйте цель на будущий месяц.']);
            }
            $excluded = collect();
            $effective = collect();
            foreach ($recipients as $user) {
                $goal = app(ResultGoalService::class)->resolve($user->id, $user->branch_group_id, $data['month'], $data['kind'], $lock);
                $effective->push(['user_id' => $user->id, 'goal' => $goal]);
                if (empty($data['user_id']) && ! empty($goal['user_id'])) {
                    $excluded->push($user->id);
                }
            }
            $result = ['current' => $current, 'effective' => $effective, 'recipients' => $members->whereNotIn('user_id', $excluded)->values(),
                'excluded' => $members->whereIn('user_id', $excluded)->values(), 'condition' => $data, 'inherits_for_new_members' => empty($data['user_id'])];
        } else {
            $from = $data['date_from'];
            $to = $data['date_to'];
            app(MotivationService::class)->validatePeriod($data['period_type'], $from, $to);
            $current = $draft->entity_id ? MotivationRule::whereKey($draft->entity_id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail() : null;
            if ($current) {
                abort_unless(app(MotivationProgramService::class)->canManage($actor, $current), 403);
                if ($current->version !== (int) $data['expected_version']) {
                    $this->conflict('MOTIVATION_PROGRAM_VERSION_CONFLICT', $current);
                }
                if ($current->date_from->lte($today) || $current->achievements()->exists()) {
                    throw ValidationException::withMessages(['date_from' => 'Программа уже началась. Скопируйте её на будущий период.']);
                }
            }
            if ($from < $today->toDateString()) {
                throw ValidationException::withMessages(['date_from' => 'Начало программы не может быть в прошлом.']);
            }
            $linked = $data['condition']['source'] === 'kpi_goal';
            if ($linked && ($data['scope'] !== 'agent' || $data['period_type'] !== 'month' || substr($from, 8) !== '01' || $to !== CarbonImmutable::parse($from)->endOfMonth()->toDateString())) {
                throw ValidationException::withMessages(['date_to' => 'Связанная цель KPI требует полного календарного месяца.']);
            }
            $ready = collect();
            $waiting = collect();
            $draftGoals = $linked ? $this->draftsForReadiness(substr($from, 0, 7), $lock) : collect();
            foreach ($recipients as $user) {
                $goal = $linked ? app(ResultGoalService::class)->resolve($user->id, $user->branch_group_id, substr($from, 0, 7), $data['condition']['kind'], $lock) : null;
                $row = ['user_id' => $user->id, 'name' => $user->name, 'goal_id' => $goal['id'] ?? null];
                if (! $linked || $goal) {
                    $ready->push($row);
                } else {
                    $hasDraft = $draftGoals->contains(fn ($d) => ($d->payload['kind'] ?? null) === $data['condition']['kind'] && ($d->user_id === $user->id || (! $d->user_id && $d->branch_group_id === $user->branch_group_id)));
                    $waiting->push($row + ['status' => $hasDraft ? 'draft' : 'no_goal']);
                }
            }
            $overlaps = MotivationRule::where('is_active', true)->where('date_from', '<=', $to)->where('date_to', '>=', $from)
                ->when($draft->entity_id, fn ($q) => $q->where('id', '!=', $draft->entity_id))->orderBy('id');
            $overlaps = ($lock ? $overlaps->lockForUpdate() : $overlaps)->get()->filter(fn ($rule) => $rule->scope === $data['scope']
                && ($data['scope'] === 'company' || $recipients->contains(fn ($u) => (! $rule->branch_id || $rule->branch_id === $u->branch_id) && (! $rule->branch_group_id || $rule->branch_group_id === $u->branch_group_id))));
            $combined = $recipients->map(function ($user) use ($overlaps, $data) {
                $totals = [];
                if ($data['reward_type'] === 'cash') {
                    $totals[$data['reward_currency']] = round((float) $data['reward_amount'], 2);
                }
                foreach ($overlaps as $rule) {
                    if ($rule->reward_type === 'cash' && (! $rule->branch_id || $rule->branch_id === $user->branch_id) && (! $rule->branch_group_id || $rule->branch_group_id === $user->branch_group_id)) {
                        $totals[$rule->reward_currency] = round(($totals[$rule->reward_currency] ?? 0) + (float) $rule->reward_amount, 2);
                    }
                }

                return ['user_id' => $user->id, 'name' => $user->name, 'totals' => $totals];
            });
            $result = ['current' => $current, 'recipients' => $members, 'ready' => $ready, 'waiting' => $waiting,
                'overlaps' => $overlaps->map(fn ($r) => $r->only(['id', 'name', 'version', 'reward_type', 'reward_amount', 'reward_currency', 'date_from', 'date_to']))->values(),
                'combined_rewards' => $combined, 'potential_amount' => $data['reward_type'] === 'cash' ? round((float) $data['reward_amount'] * ($data['scope'] === 'company' ? 1 : $members->count()), 2) : null,
                'reward_currency' => $data['reward_currency'] ?? null, 'program' => $data, 'scheduled' => $from > $today->toDateString()];
        }
        $token = hash('sha256', json_encode([$actor->id, $actor->access_scope_version, $draft->id, $draft->revision, $data, $result], JSON_THROW_ON_ERROR));

        return $result + ['preview_token' => $token, 'revision' => $draft->revision];
    }

    private function draftsForReadiness(string $month, bool $lock): Collection
    {
        // A motivation manager can inspect readiness in their authorized audience even if goal editing is disabled.
        $query = KpiPlanningDraft::where('type', 'goal')->where('month', $month);

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    public function inspect(User $actor, int $id, int $revision, ?string $token = null): array
    {
        return DB::transaction(function () use ($actor, $id, $revision, $token) {
            $draft = KpiPlanningDraft::findOrFail($id);
            [$actor] = app(GroupRecordWriteLock::class)->acquireMany($actor, [], [$draft->user_id], [$draft->branch_group_id]);
            $draft = KpiPlanningDraft::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->scope->authorize($actor, $draft->type, $draft->payload, $draft->created_by);
            if ($draft->revision !== $revision) {
                $this->conflict('DRAFT_VERSION_CONFLICT', $draft);
            }
            if ($token !== null && $draft->type === 'program') {
                DB::table('branches')->when($draft->branch_id, fn ($q) => $q->where('id', $draft->branch_id))->orderBy('id')->lockForUpdate()->get(['id']);
            }
            $preview = $this->review($actor, $draft, $token !== null);
            if ($token === null) {
                return $preview;
            }
            if (! hash_equals($preview['preview_token'], $token)) {
                $this->conflict('PUBLICATION_PREVIEW_CHANGED', $preview);
            }
            $data = $draft->type === 'goal' ? $preview['condition'] : $preview['program'];
            unset($data['audience']);
            $published = $draft->type === 'goal' ? app(ResultGoalService::class)->publish($actor, $data)
                : app(MotivationProgramService::class)->save($actor, $data, $draft->entity_id)->toArray();
            $draft->delete();

            return ['published' => $published, 'type' => $draft->type];
        }, 3);
    }

    public function copyMonth(User $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $data) {
            [$actor] = app(GroupRecordWriteLock::class)->acquireMany($actor, [], [], [$data['branch_group_id'] ?? null]);
            $this->scope->ensureManager($actor, 'goal');
            if (! empty($data['branch_group_id'])) {
                $this->scope->authorize($actor, 'goal', $data);
            }
            abort_unless($data['to'] > $data['from'] && $data['to'] >= CarbonImmutable::now(MotivationService::TZ)->format('Y-m'), 422, 'Выберите текущий или будущий месяц после исходного.');
            $users = $this->scope->recipients($actor, $data, true)->pluck('id');
            $groups = $this->scope->groups($actor)->when(! empty($data['branch_group_id']), fn ($c) => $c->where('id', $data['branch_group_id']))->pluck('id');
            $goals = DB::table('kpi_result_goals')->where('month', $data['from'].'-01')->orderByDesc('version')->get()->unique(fn ($g) => $g->scope_key.':'.$g->kind)
                ->filter(fn ($g) => $g->user_id ? $users->contains($g->user_id) : $groups->contains($g->branch_group_id));
            $created = [];
            $skipped = [];
            foreach ($goals as $goal) {
                $slot = $data['to'].':'.$goal->scope_key.':'.$goal->kind;
                $exists = ($goal->user_id && app(ResultGoalService::class)->resolve($goal->user_id, $goal->branch_group_id, $data['to'], $goal->kind)) || DB::table('kpi_result_goals')->where('scope_key', $goal->scope_key)->where('month', $data['to'].'-01')->where('kind', $goal->kind)->exists()
                    || KpiPlanningDraft::where('slot', $slot)->exists();
                if ($exists) {
                    $skipped[] = $goal->scope_key.':'.$goal->kind;

                    continue;
                }
                $payload = array_intersect_key((array) $goal, ResultGoalService::rules());
                if ($goal->user_id) {
                    unset($payload['branch_group_id']);
                } else {
                    unset($payload['user_id']);
                }
                $payload['month'] = $data['to'];
                $payload['expected_version'] = 0;
                $created[] = $this->save($actor, ['type' => 'goal', 'payload' => $payload, 'step' => 2, 'revision' => 0])->id;
            }

            return ['created_ids' => $created, 'skipped' => $skipped];
        }, 3);
    }
}

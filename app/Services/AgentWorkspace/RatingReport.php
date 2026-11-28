<?php

namespace App\Services\AgentWorkspace;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RatingReport
{
    public const VERSION = 'aura-listing-participants-v3';

    public function __construct(private RatingAccess $access, private ListingPerformance $listings) {}

    public function build(User $actor, string $month, string $category, ?string $requestedScope): array
    {
        $scope = $this->access->resolve($actor, $requestedScope);

        return DB::transaction(function () use ($actor, $month, $category, $scope) {
            $start = CarbonImmutable::parse($month.'-01', 'Asia/Dushanbe');
            $now = CarbonImmutable::now('Asia/Dushanbe');
            $asOf = $now->utc()->toIso8601String();
            $agents = [];
            $details = [];
            $visibleTransactions = [];
            $incomplete = [];
            $invalidFx = [];
            foreach ($this->listings->forMonth($month) as $closing) {
                foreach ($closing['participants'] as $participant) {
                    if (! $participant['agent_id'] || ! $this->access->matches($actor, $participant, $scope)
                        || ! $this->listings->matchesCategory($closing, $participant, $category)) {
                        continue;
                    }
                    if (! $closing['closed_at'] || $participant['credit_minor'] === null) {
                        $incomplete[$closing['property_id']] = true;
                    }
                    if (! $closing['closed_at']) {
                        continue;
                    }
                    if ($participant['commission_issue'] === 'invalid_fx_rate') {
                        $invalidFx[$closing['property_id']] = true;
                    }
                    $id = $participant['agent_id'];
                    $agents[$id] ??= ['agent_id' => $id, 'score_minor' => 0, '_deals' => [], '_branches' => []];
                    $agents[$id]['score_minor'] += $participant['credit_minor'] ?? 0;
                    $agents[$id]['_deals'][$closing['property_id']] = true;
                    $visibleTransactions[$closing['property_id']] = true;
                    if ($participant['branch_id']) {
                        $agents[$id]['_branches'][$participant['branch_id']] = true;
                    }
                    $details[$id][] = ['transaction_id' => $closing['property_id'], 'property_id' => $closing['property_id'],
                        'deal_id' => null, 'kind' => $closing['kind'], 'closed_at' => $closing['closed_at'],
                        'commission' => $participant['commission'], 'currency' => $participant['currency'],
                        'fx_rate' => $closing['fx_rate'], 'role' => $participant['role'],
                        'credit_minor' => $participant['credit_minor'], 'commission_issue' => $participant['commission_issue'],
                        'source' => 'listing_participant'];
                }
            }
            $users = DB::table('users')->whereIn('id', array_keys($agents))->get()->keyBy('id');
            $branches = DB::table('branches')->pluck('name', 'id');
            $publicRoles = \App\Models\Role::query()->withPermission('public_team.subject.profile')->pluck('id')->all();
            $rows = [];
            foreach ($agents as $id => $row) {
                $user = $users->get($id);
                $rows[] = ['agent_id' => $id, 'name' => $user?->name ?? 'Бывший сотрудник', 'photo' => $user?->photo ?? null,
                    'offices' => array_values(array_map(fn ($b) => $branches[$b] ?? 'Архивный филиал', array_keys($row['_branches']))),
                    'score_minor' => $row['score_minor'], 'deal_count' => count($row['_deals']),
                    'profile_url' => $user && $user->status === 'active' && in_array($user->role_id, $publicRoles) ? '/about/team/'.$id : null, 'can_view_details' => $actor->hasPermission('agent_rating.details'),
                    'previous_rank' => null, 'delta_rank' => null, 'is_new' => false];
            }
            $rows = CommissionMath::rank($rows);
            $comparisonKey = hash('sha256', json_encode([$scope, $category, self::VERSION, $this->access->fingerprint($actor)]));
            $elapsed = $month === $now->format('Y-m') ? (int) $start->diffInMinutes($now) : 100000;
            // Only observed snapshots are comparable; never rebuild a historical position with today's corrections.
            $previousMonth = $start->subMonth()->format('Y-m');
            $previous = DB::table('agent_rating_snapshots')->where('comparison_key', $comparisonKey)->where('month', $previousMonth)
                ->where('elapsed_minute', $elapsed)->where('created_at', '<', $start->utc())->oldest('created_at')->first();
            $oldRows = $previous ? collect($this->payloadOf($previous)['rows'])->keyBy('agent_id') : collect();
            foreach ($rows as &$row) {
                $before = $oldRows->get($row['agent_id']);
                $row['previous_rank'] = $before['rank'] ?? null;
                $row['delta_rank'] = $row['rank'] && $row['previous_rank'] ? $row['previous_rank'] - $row['rank'] : null;
                $row['is_new'] = (bool) ($previous && $row['rank'] && ! ($before['rank'] ?? null));
                if ($scope === 'own') {
                    $row['rank'] = null;
                    $row['shared_rank'] = false;
                    $row['previous_rank'] = null;
                    $row['delta_rank'] = null;
                    $row['is_new'] = false;
                }
            }
            unset($row);
            $excluded = count($incomplete);
            $me = collect($rows)->firstWhere('agent_id', $actor->id) ?? ['agent_id' => $actor->id, 'name' => $actor->name, 'photo' => $actor->photo,
                'offices' => [], 'score_minor' => 0, 'deal_count' => 0, 'rank' => null, 'shared_rank' => false,
                'previous_rank' => null, 'delta_rank' => null, 'is_new' => false, 'profile_url' => null, 'can_view_details' => $actor->hasPermission('agent_rating.details')];
            $overview = $scope !== 'own' && $actor->hasPermission('agent_rating.scope.all') && ! $actor->usesAssignedGroupScope();
            $summary = null;
            if ($overview) {
                $summary = ['score_minor' => array_sum(array_column($rows, 'score_minor')), 'deal_count' => count($visibleTransactions),
                    'agent_count' => count($rows), 'recorded_unranked_minor' => 0, 'recorded_unranked_count' => 0];
            }
            $payload = ['snapshot_id' => (string) Str::uuid(), 'rules_version' => self::VERSION, 'period' => $month,
                'timezone' => 'Asia/Dushanbe', 'currency' => 'TJS', 'scope' => $scope, 'scopes' => $this->access->scopes($actor),
                'category' => $category, 'as_of' => $asOf, 'status' => 'provisional', 'data_source' => 'listing_participants',
                'period_version' => 0, 'data_quality' => $excluded ? 'partial' : 'complete', 'excluded_count' => $excluded, 'pending_count' => 0,
                'invalid_fx_count' => count($invalidFx),
                'total_ranked' => $scope === 'own' ? null : count(array_filter($rows, fn ($r) => $r['rank'] !== null)), 'cutoff_rank' => 15,
                'summary' => $summary, 'show_personal_result' => ! $overview || $me['deal_count'] > 0,
                'leaders' => $scope === 'own' ? [] : array_values(array_filter($rows, fn ($r) => $r['rank'] === 1)), 'rows' => $rows, 'me' => $me,
                'capabilities' => ['peers' => $scope !== 'own', 'prepare' => false,
                    'confirm' => false, 'close' => false, 'reopen' => false], '_details' => $details];
            $content = $payload;
            unset($content['snapshot_id'], $content['as_of']);
            $encoded = json_encode($content, JSON_THROW_ON_ERROR);
            $hash = hash('sha256', $encoded);
            DB::table('agent_rating_snapshot_contents')->insertOrIgnore(['hash' => $hash, 'payload' => $encoded]);
            $existing = DB::table('agent_rating_snapshots')->where('comparison_key', $comparisonKey)->where('month', $month)
                ->where('elapsed_minute', $elapsed)->where('content_hash', $hash)->where('created_at', '>=', now()->utc()->startOfMinute())->first();
            if ($existing) {
                return $this->payloadOf($existing);
            }
            DB::table('agent_rating_snapshots')->insert(['id' => $payload['snapshot_id'], 'actor_id' => $actor->id,
                'access_hash' => $this->access->fingerprint($actor), 'comparison_key' => $comparisonKey, 'month' => $month,
                'elapsed_minute' => $elapsed, 'content_hash' => $hash, 'created_at' => now()->utc()]);

            return $payload;
        });
    }

    public function snapshot(User $actor, string $id): array
    {
        $row = DB::table('agent_rating_snapshots')->where('id', $id)->where('actor_id', $actor->id)->first();
        abort_unless($row, 404, 'Снимок рейтинга не найден.');
        abort_unless(hash_equals($row->access_hash, $this->access->fingerprint($actor)), 403, 'Права изменились. Обновите рейтинг.');

        return $this->payloadOf($row);
    }

    private function payloadOf(object $snapshot): array
    {
        $content = DB::table('agent_rating_snapshot_contents')->where('hash', $snapshot->content_hash)->value('payload');
        abort_unless($content, 410, 'Снимок истёк. Обновите рейтинг.');

        return json_decode($content, true) + ['snapshot_id' => $snapshot->id, 'as_of' => CarbonImmutable::parse($snapshot->created_at, 'UTC')->toIso8601String()];
    }

    public function publicPayload(array $payload, bool $all = false, int $offset = 0): array
    {
        unset($payload['_details']);
        $rows = $all ? $payload['rows'] : array_values(array_filter($payload['rows'], fn ($r) => $r['rank'] !== null && $r['rank'] <= 15));
        $payload['rows'] = $all ? array_slice($rows, $offset, 50) : $rows;
        $payload['next_cursor'] = $all && count($rows) > $offset + 50 ? $offset + 50 : null;

        return $payload;
    }

    public function breakdown(User $actor, array $snapshot, int $agentId): array
    {
        abort_unless($actor->hasPermission('agent_rating.details'), 403);
        $rows = $snapshot['_details'][$agentId] ?? [];
        $visible = [];
        $hidden = 0;
        foreach ($rows as $row) {
            $canRead = $agentId === (int) $actor->id;
            if (! $canRead && $row['property_id']) {
                // Aggregate rating permission never implies access to property financial details.
                $canRead = $actor->hasPermission('agent_rating.confirm') && $actor->hasPermission('agent_rating.scope.all') && ! $actor->usesAssignedGroupScope();
            }
            if ($canRead) {
                $visible[] = $row;
            } else {
                $hidden += $row['credit_minor'] ?? 0;
            }
        }
        abort_unless($rows || $agentId === (int) $actor->id, 404);

        return ['snapshot_id' => $snapshot['snapshot_id'], 'agent_id' => $agentId, 'currency' => 'TJS', 'rows' => $visible, 'restricted_credit_minor' => $hidden,
            'total_minor' => array_sum(array_column($rows, 'credit_minor')), 'as_of' => $snapshot['as_of']];
    }
}

<?php

namespace App\Services\Marketing;

use App\Models\Booking;
use App\Models\CrmAuditLog;
use App\Models\CrmTask;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Property;
use App\Models\User;
use App\Support\ClientPhone;
use App\Support\LeadAccess;
use App\Support\RopGroupAccess;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MarketingLeadReport
{
    public const READ_ROLES = ['admin', 'superadmin', 'marketing', 'branch_director', 'rop', 'agent', 'mop'];

    public const REVIEW_ROLES = ['admin', 'superadmin', 'branch_director', 'rop'];

    public function ensureReader(User $actor): void
    {
        abort_unless(in_array($actor->role?->slug, self::READ_ROLES, true), 403);
    }

    public function salesQuery(User $actor): Builder
    {
        $q = Property::query()->where('moderation_status', 'sold')->whereNotNull('sold_at');
        if ($actor->usesAssignedGroupScope()) {
            return app(RopGroupAccess::class)->scope($q, $actor, 'properties.branch_group_id', 'properties.branch_id');
        }
        if (! in_array($actor->role?->slug, ['admin', 'superadmin', 'marketing'], true)) {
            $q->where('branch_id', $actor->branch_id ?: -1);
            if (in_array($actor->role?->slug, ['agent', 'mop'], true)) {
                $q->where('sale_user_id', $actor->id);
            }
        }

        return $q;
    }

    public function report(User $actor, array $filters): array
    {
        $this->ensureReader($actor);
        $from = Carbon::parse($filters['date_from'], 'Asia/Dushanbe')->startOfDay();
        $to = Carbon::parse($filters['date_to'], 'Asia/Dushanbe')->endOfDay();
        $horizon = (int) ($filters['horizon_days'] ?? 90);
        $now = Carbon::now('Asia/Dushanbe');
        $q = app(LeadAccess::class)->visibleQuery($actor)->withTrashed()
            ->join('marketing_lead_receipts as mr', 'mr.lead_id', '=', 'leads.id')
            ->whereBetween('mr.received_at', [$from, $to])
            ->select('leads.*', 'mr.phone_key as receipt_phone', 'mr.source as receipt_source', 'mr.campaign as receipt_campaign',
                'mr.classification as receipt_classification', 'mr.returning_confirmed', 'mr.classification_reason', 'mr.historical as receipt_historical', 'mr.received_at as receipt_at');
        if (! empty($filters['source'])) {
            $q->where('mr.source', $filters['source']);
        }
        if (! empty($filters['campaign'])) {
            $q->where('mr.campaign', $filters['campaign']);
        }
        if (! empty($filters['user_ids'])) {
            $ids = $filters['user_ids'];
            $q->whereExists(fn ($a) => $a->selectRaw('1')->from('marketing_lead_assignments as ma')
                ->whereColumn('ma.lead_id', 'leads.id')->whereIn('ma.agent_id', $ids));
        }
        abort_if((clone $q)->count() > 5000, 422, 'Слишком большой период: выберите меньше дат или источник (до 5000 заявок).');
        $leads = $q->orderBy('leads.id')->get();
        $end = $to->copy()->addDays($horizon)->min($now);
        $assignments = DB::table('marketing_lead_assignments')->whereIn('lead_id', $leads->modelKeys())->orderBy('id')->get()->groupBy('lead_id');
        $deals = Deal::query()->whereIn('lead_id', $leads->modelKeys())->get();
        $clientIds = $leads->pluck('client_id')->merge($leads->pluck('converted_client_id'))->filter()->unique();
        $calls = CrmAuditLog::query()->whereIn('event', ['call', 'show_completed'])->whereBetween('created_at', [$from, $end])
            ->where(function ($q) use ($leads, $deals) {
                $q->where(fn ($q) => $q->where('auditable_type', (new Lead)->getMorphClass())->whereIn('auditable_id', $leads->modelKeys()))
                    ->orWhere(fn ($q) => $q->where('auditable_type', (new Deal)->getMorphClass())->whereIn('auditable_id', $deals->modelKeys()));
            })->orderBy('created_at')->get();
        $bookings = Booking::query()->whereIn('crm_client_id', $clientIds)->whereBetween('start_time', [$from, $end])->get();
        $tasks = CrmTask::query()->where('status', 'done')->whereNotNull('result_code')->whereBetween('completed_at', [$from, $end])
            ->where(function ($q) use ($leads, $deals) {
                $q->where(fn ($q) => $q->where('related_entity_type', 'lead')->whereIn('related_entity_id', $leads->modelKeys()))
                    ->orWhere(fn ($q) => $q->where('related_entity_type', 'deal')->whereIn('related_entity_id', $deals->modelKeys()));
            })->get();
        $sales = $this->salesQuery($actor)->whereBetween('sold_at', [$from, $end])
            ->get(['id', 'branch_id', 'branch_group_id', 'buyer_client_id', 'buyer_phone', 'sold_at', 'sale_user_id']);
        $salesByPhone = $sales->groupBy(fn ($sale) => ClientPhone::normalize($sale->buyer_phone) ?: 'missing');
        $salesByClient = $sales->whereNotNull('buyer_client_id')->groupBy('buyer_client_id');
        $callsByEntity = $calls->groupBy(fn ($call) => $call->auditable_type.':'.$call->auditable_id);
        $tasksByEntity = $tasks->groupBy(fn ($task) => $task->related_entity_type.':'.$task->related_entity_id);
        $reviews = DB::table('marketing_sale_reviews')->whereIn('property_id', $sales->modelKeys())->get()->keyBy('property_id');
        // Only a unique explicit deal -> lead -> buyer path is automatically confirmed.
        $saleDeals = Deal::query()->whereIn('primary_property_id', $sales->modelKeys())->whereNotNull('lead_id')->get()->groupBy('primary_property_id');
        $rows = [];
        foreach ($leads as $lead) {
            $received = Carbon::parse($lead->receipt_at);
            $cutoff = $received->copy()->addDays($horizon)->min($now);
            $inWindow = fn ($date) => $date && Carbon::parse($date)->betweenIncluded($received, $cutoff);
            $leadDeals = $deals->where('lead_id', $lead->id);
            $dealIds = $leadDeals->modelKeys();
            $scopedCalls = collect($callsByEntity->get((new Lead)->getMorphClass().':'.$lead->id, []));
            $scopedTasks = collect($tasksByEntity->get('lead:'.$lead->id, []));
            foreach ($dealIds as $dealId) {
                $scopedCalls = $scopedCalls->concat($callsByEntity->get((new Deal)->getMorphClass().':'.$dealId, []));
                $scopedTasks = $scopedTasks->concat($tasksByEntity->get('deal:'.$dealId, []));
            }
            $leadCalls = $scopedCalls->filter(fn ($c) => $inWindow($c->created_at) &&
                (($c->auditable_type === (new Lead)->getMorphClass() && $c->auditable_id == $lead->id) ||
                ($c->auditable_type === (new Deal)->getMorphClass() && in_array($c->auditable_id, $dealIds))));
            $leadTasks = $scopedTasks->filter(fn ($t) => $inWindow($t->completed_at) &&
                (($t->related_entity_type === 'lead' && $t->related_entity_id == $lead->id) ||
                ($t->related_entity_type === 'deal' && in_array($t->related_entity_id, $dealIds))));
            $ids = array_filter([$lead->client_id, $lead->converted_client_id]);
            // A scheduled booking is not evidence that a showing took place.
            $shows = $bookings->filter(fn ($b) => in_array($b->crm_client_id, $ids) && $inWindow($b->start_time)
                && in_array($b->status, ['completed', 'done'], true));
            $history = collect($assignments->get($lead->id, []))->filter(fn ($a) => Carbon::parse($a->assigned_at)->lte($cutoff));
            $candidates = [];
            $possibleSales = collect($lead->receipt_phone ? $salesByPhone->get($lead->receipt_phone, []) : []);
            foreach ($ids as $clientId) {
                $possibleSales = $possibleSales->concat($salesByClient->get($clientId, []));
            }
            foreach ($possibleSales->unique('id') as $sale) {
                if (! $inWindow($sale->sold_at)) {
                    continue;
                }
                $buyerMatch = $sale->buyer_client_id && in_array($sale->buyer_client_id, $ids);
                $phoneMatch = $lead->receipt_phone && ClientPhone::normalize($sale->buyer_phone) === $lead->receipt_phone;
                if (! $buyerMatch && ! $phoneMatch) {
                    continue;
                }
                $links = collect($saleDeals->get($sale->id, []));
                $direct = $buyerMatch && $links->pluck('lead_id')->unique()->count() === 1 && $links->first()?->lead_id == $lead->id && $links->contains('client_id', $sale->buyer_client_id);
                $review = $reviews->get($sale->id);
                $state = $direct ? 'confirmed' : 'candidate';
                if ($review) {
                    $state = $review->lead_id == $lead->id ? ($review->decision === 'confirm' ? 'confirmed' : 'rejected')
                        : ($review->decision === 'confirm' ? 'attributed_elsewhere' : $state);
                }
                $incomeDeal = $links->count() === 1 ? $links->where('lead_id', $lead->id)->first() : null;
                $candidates[] = ['property_id' => $sale->id, 'sold_at' => $sale->sold_at,
                    'seller_id' => $sale->sale_user_id, 'state' => $state,
                    'evidence' => $review && $review->lead_id == $lead->id ? 'reviewed' : ($direct ? 'direct_link' : 'phone_or_client_match'),
                    'reason' => $review && $review->lead_id == $lead->id ? $review->reason : null,
                    'income' => $state === 'confirmed' ? $incomeDeal?->actual_company_income : null,
                    'income_currency' => $state === 'confirmed' ? $incomeDeal?->actual_company_income_currency : null];
            }
            $actions = $leadCalls->map(fn ($c) => ['actor_id' => $c->actor_id, 'at' => $c->created_at->toDateTimeString(), 'type' => $c->event === 'show_completed' ? 'show' : 'call',
                'outcome' => data_get($c->context, 'contact_outcome'), 'result' => data_get($c->new_values, 'result'), 'verification' => 'manual'])
                ->concat($leadTasks->map(fn ($t) => ['actor_id' => $t->assignee_id, 'at' => $t->completed_at->toDateTimeString(), 'type' => 'task', 'result' => $t->result_code, 'verification' => 'manual']))
                ->concat($shows->map(fn ($b) => ['actor_id' => $b->agent_id, 'at' => $b->start_time, 'type' => 'show', 'verification' => 'manual']))->sortBy('at')->values();
            $firstAssignment = $history->first(fn ($a) => $a->agent_id && ! $a->historical);
            $firstAction = $firstAssignment ? $actions->first(fn ($a) => $a['actor_id'] == $firstAssignment->agent_id && Carbon::parse($a['at'])->gte($firstAssignment->assigned_at)) : null;
            $latency = $firstAssignment && $firstAction ? max(0, Carbon::parse($firstAssignment->assigned_at)->diffInMinutes(Carbon::parse($firstAction['at']), false)) : null;
            $rows[] = ['lead_id' => $lead->id, 'name' => $lead->full_name, 'phone' => $lead->receipt_phone,
                'contact_key' => $lead->receipt_phone, 'source' => $lead->receipt_source, 'campaign' => $lead->receipt_campaign,
                'original_classification' => $lead->receipt_classification, 'classification' => $lead->returning_confirmed ? 'returning' : $lead->receipt_classification, 'classification_reason' => $lead->classification_reason, 'historical' => (bool) $lead->receipt_historical,
                'received_at' => $lead->receipt_at, 'cutoff_at' => $cutoff->toIso8601String(), 'mature' => $received->copy()->addDays($horizon)->lte($now),
                'current_agent_id' => $lead->responsible_agent_id, 'deleted' => $lead->trashed(),
                'assignments' => $history->values()->all(), 'actions' => $actions->all(), 'sales' => $candidates,
                'contacted' => $leadCalls->contains(fn ($c) => data_get($c->context, 'contact_outcome') === 'connected' || $c->event === 'show_completed') || $shows->isNotEmpty(),
                'first_action_minutes' => $latency, 'no_actions' => $actions->isEmpty(),
                'overdue' => $firstAssignment && ! $firstAction && Carbon::parse($firstAssignment->assigned_at)->addMinutes(Lead::DEFAULT_FIRST_CONTACT_SLA_MINUTES)->lt($cutoff),
                'no_next_step' => ! $lead->is_closed && ! $lead->next_activity_at && ! $lead->next_follow_up_at,
                'lost_reason' => $lead->lost_reason, 'status' => $lead->status];
        }
        if ($actor->usesAssignedGroupScope() || ! in_array($actor->role?->slug, ['admin', 'superadmin', 'marketing'], true)) {
            $visiblePeople = User::query();
            if ($actor->usesAssignedGroupScope()) {
                app(RopGroupAccess::class)->scope($visiblePeople, $actor, 'users.branch_group_id', 'users.branch_id');
            } else {
                $visiblePeople->where('branch_id', $actor->branch_id ?: -1);
            }
            $visibleIds = $visiblePeople->pluck('id')->all();
            foreach ($rows as &$row) {
                foreach (['assignments' => 'agent_id', 'actions' => 'actor_id', 'sales' => 'seller_id'] as $field => $identity) {
                    foreach ($row[$field] as &$item) {
                        if (is_object($item)) {
                            if (! in_array($item->{$identity}, $visibleIds)) {
                                $item->{$identity} = null;
                            }
                            if (! in_array($item->actor_id, $visibleIds)) {
                                $item->actor_id = null;
                            }
                        } elseif (! in_array($item[$identity], $visibleIds)) {
                            $item[$identity] = null;
                        }
                    }
                    unset($item);
                }
            }
            unset($row);
        }
        $agents = $this->agentStats(collect($rows));
        if (! empty($filters['user_ids'])) {
            $agents = array_values(array_filter($agents, fn ($a) => in_array($a['agent_id'], $filters['user_ids'])));
        }
        $names = User::query()->whereIn('id', collect($rows)->flatMap(fn ($r) => array_merge(
            array_column($r['assignments'], 'agent_id'), array_column($r['actions'], 'actor_id'), array_column($r['sales'], 'seller_id')))->filter()->unique())->pluck('name', 'id');
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = (int) ($filters['per_page'] ?? 25);

        return ['summary' => $this->summarize(collect($rows)), 'agents' => $agents, 'names' => (object) $names->all(),
            'rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'meta' => ['total' => count($rows), 'page' => $page, 'per_page' => $perPage, 'last_page' => max(1, (int) ceil(count($rows) / $perPage)),
                'date_from' => $from->toDateString(), 'date_to' => $to->toDateString(), 'horizon_days' => $horizon,
                'can_review' => $actor->hasPermission('leads.update') && in_array($actor->role?->slug, self::REVIEW_ROLES, true), 'as_of' => $now->toIso8601String()]];
    }

    public function summarize(Collection $rows): array
    {
        $contacts = fn (Collection $r) => $r->pluck('contact_key')->filter()->unique()->count();
        $confirmed = $rows->flatMap(fn ($r) => $r['sales'])->where('state', 'confirmed')->unique('property_id');
        $buyers = $contacts($rows->filter(fn ($r) => collect($r['sales'])->contains('state', 'confirmed')));
        $mature = $rows->where('mature', true);
        $matureBuyers = $contacts($mature->filter(fn ($r) => collect($r['sales'])->contains('state', 'confirmed')));

        return ['mature_conversion_pct' => $contacts($mature) ? round(100 * $matureBuyers / $contacts($mature), 1) : null, 'leads' => $rows->count(), 'unique_contacts' => $contacts($rows),
            'new_contacts' => $contacts($rows->where('classification', 'new')), 'repeat_leads' => $rows->where('classification', 'repeat')->count(),
            'returning_leads' => $rows->where('classification', 'returning')->count(), 'existing_leads' => $rows->where('classification', 'existing')->count(), 'unknown_phone' => $rows->whereNull('contact_key')->count(),
            'worked_contacts' => $contacts($rows->where('no_actions', false)), 'contacted' => $contacts($rows->where('contacted', true)),
            'shown_contacts' => $contacts($rows->filter(fn ($r) => collect($r['actions'])->contains('type', 'show'))),
            'no_actions' => $rows->pluck('contact_key')->filter()->unique()->diff($rows->where('no_actions', false)->pluck('contact_key'))->count(), 'overdue' => $rows->where('overdue', true)->count(),
            'mature_leads' => $rows->where('mature', true)->count(), 'historical_leads' => $rows->where('historical', true)->count(),
            'buying_contacts' => $buyers, 'sales' => $confirmed->count(),
            'conversion_pct' => $contacts($rows) ? round(100 * $buyers / $contacts($rows), 1) : null,
            'candidate_sales' => $rows->flatMap(fn ($r) => $r['sales'])->where('state', 'candidate')->unique('property_id')->count(),
            'income' => $confirmed->filter(fn ($s) => $s['income'] !== null && $s['income_currency'])
                ->groupBy('income_currency')->map(fn ($s) => round($s->sum('income'), 2))->all()];
    }

    private function agentStats(Collection $rows): array
    {
        $ids = $rows->flatMap(fn ($r) => array_merge(array_column($r['assignments'], 'agent_id'), array_column($r['sales'], 'seller_id')))->filter()->unique();

        return $ids->map(function ($id) use ($rows) {
            $received = $rows->filter(fn ($r) => collect($r['assignments'])->contains('agent_id', $id));
            $ownedAction = fn ($r, $action) => $action['actor_id'] == $id
                && collect($r['assignments'])->contains(fn ($a) => $a->agent_id == $id && Carbon::parse($action['at'])->gte($a->assigned_at)
                    && (! $a->ended_at || Carbon::parse($action['at'])->lt($a->ended_at)));
            $acted = $received->filter(fn ($r) => collect($r['actions'])->contains(fn ($action) => $ownedAction($r, $action)));
            $keys = fn ($r) => $r->pluck('contact_key')->filter()->unique()->count();
            $won = $rows->flatMap(fn ($r) => $r['sales'])->where('state', 'confirmed')->where('seller_id', $id)->unique('property_id');
            $buyers = $keys($received->filter(fn ($r) => collect($r['sales'])->contains(fn ($s) => $s['state'] === 'confirmed' && $s['seller_id'] == $id)));

            return ['agent_id' => (int) $id, 'received' => $keys($received), 'worked' => $keys($acted),
                'contacted' => $keys($acted->filter(fn ($r) => collect($r['actions'])->contains(fn ($a) => $ownedAction($r, $a) && (($a['outcome'] ?? '') === 'connected' || $a['type'] === 'show')))),
                'shown' => $keys($acted->filter(fn ($r) => collect($r['actions'])->contains(fn ($a) => $ownedAction($r, $a) && $a['type'] === 'show'))),
                'no_actions' => $received->pluck('contact_key')->filter()->unique()->diff($acted->pluck('contact_key'))->count(),
                'sales' => $won->count(), 'buying_contacts' => $buyers,
                'conversion_pct' => $keys($received) ? round(100 * $buyers / $keys($received), 1) : null];
        })->values()->all();
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\CrmAuditLog;
use App\Models\Deal;
use App\Models\DealPipeline;
use App\Models\DealStage;
use App\Models\User;
use App\Services\Crm\AuditLogger;
use App\Services\Crm\DealListQuery;
use App\Support\DealAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PropertyControlReportController extends Controller
{
    public function __construct(
        private readonly DealAccess $dealAccess,
        private readonly DealListQuery $dealListQuery,
        private readonly AuditLogger $auditLogger
    ) {}

    public function summary(Request $request)
    {
        [$user, $validated, $query] = $this->context($request);
        $deals = (clone $query)->get(['crm_deals.id', 'crm_deals.created_at', 'crm_deals.closed_at']);
        $metrics = $this->durationMetrics($deals);
        $total = $deals->count();

        $stageCounts = (clone $query)
            ->selectRaw('stage_id, COUNT(*) as aggregate')
            ->groupBy('stage_id')
            ->pluck('aggregate', 'stage_id');
        $stages = DealStage::query()->whereIn('id', $stageCounts->keys())->get()->keyBy('id');

        $payload = [
            'total' => $total,
            'open' => (clone $query)->whereNull('closed_at')->count(),
            'closed' => (clone $query)->whereNotNull('closed_at')->count(),
            'overdue' => (clone $query)->whereNull('closed_at')->whereNotNull('next_activity_at')->where('next_activity_at', '<', now())->count(),
            'by_stage' => $stageCounts->map(fn ($count, $id) => [
                'stage_id' => (int) $id,
                'slug' => $stages->get((int) $id)?->slug,
                'name' => $stages->get((int) $id)?->name,
                'count' => (int) $count,
            ])->values(),
            'by_branch' => $this->groupCounts($query, 'branch_id'),
            'by_source_status' => $this->groupCounts($query, 'source_property_status'),
            'by_responsible' => $this->groupCounts($query, 'responsible_agent_id'),
            'average_claim_minutes' => $metrics['claim'],
            'average_branch_response_minutes' => $metrics['branch_response'],
            'average_total_review_minutes' => $metrics['total_review'],
        ];

        $this->audit($user, 'property_control_report_viewed', $validated, $total);

        return response()->json($payload);
    }

    public function meta(Request $request)
    {
        [$user] = $this->context($request);
        $pipelines = DealPipeline::query()
            ->with(['branch:id,name', 'stages' => fn ($stages) => $stages->where('is_active', true)->orderBy('sort_order')])
            ->where('code', DealPipeline::CODE_PROPERTY_CONTROL)
            ->where('is_active', true)
            ->get();

        return response()->json([
            'branches' => $pipelines->pluck('branch')->filter()->unique('id')->values(),
            'stages' => $pipelines->flatMap->stages->unique('slug')->values(),
            'security_officers' => User::query()
                ->select(['id', 'name', 'branch_id'])
                ->where('status', User::STATUS_ACTIVE)
                ->whereHas('role', fn ($role) => $role->where('slug', 'security'))
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        [$user, $validated, $query] = $this->context($request);
        $count = (clone $query)->count();
        $this->audit($user, 'property_control_exported', $validated, $count);

        return response()->streamDownload(function () use ($query) {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, [
                'CRM ID', 'Объявление ID', 'Объект', 'Адрес', 'Филиал', 'Статус объявления',
                'Этап СБ', 'Ответственный СБ', 'Дата события', 'Срок', 'Закрыто',
                'Сумма', 'Валюта', 'Комиссия компании', 'Валюта комиссии',
            ], ';');

            (clone $query)
                ->with(['primaryProperty', 'branch', 'stage', 'responsibleAgent'])
                ->reorder('crm_deals.id')
                ->chunkById(200, function ($deals) use ($stream) {
                    foreach ($deals as $deal) {
                        fputcsv($stream, [
                            $deal->id,
                            $deal->primary_property_id,
                            $deal->primaryProperty?->title,
                            $deal->primaryProperty?->address,
                            $deal->branch?->name,
                            $deal->source_property_status,
                            $deal->stage?->name,
                            $deal->responsibleAgent?->name,
                            data_get($deal->meta, 'control.triggered_at'),
                            $deal->next_activity_at?->toIso8601String(),
                            $deal->closed_at?->toIso8601String(),
                            $deal->amount,
                            $deal->currency,
                            $deal->actual_company_income,
                            $deal->actual_company_income_currency,
                        ], ';');
                    }
                }, 'crm_deals.id', 'id');

            fclose($stream);
        }, 'property-control-'.now()->format('Y-m-d-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function context(Request $request): array
    {
        /** @var User $user */
        $user = Auth::user();
        abort_unless($user, 401, 'Unauthenticated.');
        $user->loadMissing('role');
        abort_unless(in_array($user->role?->slug, ['security', 'admin', 'superadmin'], true), 403, 'CRM_DEAL_FORBIDDEN');

        $validated = $request->validate($this->dealListQuery->rules());
        $validated['pipeline_type'] = 'property_control';
        $query = $this->dealListQuery->build($request, $user, $validated);

        return [$user, $validated, $query];
    }

    private function groupCounts($query, string $column): Collection
    {
        return (clone $query)
            ->selectRaw($column.', COUNT(*) as aggregate')
            ->groupBy($column)
            ->pluck('aggregate', $column)
            ->map(fn ($count, $value) => ['value' => $value === '' ? null : $value, 'count' => (int) $count])
            ->values();
    }

    private function durationMetrics(Collection $deals): array
    {
        if ($deals->isEmpty()) {
            return ['claim' => null, 'branch_response' => null, 'total_review' => null];
        }

        $logs = CrmAuditLog::query()
            ->where('auditable_type', (new Deal)->getMorphClass())
            ->whereIn('auditable_id', $deals->pluck('id'))
            ->where('event', 'status_change')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('auditable_id');

        $claim = [];
        $branchResponse = [];
        $totalReview = [];

        foreach ($deals as $deal) {
            $events = $logs->get($deal->id, collect());
            $claimAt = $events->first(fn ($log) => data_get($log->new_values, 'stage_slug') === 'security_review')?->created_at;
            if ($claimAt) {
                $claim[] = $deal->created_at->diffInMinutes($claimAt);
            }

            $clarificationAt = null;
            foreach ($events as $event) {
                $slug = data_get($event->new_values, 'stage_slug');
                if ($slug === 'branch_clarification') {
                    $clarificationAt = $event->created_at;
                } elseif ($slug === 'security_recheck' && $clarificationAt) {
                    $branchResponse[] = $clarificationAt->diffInMinutes($event->created_at);
                    $clarificationAt = null;
                }
            }

            if ($deal->closed_at) {
                $totalReview[] = $deal->created_at->diffInMinutes($deal->closed_at);
            }
        }

        return [
            'claim' => $this->average($claim),
            'branch_response' => $this->average($branchResponse),
            'total_review' => $this->average($totalReview),
        ];
    }

    private function average(array $values): ?float
    {
        return $values === [] ? null : round(array_sum($values) / count($values), 2);
    }

    private function audit(User $user, string $event, array $filters, int $count): void
    {
        $this->auditLogger->log(
            $user,
            $user,
            $event,
            [],
            ['result_count' => $count],
            $event === 'property_control_exported'
                ? 'Экспортирован отчёт CRM-контроля объектов.'
                : 'Просмотрен отчёт CRM-контроля объектов.',
            [
                'filter_keys' => array_values(array_diff(array_keys(array_filter($filters)), ['q', 'search'])),
            ]
        );
    }
}

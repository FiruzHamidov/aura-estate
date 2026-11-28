<?php

namespace App\Http\Controllers;

use App\Services\AgentWorkspace\RatingAccess;
use App\Services\AgentWorkspace\RatingLedger;
use App\Services\AgentWorkspace\RatingReport;
use App\Services\AgentWorkspace\RatingSources;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class AgentRatingController extends Controller
{
    public function __construct(private RatingReport $report, private RatingAccess $access, private RatingLedger $ledger, private RatingSources $sources) {}

    private function filters(Request $request): array
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m', 'after_or_equal:2020-01', 'before_or_equal:'.now('Asia/Dushanbe')->format('Y-m')],
            'category' => ['nullable', Rule::in(['all', 'main', 'assistant', 'partner', 'rental'])], 'scope' => ['nullable', 'string', 'max:40']]);

        return [$data['month'] ?? now('Asia/Dushanbe')->format('Y-m'), $data['category'] ?? 'all', $data['scope'] ?? null];
    }

    public function index(Request $request)
    {
        return response()->json($this->report->publicPayload($this->report->build($request->user(), ...$this->filters($request))))->header('Cache-Control', 'private, no-store');
    }

    public function participants(Request $request)
    {
        $v = $request->validate(['snapshot_id' => 'required|uuid', 'cursor' => 'nullable|integer|min:0']);

        return response()->json($this->report->publicPayload($this->report->snapshot($request->user(), $v['snapshot_id']), true, (int) ($v['cursor'] ?? 0)))->header('Cache-Control', 'private, no-store');
    }

    public function breakdown(Request $request, int $agent)
    {
        $v = $request->validate(['snapshot_id' => 'required|uuid']);

        return response()->json($this->report->breakdown($request->user(), $this->report->snapshot($request->user(), $v['snapshot_id']), $agent))->header('Cache-Control', 'private, no-store');
    }

    public function rules(Request $request)
    {
        $this->access->scopes($request->user());
        $request->validate(['version' => ['nullable', Rule::in([RatingReport::VERSION])]]);

        return response()->json(['version' => RatingReport::VERSION, 'currency' => 'TJS', 'timezone' => 'Asia/Dushanbe', 'rules' => [
            'Место определяется суммой комиссий агента из списка участников в попапе модерации объявления. Подтверждение в CRM не требуется.',
            'Учитываются объявления со статусом «Продано» или «Сдано» по дате закрытия. Снятые с продажи, удалённые и вновь открытые объявления не учитываются.',
            'Главные агенты, помощники и партнёры — роли из списка участников. Аренда — только сданные объявления. Один объект считается один раз для компании и один раз для каждого участника.',
            'Используется комиссия каждого участника. Доход компании и стоимость недвижимости не распределяются между агентами автоматически.',
            'USD переводится в сомони по сохранённому курсу объявления. Для защиты от ошибок ввода принимается курс от 1 до 100 TJS за 1 USD. Если комиссия или курс не указаны либо курс вне этого диапазона, денежная сумма не учитывается, а итог отмечается как неполный. Количество закрытий сохраняется.',
            'Если список участников пуст, закрытие учитывается у продавшего сотрудника без начисления комиссии.',
            'Равные суммы дают одинаковые места: 1, 1, 3. Нулевой или неизвестный денежный результат остаётся без места.',
            'Филиал и группа определяются по текущему месту работы участника. Изменения в модерации учитываются после обновления рейтинга.',
            'Изменение места показывается только при наличии сохранённого сопоставимого снимка за прошлый месяц.',
            'Рейтинг не начисляет зарплату, премии или штрафы автоматически.',
        ]]);
    }

    public function sources(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor->hasPermission('agent_rating.prepare') || $actor->hasPermission('agent_rating.confirm'), 403);
        [$month, , $requestedScope] = $this->filters($request);
        $scope = $this->access->resolve($actor, $requestedScope);
        $this->sources->sync();
        $start = CarbonImmutable::parse($month.'-01', 'Asia/Dushanbe');
        $query = DB::table('agent_rating_sources')->whereIn('state', ['closed', 'needs_link'])->whereNull('canonical_source_id')->where(fn ($q) => $q->whereNull('closed_at')->orWhere(fn ($q) => $q->where('closed_at', '>=', $start->utc())->where('closed_at', '<', $start->addMonth()->utc())));
        if ($scope !== 'all') {
            [$kind, $id] = array_pad(explode(':', $scope), 2, null);
            $query->where(match ($kind) {
                'branch' => 'branch_id', 'group' => 'branch_group_id', default => 'user_id'
            }, $id ?? $actor->id);
        }
        \App\Support\BranchVisibility::apply($query, 'agent_rating_sources.branch_id');
        $page = $query->orderByDesc('closed_at')->orderBy('id')->paginate(30);
        $page->getCollection()->transform(function ($s) use ($actor) {
            $s->canonical_candidates = $s->state === 'needs_link' ? DB::table('agent_rating_sources')->where('property_id', $s->property_id)->where('state', 'closed')->whereNull('canonical_source_id')->get(['id', 'closed_at', 'kind', 'branch_id', 'branch_group_id', 'user_id'])->filter(fn ($candidate) => collect($this->access->scopes($actor))->contains(fn ($scope) => $this->access->matches($actor, $candidate, $scope['key'])))->values() : [];
            $s->financials = json_decode($s->financials ?? '{}', true);
            $s->revisions = DB::table('agent_rating_revisions as r')->leftJoin('agent_rating_approvals as a', 'a.revision_id', '=', 'r.id')
                ->where('r.transaction_id', $s->id)->orderByDesc('r.id')->get(['r.*', 'a.actor_id as confirmed_by', 'a.created_at as confirmed_at'])
                ->map(function ($r) {
                    $r->payload = json_decode($r->payload, true);

                    return $r;
                });

            return $s;
        });

        return response()->json($page)->header('Cache-Control', 'private, no-store');
    }

    public function directory(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor->hasPermission('agent_rating.prepare') || $actor->hasPermission('agent_rating.confirm'), 403);
        $scopes = $this->access->scopes($actor);
        $visible = fn ($record) => collect($scopes)->contains(fn ($scope) => $this->access->matches($actor, $record, $scope['key']));
        $agents = \App\Models\User::query()->whereHas('role', fn ($q) => $q->withPermission('users.subject.team'))
            ->get(['id', 'name', 'branch_id', 'branch_group_id', 'status'])->filter(fn ($u) => $visible((object) ['user_id' => $u->id, 'branch_id' => $u->branch_id, 'branch_group_id' => $u->branch_group_id]))->values();
        $groups = DB::table('branch_groups')->get(['id', 'name', 'branch_id'])->filter(fn ($g) => $visible((object) ['branch_id' => $g->branch_id, 'branch_group_id' => $g->id]))->values();
        $branchIds = $agents->pluck('branch_id')->merge($groups->pluck('branch_id'))->filter()->unique();

        return response()->json(['agents' => $agents, 'groups' => $groups, 'branches' => DB::table('branches')->whereIn('id', $branchIds)->get(['id', 'name'])])->header('Cache-Control', 'private, no-store');
    }

    public function draft(Request $request, int $source)
    {
        $v = $request->validate([
            'event_key' => 'required|uuid', 'source_version' => 'required|integer|min:0', 'reason' => 'required|string|min:5|max:1000',
            'kind' => ['nullable', Rule::in(['sale', 'rental'])],
            'commission' => 'required|string|max:20', 'refund' => 'required|string|max:20', 'currency' => ['required', Rule::in(['TJS', 'USD'])],
            'fx_rate' => 'nullable|string|max:20', 'correction_reference' => 'nullable|string|max:500',
            'participants' => 'required|array|min:1|max:20', 'participants.*.agent_id' => 'nullable|integer|min:1',
            'participants.*.branch_id' => 'nullable|integer|min:1', 'participants.*.branch_group_id' => 'nullable|integer|min:1',
            'participants.*.side' => ['required', Rule::in(['listing', 'closing'])], 'participants.*.share_bps' => 'required|integer|min:1|max:10000',
        ]);

        return response()->json($this->ledger->draft($request->user(), $source, $v), 201);
    }

    public function link(Request $request, int $source)
    {
        $v = $request->validate(['canonical_id' => 'required|integer|min:1', 'reason' => 'required|string|min:5|max:1000']);
        $this->ledger->link($request->user(), $source, $v['canonical_id'], $v['reason']);

        return response()->json(['linked' => true]);
    }

    public function confirm(Request $request, int $revision)
    {
        $this->ledger->confirm($request->user(), $revision);

        return response()->json(['confirmed' => true]);
    }

    public function close(Request $request)
    {
        $v = $request->validate(['month' => 'required|date_format:Y-m', 'reason' => 'required|string|min:5|max:1000', 'expected_version' => 'required|integer|min:0']);

        return response()->json($this->ledger->close($request->user(), $v['month'], $v['reason'], $v['expected_version']));
    }

    public function history(Request $request)
    {
        $this->access->scopes($request->user());
        abort_unless($request->user()->hasPermission('agent_rating.prepare') || $request->user()->hasPermission('agent_rating.confirm'), 403);
        $v = $request->validate(['month' => 'required|date_format:Y-m']);

        return response()->json(DB::table('agent_rating_periods')->where('month', $v['month'])->orderByDesc('version')->get($request->user()->hasPermission('agent_rating.scope.all') && ! $request->user()->usesAssignedGroupScope() ? ['month', 'version', 'reason', 'created_at'] : ['month', 'version', 'created_at']));
    }
}

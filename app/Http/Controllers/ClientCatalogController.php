<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientNeed;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Shared needs catalog. Never reuse the full CRM client serializer here. */
final class ClientCatalogController extends Controller
{
    public function __construct(private readonly \App\Services\Crm\ClientCatalogService $catalog) {}

    public function counts(Request $request)
    {
        $lists = $this->catalog->lists($request->user());
        $counts = [];
        foreach ($lists as $list) {
            $counts[$list] = $this->catalog->clients([$list])->count();
        }

        // Clients without needs cannot overlap either budget list.
        $budgetLists = array_values(array_diff($lists, ['unattended']));
        $total = ($budgetLists === [] ? 0 : $this->catalog->clients($budgetLists)->count()) + ($counts['unattended'] ?? 0);

        return response()->json(['total' => $total, 'lists' => $counts])
            ->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request)
    {
        $lists = $this->catalog->lists($request->user());
        $data = $request->validate([
            'list' => ['sometimes', Rule::in($lists)],
            'search' => ['nullable', 'string', 'max:100', 'regex:/^[\p{L}\p{M}\s.\x{0027}\x{2019}-]*$/u'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ]);
        $list = $data['list'] ?? $lists[0];
        $query = $this->catalog->clients([$list])->select([
            'id', 'full_name', 'branch_id', 'responsible_agent_id', 'client_type_id', 'contact_kind', 'created_at',
            ...($list === 'unattended' ? ['phone'] : []),
        ])->with([
            'branch:id,name', 'responsibleAgent:id,name', 'type:id,name',
            'needs' => function (\Illuminate\Database\Eloquent\Relations\HasMany $needs) use ($list) {
                if ($list !== 'unattended') {
                    $this->catalog->needs($needs->getQuery(), [$list]);
                }
                $needs->with(['type:id,name', 'status:id,name', 'location:id,city',
                    'propertyTypes:id,name', 'propertyType:id,name', 'repairTypes:id,name', 'repairType:id,name']);
            },
        ]);
        if (! empty($data['search'])) {
            // Do not allow phone/email searches to act as an oracle for hidden contacts.
            $query->where('full_name', 'like', '%'.addcslashes(trim($data['search']), '%_\\').'%');
        }
        $page = $query->orderBy('created_at', $list === 'agents' ? 'desc' : 'asc')->orderBy('id')
            ->paginate($data['per_page'] ?? 20);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Client $client) => $this->clientPayload($client, $list === 'unattended'))->values(),
            'meta' => ['list' => $list, 'total' => $page->total(), 'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
                'inactive_days' => null],
        ])->header('Cache-Control', 'private, no-store');
    }

    private function claimant(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->hasPermission('clients.catalog.claim'), 403, 'Нет права брать клиентов из каталога.');

        return $user;
    }

    public function claimStatus(Request $request)
    {
        $user = $this->claimant($request);
        $usedToday = DB::table('client_catalog_claims')->where('user_id', $user->id)
            ->where('claimed_on', now('Asia/Dushanbe')->toDateString())->exists();
        return response()->json([
            'can_claim' => $user->branch_id !== null,
            'used_today' => $usedToday,
            'next_available_at' => null,
            'timezone' => 'Asia/Dushanbe',
            'message' => $user->branch_id ? 'Можно брать клиентов без дневного лимита.' : 'Для получения клиента руководитель должен назначить вам филиал.',
        ])->header('Cache-Control', 'private, no-store');
    }

    public function claim(Request $request, int $clientId)
    {
        $actor = $this->claimant($request);
        $client = $this->catalog->claim($actor, $clientId);
        $client->load($this->payloadRelations());

        return response()->json(['data' => $this->clientPayload($client, true)])->header('Cache-Control', 'private, no-store');
    }

    public function mine(Request $request)
    {
        $user = $this->claimant($request);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $page = Client::query()->where('responsible_agent_id', $user->id)
            ->whereIn('id', DB::table('client_catalog_claims')->where('user_id', $user->id)->select('client_id'))
            ->with($this->payloadRelations())->orderByDesc(DB::table('client_catalog_claims')->select('created_at')->whereColumn('client_id', 'clients.id')->limit(1))
            ->orderByDesc('id')->paginate(20);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Client $client) => $this->clientPayload($client, true))->values(),
            'meta' => ['list' => 'mine', 'total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()],
        ])->header('Cache-Control', 'private, no-store');
    }

    private function payloadRelations(): array
    {
        return ['branch:id,name', 'responsibleAgent:id,name', 'type:id,name', 'needs.type:id,name', 'needs.status:id,name',
            'needs.location:id,city', 'needs.propertyTypes:id,name', 'needs.propertyType:id,name', 'needs.repairTypes:id,name', 'needs.repairType:id,name'];
    }

    private function clientPayload(Client $client, bool $withPhone = false): array
    {
        $payload = [
            'id' => $client->id,
            'name' => $this->safeText($client->full_name),
            'branch_name' => $this->safeText($client->branch?->name),
            'responsible_agent_name' => $this->safeText($client->responsibleAgent?->name),
            'type_name' => $client->type?->name,
            'contact_kind' => $client->contact_kind,
            'created_at' => $client->created_at?->toISOString(),
            'needs' => $client->needs->map(fn (ClientNeed $need) => $this->needPayload($need))->values(),
        ];
        if ($withPhone) {
            $payload['phone'] = $client->phone;
        }

        return $payload;
    }

    private function needPayload(ClientNeed $need): array
    {
        return [
            'id' => $need->id,
            'type' => $need->type?->name,
            'status' => $need->status?->name,
            'location' => $need->location?->city,
            'district' => $this->safeText($need->district),
            'cash_on_hand_amount' => $need->cash_on_hand_amount,
            'budget_total' => $need->budget_total,
            'budget_from' => $need->budget_from,
            'budget_to' => $need->budget_to,
            'currency' => $need->currency,
            'rooms_from' => $need->rooms_from,
            'rooms_to' => $need->rooms_to,
            'area_from' => $need->area_from,
            'area_to' => $need->area_to,
            'wants_mortgage' => $need->wants_mortgage,
            'property_types' => $need->propertyTypes->isNotEmpty() ? $need->propertyTypes->pluck('name')->values()->all() : array_values(array_filter([$need->propertyType?->name])),
            'repair_types' => $need->repairTypes->isNotEmpty() ? $need->repairTypes->pluck('name')->values()->all() : array_values(array_filter([$need->repairType?->name])),
            'comment' => $this->safeText($need->comment),
        ];
    }

    private function safeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = strip_tags($value);
        $value = preg_replace('/[\w.+-]+@[\w.-]+\.[a-z]{2,}/iu', '[контакт скрыт]', $value);

        return preg_replace('/(?<!\d)\+?\d(?:[\s().-]*\d){6,}(?!\d)/u', '[номер скрыт]', $value);
    }
}

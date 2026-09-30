<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientNeed;
use App\Models\User;
use App\Services\Crm\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Shared needs catalog. Never reuse the full CRM client serializer here. */
final class ClientCatalogController extends Controller
{
    private function lists(User $user): array
    {
        $role = $user->role?->slug;
        abort_unless(in_array($role, ['intern', 'agent', 'rop', 'mop', 'admin', 'superadmin'], true), 403);

        return $role === 'intern' ? ['interns'] : ['agents', 'interns'];
    }

    private function needs(Builder $query, array $lists): void
    {
        $query->where('has_cash_on_hand', true)->where('currency', 'TJS')->whereNull('closed_at')
            ->where(fn (Builder $status) => $status->whereNull('status_id')
                ->orWhereHas('status', fn (Builder $statuses) => $statuses->where('is_closed', false)))
            ->where(function (Builder $amounts) use ($lists) {
                if (in_array('agents', $lists, true)) {
                    $amounts->orWhere('cash_on_hand_amount', '>=', 1000000);
                }
                if (in_array('interns', $lists, true)) {
                    $amounts->orWhereBetween('cash_on_hand_amount', [300000, 800000]);
                }
            });
    }

    private function clients(array $lists): Builder
    {
        // Deliberately shared across branches; all normal CRM read/write scopes stay intact.
        return Client::query()->whereNotIn('id', DB::table('client_catalog_claims')->select('client_id'))->where('status', 'active')->whereIn('contact_kind', ['buyer', 'both'])
            ->whereHas('needs', fn (Builder $needs) => $this->needs($needs, $lists));
    }

    public function counts(Request $request)
    {
        $lists = $this->lists($request->user());
        $counts = [];
        foreach ($lists as $list) {
            $counts[$list] = $this->clients([$list])->count();
        }

        return response()->json(['total' => $this->clients($lists)->count(), 'lists' => $counts])
            ->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request)
    {
        $lists = $this->lists($request->user());
        $data = $request->validate([
            'list' => ['sometimes', Rule::in($lists)],
            'search' => ['nullable', 'string', 'max:100', 'regex:/^[\p{L}\p{M}\s.\x{0027}\x{2019}-]*$/u'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ]);
        $list = $data['list'] ?? $lists[0];
        $query = $this->clients([$list])->select([
            'id', 'full_name', 'branch_id', 'responsible_agent_id', 'client_type_id', 'contact_kind', 'created_at',
        ])->with([
            'branch:id,name', 'responsibleAgent:id,name', 'type:id,name',
            'needs' => function (\Illuminate\Database\Eloquent\Relations\HasMany $needs) use ($list) {
                $this->needs($needs->getQuery(), [$list]);
                $needs->with(['type:id,name', 'status:id,name', 'location:id,city',
                    'propertyTypes:id,name', 'propertyType:id,name', 'repairTypes:id,name', 'repairType:id,name']);
            },
        ]);
        if (! empty($data['search'])) {
            // Do not allow phone/email searches to act as an oracle for hidden contacts.
            $query->where('full_name', 'like', '%'.addcslashes(trim($data['search']), '%_\\').'%');
        }
        $page = $query->orderBy('created_at', $list === 'interns' ? 'asc' : 'desc')->orderBy('id')
            ->paginate($data['per_page'] ?? 20);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Client $client) => $this->clientPayload($client))->values(),
            'meta' => ['list' => $list, 'total' => $page->total(), 'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(), 'per_page' => $page->perPage()],
        ])->header('Cache-Control', 'private, no-store');
    }

    private function claimant(Request $request): User
    {
        $user = $request->user();
        abort_unless(in_array($user->role?->slug, ['agent', 'intern'], true), 403, 'Брать клиентов могут только агенты и стажёры.');

        return $user;
    }

    public function claimStatus(Request $request)
    {
        $user = $this->claimant($request);
        $now = now('Asia/Dushanbe');
        $used = DB::table('client_catalog_claims')->where('user_id', $user->id)->where('claimed_on', $now->toDateString())->exists();

        return response()->json([
            'can_claim' => ! $used && $user->branch_id !== null,
            'used_today' => $used,
            'next_available_at' => $used ? $now->copy()->addDay()->startOfDay()->toIso8601String() : null,
            'timezone' => 'Asia/Dushanbe',
            'message' => $used ? 'Сегодня вы уже взяли клиента. Следующего можно взять завтра.' : ($user->branch_id ? 'Можно взять одного клиента сегодня.' : 'Для получения клиента руководитель должен назначить вам филиал.'),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function claim(Request $request, int $clientId)
    {
        $actor = $this->claimant($request);
        $client = DB::transaction(function () use ($actor, $clientId) {
            // Serialize both requests by the same employee and requests for the same client.
            $user = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($user->status === 'active' && in_array($user->role?->slug, ['agent', 'intern'], true), 403);
            $client = Client::query()->lockForUpdate()->findOrFail($clientId);
            $existing = DB::table('client_catalog_claims')->where('client_id', $clientId)->lockForUpdate()->first();
            if ($existing) {
                abort_unless((int) $existing->user_id === (int) $user->id && (int) $client->responsible_agent_id === (int) $user->id, 409, 'Этот клиент уже недоступен. Обновите список.');

                return $client; // Safe retry: no second quota charge and no second transfer.
            }
            abort_unless($user->branch_id, 422, 'Сначала руководитель должен назначить вам филиал.');
            $today = now('Asia/Dushanbe')->toDateString();
            abort_if(DB::table('client_catalog_claims')->where('user_id', $user->id)->where('claimed_on', $today)->lockForUpdate()->exists(), 409, 'Сегодня вы уже взяли клиента. Следующего можно взять завтра.');
            abort_unless($this->clients($this->lists($user))->whereKey($clientId)->exists(), 409, 'Этот клиент уже недоступен. Обновите список.');

            DB::table('client_catalog_claims')->insert(['client_id' => $clientId, 'user_id' => $user->id, 'claimed_on' => $today, 'created_at' => now()]);
            $before = $client->only(['responsible_agent_id', 'branch_id', 'branch_group_id']);
            $client->forceFill(['responsible_agent_id' => $user->id, 'branch_id' => $user->branch_id, 'branch_group_id' => $user->branch_group_id])->save();
            // Keep open needs with their newly responsible employee; closed history is preserved.
            $client->needs()->whereNull('closed_at')->where(fn (Builder $q) => $q->whereNull('status_id')->orWhereHas('status', fn (Builder $statuses) => $statuses->where('is_closed', false)))
                ->update(['responsible_agent_id' => $user->id]);
            app(AuditLogger::class)->log($client, $user, 'responsible_agent_changed', $before,
                $client->only(['responsible_agent_id', 'branch_id', 'branch_group_id']),
                'Клиент взят из общего каталога.', ['source' => 'client_catalog', 'claimed_on' => $today]);

            return $client;
        }, 5);
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

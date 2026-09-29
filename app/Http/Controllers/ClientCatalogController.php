<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientNeed;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Shared, read-only needs catalog. Never reuse the full CRM client serializer here. */
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
        return Client::query()->where('status', 'active')->whereIn('contact_kind', ['buyer', 'both'])
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
            'data' => $page->getCollection()->map(fn (Client $client) => [
                'id' => $client->id,
                'name' => $this->safeText($client->full_name),
                'branch_name' => $this->safeText($client->branch?->name),
                'responsible_agent_name' => $this->safeText($client->responsibleAgent?->name),
                'type_name' => $client->type?->name,
                'contact_kind' => $client->contact_kind,
                'created_at' => $client->created_at?->toISOString(),
                'needs' => $client->needs->map(fn (ClientNeed $need) => $this->needPayload($need))->values(),
            ])->values(),
            'meta' => ['list' => $list, 'total' => $page->total(), 'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(), 'per_page' => $page->perPage()],
        ])->header('Cache-Control', 'private, no-store');
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

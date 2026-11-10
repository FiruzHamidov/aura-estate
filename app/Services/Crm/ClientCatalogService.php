<?php

namespace App\Services\Crm;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ClientCatalogService
{
    public function lists(User $user): array
    {
        abort_unless($user->hasPermission('clients.catalog.view'), 403);

        $lists = array_values(array_filter(['agents', 'interns', 'unattended'], fn (string $list) => $user->hasPermission('clients.catalog.list.'.$list)));
        abort_unless($lists !== [], 403, 'Нет доступных подборок каталога.');

        return $lists;
    }

    public function needs(Builder $query, array $lists): void
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

    public function clients(array $lists): Builder
    {
        // Deliberately shared across branches; all normal CRM read/write scopes stay intact.
        return Client::query()->whereNotIn('id', DB::table('client_catalog_claims')->select('client_id'))->where('status', 'active')
            ->where(function (Builder $clients) use ($lists) {
                $budgetLists = array_values(array_diff($lists, ['unattended']));
                if ($budgetLists !== []) {
                    $clients->where(fn (Builder $budget) => $budget->whereIn('contact_kind', ['buyer', 'both'])
                        ->whereHas('needs', fn (Builder $needs) => $this->needs($needs, $budgetLists)));
                }
                if (in_array('unattended', $lists, true)) {
                    // Keep the existing API/permission key; this list now includes all clients without needs.
                    $clients->orWhereDoesntHave('needs');
                }
            });
    }

    public function claim(User $actor, int $clientId, bool $withNeed = false): Client
    {
        return DB::transaction(function () use ($actor, $clientId, $withNeed) {
            // Serialize both requests by the same employee and requests for the same client.
            [$user, $client] = app(\App\Services\GroupAccess\GroupRecordWriteLock::class)->acquire(
                $actor, Client::query()->findOrFail($clientId), $actor->id, null
            );
            abort_unless($user->status === 'active' && $user->hasPermission('clients.catalog.claim'), 403);
            if ($withNeed) {
                $this->lists($user);
                abort_unless($user->hasPermission('clients.catalog.list.unattended') && $user->hasPermission('client_needs.create'), 403);
                abort_if($client->needs()->exists(), 409, 'У клиента уже есть потребность. Обновите список.');
            }
            $existing = DB::table('client_catalog_claims')->where('client_id', $clientId)->lockForUpdate()->first();
            if ($existing) {
                abort_if($withNeed, 409, 'Этот клиент уже недоступен. Обновите список.');
                abort_unless((int) $existing->user_id === (int) $user->id && (int) $client->responsible_agent_id === (int) $user->id, 409, 'Этот клиент уже недоступен. Обновите список.');

                return $client; // Safe retry: no second ledger entry and no second transfer.
            }
            abort_unless($user->branch_id, 422, 'Сначала руководитель должен назначить вам филиал.');
            $today = now('Asia/Dushanbe')->toDateString();
            abort_unless($this->clients($withNeed ? array_intersect($this->lists($user), ['unattended']) : $this->lists($user))->whereKey($clientId)->exists(), 409, 'Этот клиент уже недоступен. Обновите список.');

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
    }
}

<?php

namespace App\Services\Marketing;

use App\Models\Client;
use App\Models\User;
use App\Support\BranchVisibility;
use App\Support\ClientAccess;
use App\Support\InternationalPhone;
use App\Support\LeadAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class SmsAudience
{
    public function query(User $actor, array $segment): Builder
    {
        $query = match ($segment['base']) {
            'clients' => app(ClientAccess::class)->visibleQuery($actor),
            'leads' => app(LeadAccess::class)->visibleQuery($actor),
            'accounts' => $this->accounts($actor),
        };
        BranchVisibility::records($query);
        foreach (['status', 'branch_id', 'source_id', 'source'] as $key) {
            if (isset($segment[$key])) {
                $query->where($key, $segment[$key]);
            }
        }
        if (isset($segment['contact_kind'])) {
            $query->whereIn('contact_kind', Client::kindsMatchingFilter($segment['contact_kind']));
        }
        if (isset($segment['business'])) {
            $query->whereHas('type', fn ($q) => $q->where('is_business', true));
        }
        if (isset($segment['from'])) {
            $query->whereDate('created_at', '>=', $segment['from']);
        }
        if (isset($segment['to'])) {
            $query->whereDate('created_at', '<=', $segment['to']);
        }

        return $query->withoutEagerLoads()->select(['id', 'phone']);
    }

    private function accounts(User $actor): Builder
    {
        $query = User::query()->activeForSelection()->whereNull('deleted_at')->whereNull('deletion_requested_at')
            ->whereHas('role', fn ($role) => $role->where('slug', 'client'));
        if ($actor->usesAssignedGroupScope()) {
            return $query->whereRaw('1 = 0');
        }
        if (! $actor->hasPermission('users.scope.all') && ! $actor->hasPermission('users.clients.view')) {
            if (! $actor->branch_id || ! $actor->hasPermission('users.scope.branch')) {
                return $query->whereRaw('1 = 0');
            }
            $query->where('branch_id', $actor->branch_id);
        }

        return $query;
    }

    /** First visible source record per canonical phone, in deterministic source ID order. */
    public function resolve(User $actor, array $segment): array
    {
        $excluded = array_fill_keys(DB::table('sms_campaign_exclusions')->pluck('phone')->all(), true);
        $recipients = [];
        $invalid = $duplicates = $suppressed = 0;
        foreach ($this->query($actor, $segment)->toBase()->lazyById(1000) as $record) {
            $phone = InternationalPhone::e164($record->phone);
            if ($phone === null || ! preg_match('/^\+992[0-9]{9}$/D', $phone)) {
                $invalid++;
            } elseif (isset($excluded[$phone])) {
                $suppressed++;
            } elseif (isset($recipients[$phone])) {
                $duplicates++;
            } else {
                $recipients[$phone] = (int) $record->id;
            }
        }

        return [
            'recipients' => $recipients,
            'summary' => ['total' => count($recipients), 'invalid' => $invalid, 'duplicates' => $duplicates, 'excluded' => $suppressed],
        ];
    }
}

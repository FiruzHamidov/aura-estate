<?php

namespace App\Services\Marketing;

use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use App\Support\ClientPhone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LeadReceiptTracker
{
    public function capture(Lead $lead, bool $historical = false): void
    {
        if (! Schema::hasTable('marketing_lead_receipts')) {
            return;
        }
        $marketing = User::query()->whereKey($lead->created_by)
            ->whereHas('role', fn ($q) => $q->where('slug', 'marketing'))->exists();
        // Explicit attribution is accepted only from trusted CRM creators.
        $trusted = User::query()->whereKey($lead->created_by)
            ->whereHas('role', fn ($q) => $q->whereIn('slug', ['admin', 'superadmin', 'marketing']))->exists();
        $website = Schema::hasTable('lead_intakes') && DB::table('lead_intakes')->where('lead_id', $lead->id)->exists();
        if (! $marketing && ! $website && ! ($trusted && data_get($lead->meta, 'marketing_origin') === true)) {
            return;
        }

        DB::transaction(function () use ($lead, $historical) {
            $phone = ClientPhone::normalize($lead->phone);
            $classification = 'new';
            if (! $phone) {
                $classification = 'unknown';
            } elseif (Lead::withTrashed()->where('phone_normalized', $phone)->where('id', '<', $lead->id)->exists()) {
                $classification = 'repeat';
            } elseif (Client::withTrashed()->where('phone_normalized', $phone)->where('created_at', '<', $lead->created_at)->exists()) {
                // A database match alone cannot establish whether the client was actively handled.
                $classification = 'existing';
            }
            $inserted = DB::table('marketing_lead_receipts')->insertOrIgnore([
                'lead_id' => $lead->id, 'phone_key' => $phone ?: null,
                'source' => $lead->source,
                'campaign' => mb_substr((string) data_get($lead->meta, 'utm.utm_campaign', data_get($lead->meta, 'campaign', '')), 0, 255) ?: null,
                'classification' => $classification, 'historical' => $historical,
                'received_at' => $lead->created_at, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($inserted) {
                DB::table('marketing_lead_assignments')->insert([
                    'lead_id' => $lead->id, 'agent_id' => $lead->responsible_agent_id,
                    'actor_id' => $lead->created_by, 'assigned_at' => $historical ? now() : $lead->created_at,
                    'historical' => $historical,
                ]);
            }
        });
    }

    public function reassigned(Lead $lead, bool $forced = false): void
    {
        if ((! $forced && ! $lead->wasChanged('responsible_agent_id')) || ! Schema::hasTable('marketing_lead_receipts')) {
            return;
        }
        if (! DB::table('marketing_lead_receipts')->where('lead_id', $lead->id)->exists()) {
            return;
        }
        DB::transaction(function () use ($lead) {
            DB::table('marketing_lead_receipts')->where('lead_id', $lead->id)->lockForUpdate()->first();
            DB::table('marketing_lead_assignments')->where('lead_id', $lead->id)->whereNull('ended_at')->update(['ended_at' => now()]);
            DB::table('marketing_lead_assignments')->insert([
                'lead_id' => $lead->id, 'agent_id' => $lead->responsible_agent_id,
                'actor_id' => auth()->id(), 'assigned_at' => now(), 'historical' => false,
            ]);
        });
    }
}

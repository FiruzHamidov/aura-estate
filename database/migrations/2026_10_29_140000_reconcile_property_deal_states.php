<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $closedDeals = ['sold', 'rented', 'sold_by_owner', 'client_denied'];
        $closedLegacy = ['sold', 'rented', 'sold_by_owner', 'denied'];
        DB::table('properties')->where(function ($query) use ($closedDeals, $closedLegacy) {
            $query->where(fn ($q) => $q->where('publication_status', 'published')
                ->where(fn ($state) => $state->whereIn('deal_status', $closedDeals)->orWhereIn('moderation_status', $closedLegacy)))
                ->orWhere(fn ($q) => $q->where('deal_status', 'available')->where('moderation_status', 'deposit'));
        })->orderBy('id')->chunkById(200, function ($rows) use ($closedDeals, $closedLegacy) {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row, $closedDeals, $closedLegacy) {
                    $current = DB::table('properties')->where('id', $row->id)->lockForUpdate()->first();
                    if (! $current) return;
                    $updates = [];
                    if ($current->publication_status === 'published'
                        && (in_array($current->deal_status, $closedDeals, true) || in_array($current->moderation_status, $closedLegacy, true))) {
                        $updates['publication_status'] = 'archived';
                    }
                    if ($current->deal_status === 'available' && $current->moderation_status === 'deposit') {
                        $updates['moderation_status'] = match ($current->publication_status) {
                            'published' => 'approved', 'draft' => 'draft', 'rejected' => 'rejected',
                            'archived' => 'deleted', default => 'pending',
                        };
                        foreach (['deposit_amount', 'deposit_received_at', 'deposit_taken_at', 'deposit_user_id',
                            'company_expected_income', 'planned_contract_signed_at'] as $field) {
                            if (property_exists($current, $field)) $updates[$field] = null;
                        }
                        foreach (['deposit_currency', 'company_expected_income_currency'] as $field) {
                            if (property_exists($current, $field)) $updates[$field] = 'TJS';
                        }
                    }
                    if ($updates === []) return;
                    $updates['moderation_version'] = (int) $current->moderation_version + 1;
                    $changes = [];
                    foreach ($updates as $field => $value) {
                        if ($current->{$field} !== $value) $changes[$field] = ['old' => $current->{$field}, 'new' => $value];
                    }
                    // Preserve sale attribution and listing dates. Record the old deposit before clearing it.
                    DB::table('properties')->where('id', $current->id)->update($updates);
                    if (Schema::hasTable('property_logs')) {
                        DB::table('property_logs')->insert([
                            'property_id' => $current->id, 'user_id' => null, 'action' => 'deal_state_reconciled',
                            'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                });
            }
        });
    }

    public function down(): void
    {
        // Reintroducing contradictory states would republish closed listings.
    }
};

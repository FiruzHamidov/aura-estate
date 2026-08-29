<?php

namespace App\Observers;

use App\Jobs\RecalculatePropertyLiquidity;
use App\Models\Property;
use App\Models\PropertyLog;
use App\Models\User;
use App\Services\Crm\PropertyControlService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PropertyObserver
{
    private const LIQUIDITY_FIELDS = [
        'price', 'discount_price', 'currency', 'offer_type', 'type_id', 'location_id',
        'district', 'district_id', 'rooms', 'total_area', 'floor', 'total_floors',
        'condition', 'repair_type_id', 'has_parking', 'is_mortgage_available',
        'is_from_developer', 'moderation_status', 'sold_at', 'listed_at',
    ];

    protected function currentUserId()
    {
        return Auth::id();
    }

    public function saving(Property $property): void
    {
        if (
            Schema::hasTable('districts')
            && Schema::hasColumn('properties', 'district_id')
            && ($property->isDirty('district') || $property->isDirty('location_id'))
        ) {
            $property->district_id = DB::table('districts')
                ->where('location_id', $property->location_id)
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim((string) $property->district))])
                ->value('id');
        }
    }

    protected function currentUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    protected function syncPropertyControl(Property $property): void
    {
        app(PropertyControlService::class)->syncForProperty(
            $property->fresh(['agent.role', 'creator.role', 'ownerClient.type', 'logs.user']),
            $this->currentUser()
        );
    }

    public function created(Property $property)
    {
        // Сохраняем всю модель как `new` (old = null)
        $changes = [];
        foreach ($property->getAttributes() as $field => $value) {
            // опционально: фильтровать служебные/временные поля
            if (in_array($field, ['updated_at', 'listing_updated_at'])) {
                continue;
            }
            $changes[$field] = [
                'old' => null,
                'new' => $value,
            ];
        }

        PropertyLog::create([
            'property_id' => $property->id,
            'user_id' => $this->currentUserId(),
            'action' => 'created',
            'changes' => $changes,
        ]);

        if (in_array($property->moderation_status, ['deleted', 'sold_by_owner'], true)) {
            $this->syncPropertyControl($property);
        }

        $this->recordInitialHistory($property);
        if ($this->supportsLiquidity()) {
            RecalculatePropertyLiquidity::dispatch($property->id)->afterCommit();
        }
    }

    public function updating(Property $property): void
    {
        if (
            $property->isDirty('moderation_status')
            && $property->moderation_status === Property::PUBLIC_MODERATION_STATUS
            && $property->listed_at === null
            && Schema::hasColumn('properties', 'listed_at')
        ) {
            $property->listed_at = now();
        }
    }

    public function updated(Property $property)
    {
        // getChanges возвращает только изменившиеся поля
        $changesRaw = $property->getChanges();
        $original = $property->getOriginal();

        $changes = [];

        foreach ($changesRaw as $field => $newValue) {
            if (in_array($field, ['updated_at', 'listing_updated_at'])) {
                continue;
            } // обычно игнорируем
            $changes[$field] = [
                'old' => array_key_exists($field, $original) ? $original[$field] : null,
                'new' => $newValue,
            ];
        }

        if (! empty($changes)) {
            $action = array_key_exists('moderation_status', $changes) ? 'status_change' : 'updated';
            $comment = array_key_exists('moderation_status', $changes)
                ? ($property->status_comment ?: $property->rejection_comment)
                : null;

            PropertyLog::create([
                'property_id' => $property->id,
                'user_id' => $this->currentUserId(),
                'action' => $action,
                'changes' => $changes,
                'comment' => $comment,
            ]);

            if (array_key_exists('moderation_status', $changes)) {
                $this->syncPropertyControl($property);
            }

            $this->recordChangedHistory($property, $changes);

            if ($this->supportsLiquidity() && array_intersect(array_keys($changes), self::LIQUIDITY_FIELDS) !== []) {
                RecalculatePropertyLiquidity::dispatch($property->id)->afterCommit();
            }
        }
    }

    public function deleted(Property $property)
    {
        PropertyLog::create([
            'property_id' => $property->id,
            'user_id' => $this->currentUserId(),
            'action' => 'deleted',
            'changes' => null,
        ]);
    }

    private function recordInitialHistory(Property $property): void
    {
        if (Schema::hasTable('property_status_history')) {
            DB::table('property_status_history')->insert([
                'property_id' => $property->id,
                'from_status' => null,
                'to_status' => $property->moderation_status,
                'changed_by' => $this->currentUserId(),
                'changed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable('property_price_history')) {
            $this->recordPrice($property);
        }
    }

    private function recordChangedHistory(Property $property, array $changes): void
    {
        if (isset($changes['moderation_status']) && Schema::hasTable('property_status_history')) {
            DB::table('property_status_history')->insert([
                'property_id' => $property->id,
                'from_status' => $changes['moderation_status']['old'],
                'to_status' => $changes['moderation_status']['new'],
                'changed_by' => $this->currentUserId(),
                'changed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (array_intersect(array_keys($changes), ['price', 'discount_price', 'currency']) !== [] && Schema::hasTable('property_price_history')) {
            $this->recordPrice($property);
        }
    }

    private function recordPrice(Property $property): void
    {
        DB::table('property_price_history')->insert([
            'property_id' => $property->id,
            'price' => $property->price,
            'discount_price' => $property->discount_price,
            'currency' => $property->currency,
            'exchange_rate' => null,
            'changed_by' => $this->currentUserId(),
            'changed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function supportsLiquidity(): bool
    {
        return Schema::hasTable('property_liquidity_snapshots')
            && Schema::hasColumn('properties', 'liquidity_score');
    }
}

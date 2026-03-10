<?php

namespace App\Observers;

use App\Models\Property;
use App\Models\PropertyLog;
use App\Models\User;
use App\Services\Crm\PropertyControlService;
use Illuminate\Support\Facades\Auth;

class PropertyObserver
{
    protected function currentUserId()
    {
        return Auth::id();
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
            if (in_array($field, ['updated_at'])) {
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
    }

    public function updated(Property $property)
    {
        // getChanges возвращает только изменившиеся поля
        $changesRaw = $property->getChanges();
        $original = $property->getOriginal();

        $changes = [];

        foreach ($changesRaw as $field => $newValue) {
            if ($field === 'updated_at') {
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
}

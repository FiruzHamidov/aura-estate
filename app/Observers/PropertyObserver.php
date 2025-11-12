<?php

namespace App\Observers;

use App\Models\Property;
use App\Models\PropertyLog;
use Illuminate\Support\Facades\Auth;

class PropertyObserver
{
    protected function currentUserId()
    {
        return Auth::id();
    }

    public function created(Property $property)
    {
        // Сохраняем всю модель как `new` (old = null)
        $changes = [];
        foreach ($property->getAttributes() as $field => $value) {
            // опционально: фильтровать служебные/временные поля
            if (in_array($field, ['updated_at'])) continue;
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
    }

    public function updated(Property $property)
    {
        // getChanges возвращает только изменившиеся поля
        $changesRaw = $property->getChanges();
        $original = $property->getOriginal();

        $changes = [];

        foreach ($changesRaw as $field => $newValue) {
            if ($field === 'updated_at') continue; // обычно игнорируем
            $changes[$field] = [
                'old' => array_key_exists($field, $original) ? $original[$field] : null,
                'new' => $newValue,
            ];
        }

        if (!empty($changes)) {
            PropertyLog::create([
                'property_id' => $property->id,
                'user_id' => $this->currentUserId(),
                'action' => 'updated',
                'changes' => $changes,
            ]);
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

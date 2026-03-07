<?php

namespace App\Services\Crm;

use App\Models\CrmAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    public function log(
        Model $subject,
        ?User $actor,
        string $event,
        array $oldValues = [],
        array $newValues = [],
        ?string $message = null,
        array $context = []
    ): CrmAuditLog {
        return CrmAuditLog::create([
            'auditable_type' => $subject->getMorphClass(),
            'auditable_id' => $subject->getKey(),
            'actor_id' => $actor?->id,
            'event' => $event,
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'context' => $context ?: null,
            'message' => $message,
        ]);
    }
}

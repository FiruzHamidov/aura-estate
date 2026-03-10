<?php

namespace App\Services\Crm;

use App\Models\CrmAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class ActivityService
{
    public function __construct(
        private readonly AuditLogger $auditLogger
    ) {}

    public function normalizeTags(null|array|string $tags): array
    {
        $values = is_array($tags) ? $tags : explode(',', (string) $tags);

        return collect($values)
            ->map(fn ($tag) => trim((string) $tag))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function logComment(Model $subject, ?User $actor, string $comment, array $context = []): CrmAuditLog
    {
        return $this->auditLogger->log(
            $subject,
            $actor,
            'comment',
            [],
            ['comment' => $comment],
            'Comment added.',
            array_merge($context, ['comment' => $comment])
        );
    }

    public function logCall(
        Model $subject,
        ?User $actor,
        string $result,
        ?int $durationSeconds = null,
        ?string $note = null,
        array $context = []
    ): CrmAuditLog {
        $payload = array_filter([
            'result' => $result,
            'duration_seconds' => $durationSeconds,
            'note' => $note,
        ], fn ($value) => $value !== null && $value !== '');

        return $this->auditLogger->log(
            $subject,
            $actor,
            'call',
            [],
            $payload,
            'Call result saved.',
            array_merge($context, $payload)
        );
    }

    public function logStatusChange(
        Model $subject,
        ?User $actor,
        array $oldValues,
        array $newValues,
        array $context = [],
        ?string $message = null
    ): CrmAuditLog {
        return $this->auditLogger->log(
            $subject,
            $actor,
            'status_change',
            $oldValues,
            $newValues,
            $message ?: 'Status changed.',
            $context
        );
    }

    public function logAssignment(
        Model $subject,
        ?User $actor,
        ?int $oldResponsibleId,
        ?int $newResponsibleId,
        array $context = []
    ): CrmAuditLog {
        return $this->auditLogger->log(
            $subject,
            $actor,
            'assignment',
            ['responsible_agent_id' => $oldResponsibleId],
            ['responsible_agent_id' => $newResponsibleId],
            'Responsible user changed.',
            $context
        );
    }

    public function logFollowUpChange(
        Model $subject,
        ?User $actor,
        array $oldValues,
        array $newValues,
        array $context = []
    ): CrmAuditLog {
        return $this->auditLogger->log(
            $subject,
            $actor,
            'follow_up_changed',
            Arr::only($oldValues, ['next_follow_up_at', 'next_activity_at']),
            Arr::only($newValues, ['next_follow_up_at', 'next_activity_at']),
            'Follow-up changed.',
            $context
        );
    }

    public function logTagDiff(Model $subject, ?User $actor, array $oldTags, array $newTags, array $context = []): void
    {
        $added = array_values(array_diff($newTags, $oldTags));
        $removed = array_values(array_diff($oldTags, $newTags));

        foreach ($added as $tag) {
            $this->auditLogger->log(
                $subject,
                $actor,
                'tag_added',
                [],
                ['tag' => $tag],
                'Tag added.',
                array_merge($context, ['tag' => $tag])
            );
        }

        foreach ($removed as $tag) {
            $this->auditLogger->log(
                $subject,
                $actor,
                'tag_removed',
                ['tag' => $tag],
                [],
                'Tag removed.',
                array_merge($context, ['tag' => $tag])
            );
        }
    }
}

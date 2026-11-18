<?php

namespace App\Services;

use App\Models\{Booking, CrmTask, CrmTaskType, User};
use App\Services\Crm\AuditLogger;
use App\Support\RopGroupAccess;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class BookingResultService
{
    public function canEdit(User $actor, Booking $booking): bool
    {
        if ($actor->usesAssignedGroupScope() && ! app(RopGroupAccess::class)->allowsGroup($actor, $booking->branch_group_id)) return false;
        return $actor->hasPermission('bookings.update.all')
            || ($actor->hasPermission('bookings.update.own') && in_array((int) $actor->id,
                [(int) $booking->agent_id, (int) $booking->co_agent_id], true));
    }

    public function canFollowUp(User $actor, Booking $booking): bool
    {
        $agent = $booking->agent;
        return $this->canEdit($actor, $booking) && $actor->hasPermission('tasks.create')
            && ($actor->hasPermission('tasks.assign.all') || ($actor->hasPermission('tasks.assign.branch')
                && (int) $actor->branch_id === (int) $agent?->branch_id))
            && $booking->branch_group_id && $agent?->status === User::STATUS_ACTIVE && ! $agent->isDeletedAccount()
            && (int) $agent->branch_group_id === (int) $booking->branch_group_id;
    }

    public function snapshot(Booking $booking): array
    {
        return $booking->only(['status', 'outcome', 'result_reason', 'result_note', 'start_time', 'end_time', 'follow_up_task_id']);
    }

    /** The caller holds the booking and participant locks in a transaction. */
    public function save(Booking $booking, User $actor, array $data): void
    {
        abort_unless($this->canEdit($actor, $booking), 403, 'FORBIDDEN_ACTION');
        abort_unless((int) $booking->result_version === (int) $data['version'], 409, 'BOOKING_RESULT_CHANGED');
        $before = $this->snapshot($booking);
        if (array_key_exists('next_contact_at', $data)) {
            $task = $booking->follow_up_task_id ? CrmTask::find($booking->follow_up_task_id) : null;
            $before['next_contact_at'] = $task?->due_at ? Carbon::parse($task->getRawOriginal('due_at'), config('app.timezone'))->toIso8601String() : null;
        }
        foreach (['status', 'outcome', 'result_reason', 'result_note'] as $field) {
            if (array_key_exists($field, $data) && ($field !== 'status' || $data[$field] !== null)) $booking->$field = $data[$field];
        }
        // A reaction is about a viewing that took place. No reaction is required.
        if ($booking->outcome && ! array_key_exists('status', $data)) $booking->status = 'completed';
        if ($booking->status !== 'completed') $booking->outcome = null;
        if ($booking->isDirty('status') && ! array_key_exists('result_reason', $data)) $booking->result_reason = null;

        $taskChanged = false;
        if (array_key_exists('next_contact_at', $data)) $taskChanged = $this->followUp($booking, $actor, $data['next_contact_at']);
        if (! $booking->isDirty(['status', 'outcome', 'result_reason', 'result_note', 'follow_up_task_id']) && ! $taskChanged) return;
        $booking->result_recorded_at = now();
        $booking->result_recorded_by = $actor->id;
        $booking->result_version = (int) $booking->result_version + 1;
        $booking->save();
        $after = $this->snapshot($booking);
        if (array_key_exists('next_contact_at', $data)) $after['next_contact_at'] = $data['next_contact_at'];
        app(AuditLogger::class)->log($booking, $actor, 'booking_result_updated', $before, $after, 'Результат показа изменён.');
    }

    /** Called for both the dedicated action and older schedule editors. */
    public function recordReschedule(Booking $booking, User $actor, array $before): void
    {
        $booking->status = 'pending';
        $booking->outcome = null;
        $booking->result_reason = null;
        $booking->result_note = null;
        $booking->result_recorded_at = null;
        $booking->result_recorded_by = null;
        $booking->result_version = (int) $booking->result_version + 1;
        app(AuditLogger::class)->log($booking, $actor, 'booking_rescheduled', $before,
            $this->snapshot($booking), 'Показ перенесён.');
    }

    private function followUp(Booking $booking, User $actor, ?string $value): bool
    {
        $task = $booking->follow_up_task_id ? CrmTask::query()->lockForUpdate()->find($booking->follow_up_task_id) : null;
        $active = $task && ! $task->completed_at && ! in_array($task->status, ['done', 'canceled'], true);
        if (! $active && $value === null) return false;
        if (! $this->canFollowUp($actor, $booking)) {
            throw ValidationException::withMessages(['next_contact_at' => ['Для задачи нужен действующий ответственный в группе показа и право создавать задачи. Результат можно сохранить без следующего контакта.']]);
        }
        if ($task) {
            abort_unless($task->related_entity_type === 'showing' && (int) $task->related_entity_id === (int) $booking->id, 409, 'BOOKING_TASK_CHANGED');
            app(RopGroupAccess::class)->ensureVisible($actor, $task);
        }
        if ($active && ! $actor->hasPermission('tasks.update')) abort(403, 'FORBIDDEN_ACTION');
        if ($value === null) {
            $task->update(['status' => 'canceled']);
            $booking->follow_up_task_id = null;
            return true;
        }
        $due = Carbon::parse($value, 'Asia/Dushanbe')->setTimezone(config('app.timezone', 'Asia/Dushanbe'));
        if ($active && $task->due_at && Carbon::parse($task->getRawOriginal('due_at'), config('app.timezone'))->equalTo($due)) return false;
        if ($due->lessThanOrEqualTo(Carbon::now('UTC'))) {
            throw ValidationException::withMessages(['next_contact_at' => ['Выберите будущее время следующего контакта.']]);
        }
        if ($active) {
            $task->update(['due_at' => $due]);
        } else {
            $type = CrmTaskType::query()->where('code', 'CALL')->where('is_active', true)->first();
            if (! $type) throw ValidationException::withMessages(['next_contact_at' => ['Тип задачи «Звонок» недоступен. Результат можно сохранить без следующего контакта.']]);
            $task = CrmTask::create([
                'task_type_id' => $type->id, 'branch_group_id' => $booking->branch_group_id,
                'assignee_id' => $booking->agent_id, 'creator_id' => $actor->id,
                'title' => 'Связаться с клиентом после показа №'.$booking->id,
                'related_entity_type' => 'showing', 'related_entity_id' => $booking->id,
                'status' => 'new', 'due_at' => $due, 'source' => 'manual',
            ]);
            $booking->follow_up_task_id = $task->id;
        }
        return true;
    }
}

<?php

namespace App\Services\Users;

use App\Models\{BranchGroup, CrmAuditLog, Property, User};
use App\Services\Crm\AuditLogger;
use App\Services\GroupAccess\{GroupRecordTransfer, UserOrganizationService};
use Illuminate\Support\Facades\{DB, Schema};
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** A dismissal receipt is immutable; returns are separate, audited operations. */
final class EmployeeRestoration
{
    private ?array $relatedColumns = null;
    public function capture(User $employee): array
    {
        $query = app(UserOrganizationService::class)->activeQueries($employee->id)['properties'] ?? null;
        return $query?->orderBy('id')->lockForUpdate()->get()->map(fn ($row) => [
            'property_id' => (int) $row->id,
            'title' => trim((string) ($row->title ?? $row->address ?? '')) ?: 'Объявление #'.$row->id,
        ])->all() ?? [];
    }

    public function receipt(User $employee, array $captured): array
    {
        return array_map(function ($item) use ($employee) {
            $property = Property::findOrFail($item['property_id']);
            $recipient = User::find($property->agent_id);
            return [...$item, 'recipient_id' => $recipient?->id, 'recipient_name' => $recipient?->name,
                'employee_branch_id' => $employee->branch_id, 'employee_group_id' => $employee->branch_group_id,
                'post_revision' => $this->fingerprint($property->id, true)];
        }, $captured);
    }

    /** Include related work so a return cannot silently take over subsequent activity. */
    private function fingerprint(int $id, bool $lock = false): string
    {
        $property = DB::table('properties')->where('id', $id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $state = (array) $property;
        // Analytics and background liquidity recalculation do not change the handover.
        foreach (['views_count', 'non_client_views_count', 'phone_clicks', 'chat_clicks', 'whatsapp_clicks', 'updated_at',
            'liquidity_score', 'liquidity_category', 'liquidity_confidence', 'price_position', 'price_delta_pct',
            'promotion_priority_score', 'promotion_eligibility', 'liquidity_calculated_at', 'liquidity_model_version'] as $field) unset($state[$field]);
        ksort($state);
        $related = [];
        $this->relatedColumns ??= array_filter([
            'crm_tasks' => 'related_entity_id', 'crm_deals' => 'primary_property_id',
            'bookings' => 'property_id', 'property_photos' => 'property_id', 'property_documents' => 'property_id',
            'property_logs' => 'property_id', 'property_moderation_cases' => 'property_id', 'property_moderation_events' => 'property_id',
        ], fn ($column, $table) => Schema::hasColumn($table, $column), ARRAY_FILTER_USE_BOTH);
        foreach ($this->relatedColumns as $table => $column) {
            $query = DB::table($table)->where($column, $id);
            if ($table === 'crm_tasks') $query->whereIn('related_entity_type', ['property', 'ad']);
            $related[$table] = $query->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()->all();
        }
        return hash('sha256', json_encode([$state, $related], JSON_THROW_ON_ERROR));
    }

    private function latest(User $employee, bool $lock = false): ?CrmAuditLog
    {
        return CrmAuditLog::where('auditable_type', $employee->getMorphClass())->where('auditable_id', $employee->id)
            ->where('event', 'user_dismissed')->orderByDesc('id')->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    public function preview(User $actor, User $employee, bool $lock = false): array
    {
        $dismissal = $this->latest($employee, $lock);
        $receipt = $dismissal?->context['property_handover'] ?? null;
        $returned = CrmAuditLog::where('auditable_type', $employee->getMorphClass())->where('auditable_id', $employee->id)
            ->where('event', 'employee_properties_returned')->get()->filter(fn ($log) => ($log->context['dismissal_id'] ?? null) === $dismissal?->id)
            ->flatMap(fn ($log) => $log->context['property_ids'] ?? [])->all();
        // Older audits establish where records went, but cannot prove that they are unchanged.
        if ($receipt === null) {
            $receipt = CrmAuditLog::where('auditable_type', (new Property)->getMorphClass())
                ->where('event', 'property_owner_transferred')->where('context->dismissed_user_id', $employee->id)
                ->orderByDesc('id')->get()->unique('auditable_id')->map(fn ($log) => [
                    'property_id' => (int) $log->auditable_id, 'title' => 'Объявление #'.$log->auditable_id,
                    'recipient_id' => $log->new_values['agent_id'] ?? null, 'recipient_name' => null,
                ])->values()->all();
        }
        $group = $employee->branch_group_id ? BranchGroup::find($employee->branch_group_id) : null;
        $records = [];
        $states = [];
        foreach ($receipt as $item) {
            $id = (int) $item['property_id'];
            $property = Property::query()->when($lock, fn ($q) => $q->lockForUpdate())->find($id);
            $visible = $property && ($actor->hasPermission('users.scope.all')
                || ($actor->branch_id && (int) $property->branch_id === (int) $actor->branch_id));
            $state = $property ? $this->fingerprint($id, $lock) : null;
            $states[$id] = $state;
            $reason = null;
            if (!$visible) $reason = 'Объявление удалено или недоступно в вашем филиале';
            elseif (in_array($id, $returned, true)) $reason = 'Уже возвращено сотруднику';
            elseif (!isset($item['post_revision'])) $reason = 'Старое увольнение: нет снимка для проверки изменений';
            elseif (in_array($property->moderation_status, UserOrganizationService::CLOSED_PROPERTY_STATUSES, true)) $reason = 'Объявление завершено или архивировано';
            elseif ((int) $property->agent_id !== (int) $item['recipient_id']) $reason = 'После увольнения изменился ответственный';
            elseif (!hash_equals($item['post_revision'], $state)) $reason = 'После увольнения изменилось объявление или связанные записи';
            elseif (!$employee->hasPermission('users.subject.record_recipient') || !$group || $group->is_archived
                || (int) $group->branch_id !== (int) $employee->branch_id) $reason = 'Для возврата нужна действующая группа и роль получателя';
            elseif ((int) $employee->branch_id !== (int) $item['employee_branch_id']
                || (int) $employee->branch_group_id !== (int) $item['employee_group_id']) $reason = 'Сотрудник переведён в другой филиал или группу';
            else {
                try {
                    app(GroupRecordTransfer::class)->authorize($actor, $property);
                    abort_unless($actor->hasPermission('properties.transfer'), 403);
                    abort_unless($actor->hasPermission('records.transfer.all_branches') || (int) $actor->branch_id === (int) $employee->branch_id, 403);
                } catch (HttpExceptionInterface $e) { $reason = 'Нет права вернуть это объявление'; }
            }
            $current = $visible && $property->agent_id ? User::find($property->agent_id) : null;
            $records[] = ['property_id' => $id, 'title' => $visible ? $item['title'] : 'Недоступное объявление',
                'recipient_name' => $visible ? ($item['recipient_name'] ?? null) : null,
                'current_responsible_name' => $current?->name, 'can_return' => $reason === null, 'reason' => $reason];
        }
        return ['dismissal_id' => $dismissal?->id, 'has_snapshot' => isset($dismissal?->context['property_handover']),
            'revision' => hash('sha256', json_encode([$dismissal?->id, $employee->only(['id', 'status', 'role_id', 'branch_id', 'branch_group_id', 'updated_at']), $records, $states], JSON_THROW_ON_ERROR)),
            'properties' => $records];
    }

    /** Caller locks the employee, rechecks permissions and keeps activation + returns atomic. */
    public function returnProperties(User $actor, User $employee, array $plan): int
    {
        $preview = $this->preview($actor, $employee, true);
        abort_unless(hash_equals($preview['revision'], $plan['revision']), 409, 'RESTORATION_VERSION_CONFLICT');
        $eligible = collect($preview['properties'])->keyBy('property_id');
        $ids = array_map('intval', $plan['property_ids']);
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) abort_unless($eligible->get($id)['can_return'] ?? false, 422, 'PROPERTY_RETURN_UNAVAILABLE');
        // Activate only after validating the preview, which includes the inactive status.
        if ($employee->status !== User::STATUS_ACTIVE) { $employee->status = User::STATUS_ACTIVE; $employee->save(); }
        $transfer = app(GroupRecordTransfer::class);
        foreach ($ids as $id) {
            $property = $transfer->model('properties', $id, true);
            $transfer->transfer($actor, 'properties', $id, ['branch_group_id' => $employee->branch_group_id,
                'responsible_user_id' => $employee->id, 'revision' => $transfer->revision($property), 'reason' => $plan['reason']]);
        }
        if ($ids !== []) app(AuditLogger::class)->log($employee, $actor, 'employee_properties_returned', message: 'Объявления возвращены сотруднику',
            context: ['dismissal_id' => $preview['dismissal_id'], 'property_ids' => $ids, 'returned_count' => count($ids), 'reason' => $plan['reason']]);
        return count($ids);
    }
}

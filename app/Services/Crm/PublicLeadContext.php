<?php

namespace App\Services\Crm;

use App\Models\NewBuilding;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Arr;

/** Resolves public object context and routing exclusively from authoritative records. */
class PublicLeadContext
{
    public function resolve(array $input): array
    {
        $context = Arr::except($input, ['responsible_agent_id', 'branch_id', 'building_name', 'unit_name', 'price', 'total_price', 'status', 'payment_program']);
        $responsibleId = null;
        $building = null;
        $unit = null;
        if (! empty($input['building_id'])) {
            $building = NewBuilding::query()->published()->findOrFail($input['building_id']);
            $context['building_name'] = $building->title;
            $responsibleId = $building->getAttribute('responsible_agent_id');
            if (! empty($input['unit_id'])) {
                $unit = $building->units()->availability(['available', 'reserved', 'sold'])->findOrFail($input['unit_id']);
                if (isset($input['expected_version'])) {
                    abort_unless((int) $unit->getAttribute('version') === (int) $input['expected_version'], 409, 'Данные квартиры изменились. Обновите карточку перед отправкой.');
                }
                $context['unit_name'] = $unit->name;
                $context['total_price'] = $unit->total_price;
                $context['status'] = \App\Services\Residential\InventoryStatus::unit($unit->getAttributes())[1];
                $context['block_id'] = $unit->block_id;
            }
        }
        if (! empty($input['property_id'])) {
            $property = Property::query()->publicSearchable()->findOrFail($input['property_id']);
            $context['property_title'] = $property->title;
            $context['property_price'] = $property->price;
        }
        if (! empty($input['payment_program_id'])) {
            $program = app(\App\Services\Residential\PaymentPrograms::class)->publicQuery($building, $unit)->findOrFail($input['payment_program_id']);
            abort_unless((int) $program->version === (int) ($input['expected_program_version'] ?? 0), 409, 'Условия программы изменились. Обновите их перед обращением.');
            $context['payment_program'] = $program->only(['id', 'title', 'type', 'bank_name', 'annual_rate', 'valid_from', 'valid_until', 'data_verified_at', 'version']);
            $context['payment_program']['valid_from'] = $program->valid_from?->toDateString();
            $context['payment_program']['valid_until'] = $program->valid_until?->toDateString();
        }

        $responsible = $responsibleId ? User::query()->where('status', 'active')->find($responsibleId) : null;
        if ($responsible?->isDeletedAccount()) {
            $responsible = null;
        }

        return ['context' => $context, 'responsible_agent_id' => $responsible?->id, 'branch_id' => $responsible?->branch_id];
    }
}

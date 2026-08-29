<?php

namespace App\Services\Residential;

use App\Models\DeveloperUnit;
use App\Models\NewBuilding;
use App\Models\PaymentProgram;
use App\Models\User;
use App\Services\Crm\AuditLogger;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PaymentPrograms
{
    public function __construct(private readonly ResidentialAccess $access, private readonly InventoryWriter $versions, private readonly AuditLogger $audit) {}

    public function ensureManage(User $actor, ?NewBuilding $building): void
    {
        if ($building) {
            $this->access->ensureManage($actor, $building);
        } else {
            abort_unless($this->access->canCreate($actor) && $this->access->global($actor), 403);
        }
    }

    public function canPublish(User $actor, ?NewBuilding $building): bool
    {
        return $building ? $this->access->canPublish($actor, $building) : $this->access->canCreate($actor) && $this->access->global($actor);
    }

    public function save(User $actor, ?NewBuilding $building, array $input, ?int $id = null): PaymentProgram
    {
        $this->ensureManage($actor, $building);
        $data = Validator::make($input, [
            'title' => ($id ? 'sometimes' : 'required').'|required|string|max:255', 'type' => ($id ? 'sometimes' : 'required').'|in:installment,mortgage',
            'bank_name' => 'nullable|string|max:255', 'currency' => 'sometimes|in:TJS', 'scope' => 'sometimes|in:all,blocks,units',
            'calculation_method' => 'sometimes|in:manual,equal_installment,annuity,differentiated', 'period_months' => 'nullable|integer|in:1,3,6,12',
            'term_min_months' => 'nullable|integer|between:1,360', 'term_max_months' => 'nullable|integer|between:1,360',
            'min_down_percent' => 'nullable|numeric|between:0,99.99', 'annual_rate' => 'nullable|numeric|between:0,100',
            'upfront_fee_percent' => 'nullable|numeric|between:0,100', 'upfront_fee_fixed' => 'nullable|numeric|between:0,9999999999999.99',
            'min_principal' => 'nullable|numeric|gt:0|max:9999999999999.99', 'max_principal' => 'nullable|numeric|gt:0|max:9999999999999.99', 'fees_verified' => 'sometimes|boolean',
            'valid_from' => 'nullable|date_format:Y-m-d', 'valid_until' => 'nullable|date_format:Y-m-d', 'conditions' => 'nullable|string|max:10000',
            'source_url' => 'nullable|url:https|max:2000', 'confirmation_reference' => 'nullable|string|max:1000', 'data_verified_at' => 'nullable|date|before_or_equal:now',
            'publication_status' => 'sometimes|in:draft,pending,published,rejected,archived', 'version' => [$id ? 'required' : 'sometimes', 'integer', 'min:1'], 'change_reason' => 'nullable|string|max:1000',
            'block_ids' => 'sometimes|array|max:500', 'block_ids.*' => 'integer|min:1|distinct', 'unit_ids' => 'sometimes|array|max:10000', 'unit_ids.*' => 'integer|min:1|distinct',
        ])->validate();

        return DB::transaction(function () use ($actor, $building, $data, $id) {
            $parent = $building ? NewBuilding::query()->lockForUpdate()->findOrFail($building->id) : null;
            $this->ensureManage($actor, $parent);
            $program = $id ? PaymentProgram::query()->where('new_building_id', $parent?->id)->lockForUpdate()->findOrFail($id) : new PaymentProgram(['new_building_id' => $parent?->id, 'created_by' => $actor->id, 'scope' => 'all', 'calculation_method' => 'manual', 'currency' => 'TJS', 'publication_status' => 'draft']);
            if ($id) {
                $this->versions->checkVersion($program, $data);
            }
            $old = $program->getAttributes();
            $old['block_ids'] = $id ? $program->blocks()->orderBy('id')->pluck('new_building_blocks.id')->all() : [];
            $old['unit_ids'] = $id ? $program->units()->orderBy('id')->pluck('developer_units.id')->all() : [];
            $blocks = $data['block_ids'] ?? $old['block_ids'];
            $units = $data['unit_ids'] ?? $old['unit_ids'];
            $values = $data;
            unset($values['version'], $values['block_ids'], $values['unit_ids'], $values['change_reason']);
            $program->fill($values);
            if (! $parent && ($program->type !== 'mortgage' || $program->scope !== 'all')) {
                throw ValidationException::withMessages(['type' => 'Общие программы используются только для ипотеки. Рассрочка задаётся внутри конкретного ЖК.']);
            }
            if ($program->scope === 'blocks' && (! $blocks || $units) || $program->scope === 'units' && (! $units || $blocks) || $program->scope === 'all' && ($blocks || $units)) {
                throw ValidationException::withMessages(['scope' => 'Выберите только корпуса или только квартиры; для всех объектов ограничения должны быть пустыми.']);
            }
            if ($blocks && (! $parent || $parent->blocks()->whereNull('archived_at')->whereKey($blocks)->count() !== count($blocks))) {
                throw ValidationException::withMessages(['block_ids' => 'Корпуса должны принадлежать этому ЖК и не быть архивными.']);
            }
            if ($units && (! $parent || $parent->units()->whereKey($units)->count() !== count($units))) {
                throw ValidationException::withMessages(['unit_ids' => 'Квартиры должны принадлежать этому ЖК.']);
            }
            if (! $this->canPublish($actor, $parent)) {
                abort_if(isset($data['publication_status']) && ! in_array($data['publication_status'], ['draft', 'pending'], true), 403, 'Условия покупки должен подтвердить модератор.');
                if (! in_array($program->publication_status, ['draft', 'pending'], true)) {
                    $program->publication_status = 'pending';
                }
                $program->verified_by = null;
            }
            $this->validateTerms($program);
            if ($program->publication_status === 'published') {
                foreach (['title', 'conditions', 'source_url', 'confirmation_reference', 'data_verified_at', 'valid_from', 'valid_until'] as $field) {
                    if (empty($program->$field)) {
                        throw ValidationException::withMessages([$field => 'Для публикации нужны источник, подтверждение и срок действия условий.']);
                    }
                }
                if ($program->type === 'mortgage' && ! $program->bank_name) {
                    throw ValidationException::withMessages(['bank_name' => 'Укажите банк, предоставивший подтверждённую программу.']);
                }
                $program->verified_by = $actor->id;
            }
            $program->version = $id ? $program->version + 1 : 1;
            $program->save();
            $program->blocks()->sync($blocks);
            $program->units()->sync($units);
            $this->audit->log($program, $actor, $id ? 'residential.program.updated' : 'residential.program.created', $id ? $old : [], $program->getAttributes() + ['block_ids' => $blocks, 'unit_ids' => $units], $data['change_reason'] ?? null);

            return $program->refresh()->load(['blocks', 'units']);
        }, 3);
    }

    private function validateTerms(PaymentProgram $program): void
    {
        foreach ([['valid_from', 'valid_until'], ['term_min_months', 'term_max_months']] as [$min, $max]) {
            if ($program->$min !== null && $program->$max !== null && $program->$min > $program->$max) {
                throw ValidationException::withMessages([$max => 'Верхняя граница должна быть не меньше нижней.']);
            }
        }
        if ($program->min_principal !== null && $program->max_principal !== null && BigDecimal::of($program->min_principal)->isGreaterThan($program->max_principal)) {
            throw ValidationException::withMessages(['max_principal' => 'Максимальная сумма должна быть не меньше минимальной.']);
        }
        if ($program->calculation_method !== 'manual' && $program->publication_status === 'published') {
            foreach (['period_months', 'term_min_months', 'term_max_months', 'min_down_percent', 'annual_rate', 'upfront_fee_percent', 'upfront_fee_fixed'] as $field) {
                if ($program->$field === null) {
                    throw ValidationException::withMessages([$field => 'Для расчёта требуются полные подтверждённые условия.']);
                }
            }
            if (! $program->fees_verified) {
                throw ValidationException::withMessages(['fees_verified' => 'Подтвердите все комиссии либо выберите ручную консультацию без расчёта.']);
            }
            if ($program->term_min_months % $program->period_months !== 0 || $program->term_max_months % $program->period_months !== 0) {
                throw ValidationException::withMessages(['term_min_months' => 'Сроки должны быть кратны периодичности платежей.']);
            }
        }
        if ($program->calculation_method === 'equal_installment' && $program->annual_rate !== null && ! BigDecimal::of($program->annual_rate)->isZero()) {
            throw ValidationException::withMessages(['annual_rate' => 'Равномерная беспроцентная рассрочка требует ставки 0. Для другой схемы выберите соответствующий метод или ручную консультацию.']);
        }
    }

    public function publicQuery(?NewBuilding $building = null, ?DeveloperUnit $unit = null): Builder
    {
        $query = PaymentProgram::query()->confirmed()->where(fn ($q) => $q->whereNull('new_building_id')->when($building, fn ($q) => $q->orWhere('new_building_id', $building->id)));

        return $query->where(function (Builder $q) use ($unit) {
            $q->where('scope', 'all')->orWhere(fn ($b) => $b->where('scope', 'blocks')->whereHas('blocks', fn ($blocks) => $blocks->whereNull('archived_at')->when($unit, fn ($blocks) => $blocks->whereKey($unit->block_id ?? 0))))
                ->orWhere(fn ($u) => $u->where('scope', 'units')->whereHas('units', fn ($units) => $units->availability(['available', 'reserved', 'sold'])->when($unit, fn ($units) => $units->whereKey($unit->id))));
        })->with(['blocks' => fn ($q) => $q->whereNull('archived_at')->select('new_building_blocks.id', 'new_building_blocks.name')])->withCount(['units as public_units_count' => fn ($q) => $q->availability(['available', 'reserved', 'sold'])]);
    }

    public function serialize(PaymentProgram $program, bool $private = false): array
    {
        $result = $program->only(['id', 'new_building_id', 'title', 'type', 'bank_name', 'currency', 'scope', 'calculation_method', 'period_months', 'term_min_months', 'term_max_months', 'min_down_percent', 'annual_rate', 'upfront_fee_percent', 'upfront_fee_fixed', 'min_principal', 'max_principal', 'fees_verified', 'valid_from', 'valid_until', 'conditions', 'source_url', 'data_verified_at', 'version']);
        $result['block_ids'] = $program->blocks->pluck('id')->all();
        // Calendar dates must not shift one day when JSON converts midnight to UTC.
        $result['valid_from'] = $program->valid_from?->toDateString();
        $result['valid_until'] = $program->valid_until?->toDateString();
        $result['unit_count'] = $private ? $program->units->count() : (int) $program->public_units_count;
        if ($private) {
            $result['unit_ids'] = $program->units->pluck('id')->all();
        }
        $result['blocks'] = $program->blocks->map(fn ($block) => $block->only(['id', 'name']));
        $result['calculator_available'] = $program->calculation_method !== 'manual' && $program->fees_verified;

        return $private ? $result + $program->only(['publication_status', 'confirmation_reference', 'verified_by', 'created_by']) : $result;
    }
}

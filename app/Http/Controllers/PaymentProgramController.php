<?php

namespace App\Http\Controllers;

use App\Models\NewBuilding;
use App\Models\PaymentProgram;
use App\Services\Residential\InventoryStatus;
use App\Services\Residential\PaymentCalculator;
use App\Services\Residential\PaymentPrograms;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PaymentProgramController extends Controller
{
    public function __construct(private readonly PaymentPrograms $programs) {}

    private function context(array $input, ?NewBuilding $building = null): array
    {
        if (! $building && ! empty($input['building_id'])) {
            $building = NewBuilding::query()->published()->findOrFail($input['building_id']);
        }
        if ($building) {
            abort_unless(NewBuilding::query()->published()->whereKey($building->id)->exists(), 404);
        }
        if (! empty($input['unit_id']) && ! $building) {
            throw ValidationException::withMessages(['building_id' => 'Для квартиры укажите ЖК.']);
        }
        $unit = ! empty($input['unit_id']) ? $building->units()->availability(['available', 'reserved', 'sold'])->findOrFail($input['unit_id']) : null;

        return [$building, $unit];
    }

    public function index(Request $request, ?NewBuilding $new_building = null)
    {
        $input = $request->validate(['page' => 'integer|min:1', 'building_id' => 'nullable|integer|min:1', 'unit_id' => 'nullable|integer|min:1', 'type' => 'sometimes|in:installment,mortgage']);
        [$building, $unit] = $this->context($input, $new_building);

        return $this->programs->publicQuery($building, $unit)->when(isset($input['type']), fn ($q) => $q->where('type', $input['type']))->orderBy('id')->paginate(20)->through(fn ($program) => $this->programs->serialize($program));
    }

    public function quote(Request $request, int $program, PaymentCalculator $calculator)
    {
        $input = $request->validate(['version' => 'required|integer|min:1', 'building_id' => 'nullable|integer|min:1', 'unit_id' => 'nullable|integer|min:1', 'unit_version' => 'required_with:unit_id|integer|min:1',
            'price' => 'nullable|numeric|gt:0|max:9999999999999.99', 'down_payment' => 'required_without:down_percent|nullable|numeric|min:0|max:9999999999999.99', 'down_percent' => 'nullable|numeric|between:0,99.99', 'months' => 'required|integer|between:1,360']);
        if (isset($input['down_payment'], $input['down_percent'])) {
            throw ValidationException::withMessages(['down_payment' => 'Укажите взнос либо суммой, либо процентом, не двумя способами сразу.']);
        }
        [$building, $unit] = $this->context($input);
        $record = $this->programs->publicQuery($building, $unit)->findOrFail($program);
        if ($record->scope !== 'all' && ! $unit) {
            throw ValidationException::withMessages(['unit_id' => 'Для программы с ограничениями выберите конкретную квартиру, к которой она применяется.']);
        }
        abort_if((int) $record->version !== (int) $input['version'], 409, 'Условия программы изменились. Загрузите актуальную версию и повторите расчёт.');
        if ($unit) {
            abort_if((int) $unit->version !== (int) $input['unit_version'], 409, 'Цена или статус квартиры изменились. Обновите карточку.');
            if (InventoryStatus::unit($unit->getAttributes())[1] === 'sold') {
                throw ValidationException::withMessages(['unit_id' => 'Квартира продана. Выберите свободную квартиру для расчёта.']);
            }
            $price = $unit->price_on_request ? null : $unit->total_price;
        } else {
            $price = $input['price'] ?? null;
        }
        if ($price === null || BigDecimal::of((string) $price)->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages(['price' => 'Стоимость неизвестна. Уточните её перед расчётом.']);
        }
        $quote = isset($input['down_percent'])
            ? $calculator->calculateWithPercent($record, (string) $price, (string) $input['down_percent'], (int) $input['months'])
            : $calculator->calculate($record, (string) $price, (string) $input['down_payment'], (int) $input['months']);

        return $quote + ['program_id' => $record->id, 'program_version' => $record->version, 'program_title' => $record->title, 'building_id' => $building?->id, 'unit_id' => $unit?->id, 'unit_version' => $unit?->version, 'price_source' => $unit ? 'inventory' : 'user_estimate', 'as_of' => now()->toIso8601String()];
    }

    public function adminIndex(Request $request, ?NewBuilding $new_building = null)
    {
        $this->programs->ensureManage($request->user(), $new_building);
        $input = $request->validate(['page' => 'integer|min:1', 'program_id' => 'sometimes|integer|min:1']);
        $page = PaymentProgram::query()->where('new_building_id', $new_building?->id)
            ->when(isset($input['program_id']), fn ($query) => $query->whereKey($input['program_id']))
            ->with(['blocks', 'units'])->orderByDesc('id')->paginate(20)->through(fn ($program) => $this->programs->serialize($program, true));

        return $page->toArray() + ['can_publish' => $this->programs->canPublish($request->user(), $new_building)];
    }

    public function store(Request $request, ?NewBuilding $new_building = null)
    {
        return response()->json($this->programs->serialize($this->programs->save($request->user(), $new_building, $request->all()), true), 201);
    }

    public function update(Request $request, int $program, ?NewBuilding $new_building = null)
    {
        return $this->programs->serialize($this->programs->save($request->user(), $new_building, $request->all(), $program), true);
    }

    public function updateForBuilding(Request $request, NewBuilding $new_building, int $program)
    {
        return $this->update($request, $program, $new_building);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\NewBuilding;
use App\Models\ResidentialImportBatch;
use App\Services\Residential\InventoryCsv;
use App\Services\Residential\InventoryImport;
use App\Services\Residential\ResidentialAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ResidentialImportController extends Controller
{
    public function __construct(private readonly ResidentialAccess $access, private readonly InventoryImport $imports, private readonly InventoryCsv $csv) {}

    public function preview(Request $request, NewBuilding $new_building)
    {
        $this->access->ensurePublish($request->user(), $new_building);
        $input = $request->validate(['mode' => 'required|in:csv,bulk', 'file' => 'required_if:mode,csv|file|max:5120', 'delimiter' => 'sometimes|in:comma,semicolon', 'unit_ids' => 'required_if:mode,bulk|array|min:1|max:1000', 'unit_ids.*' => 'integer|min:1|distinct', 'changes' => 'required_if:mode,bulk|array:availability_status,pricing_basis,total_price,price_per_sqm,price_on_request,data_verified_at',
            'changes.availability_status' => 'sometimes|in:available,reserved,sold,withdrawn', 'changes.pricing_basis' => 'sometimes|in:total,per_sqm', 'changes.total_price' => 'sometimes|numeric|gt:0|max:9999999999999.99', 'changes.price_per_sqm' => 'sometimes|numeric|gt:0|max:9999999999999.99', 'changes.price_on_request' => 'sometimes|boolean', 'changes.data_verified_at' => 'sometimes|date|before_or_equal:now']);
        if ($input['mode'] === 'csv') {
            $rows = $this->csv->parse($request->file('file'), ($input['delimiter'] ?? 'semicolon') === 'comma' ? ',' : ';');
        } else {
            if (! array_intersect(array_keys($input['changes']), ['availability_status', 'total_price', 'price_per_sqm', 'price_on_request'])) {
                throw ValidationException::withMessages(['changes' => 'Выберите изменение цены или доступности.']);
            }
            if (isset($input['changes']['total_price'], $input['changes']['price_per_sqm']) || ($input['changes']['price_on_request'] ?? false) && (isset($input['changes']['total_price']) || isset($input['changes']['price_per_sqm']))) {
                throw ValidationException::withMessages(['changes' => 'Используйте одну исходную цену либо явное «По запросу».']);
            }
            $rows = array_map(fn ($id, $index) => ['line' => $index + 1, 'id' => $id, 'data' => $input['changes'], 'errors' => []], $input['unit_ids'], array_keys($input['unit_ids']));
        }
        $batch = $this->imports->preview($request->user(), $new_building, $input['mode'], $rows, $request->file('file') ? mb_substr(basename($request->file('file')->getClientOriginalName()), 0, 255) : null);

        return response()->json($this->serialize($batch, 1), 201);
    }

    public function index(Request $request, NewBuilding $new_building)
    {
        $this->access->ensurePublish($request->user(), $new_building);
        $request->validate(['page' => 'sometimes|integer|min:1']);

        return ResidentialImportBatch::query()->where('new_building_id', $new_building->id)->where('actor_id', $request->user()->id)->orderByDesc('id')->paginate(20, ['id', 'mode', 'source_name', 'status', 'counts', 'created_at', 'applied_at', 'expires_at']);
    }

    public function show(Request $request, NewBuilding $new_building, int $batch)
    {
        $this->access->ensurePublish($request->user(), $new_building);
        $input = $request->validate(['page' => 'sometimes|integer|min:1']);

        return $this->serialize(ResidentialImportBatch::query()->where('new_building_id', $new_building->id)->where('actor_id', $request->user()->id)->findOrFail($batch), $input['page'] ?? 1);
    }

    public function apply(Request $request, NewBuilding $new_building, int $batch)
    {
        $request->validate(['confirmed' => 'required|accepted']);

        return $this->serialize($this->imports->apply($request->user(), $new_building, $batch), 1);
    }

    private function serialize(ResidentialImportBatch $batch, int $page): array
    {
        return $batch->only(['id', 'mode', 'source_name', 'status', 'building_version', 'counts', 'result', 'expires_at', 'applied_at', 'created_at']) + ['data' => array_slice($batch->report, ($page - 1) * 20, 20), 'current_page' => $page, 'last_page' => max(1, (int) ceil(count($batch->report) / 20)), 'total' => count($batch->report)];
    }
}

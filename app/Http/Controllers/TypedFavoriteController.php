<?php

namespace App\Http\Controllers;

use App\Models\Favorite;
use App\Services\Residential\FavoriteObjects;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TypedFavoriteController extends Controller
{
    public function __construct(private readonly FavoriteObjects $objects) {}

    public function resolve(Request $request)
    {
        return ['data' => $this->objects->resolve($this->objects->validate($request->all()))];
    }

    public function index(Request $request)
    {
        $data = $request->validate(['page' => 'integer|min:1', 'per_page' => 'integer|between:1,200', 'type' => 'sometimes|in:property,new_building,developer_unit']);
        $page = Favorite::query()->where('user_id', $request->user()->id)->when(isset($data['type']), fn ($q) => $q->where('entity_type', $data['type']))->orderByDesc('id')->paginate($data['per_page'] ?? 50);
        $resolved = $this->objects->resolve($page->getCollection()->map(fn ($favorite) => ['type' => $favorite->entity_type, 'id' => $favorite->entity_type === 'property' ? $favorite->property_id : $favorite->entity_id])->all());
        $page->setCollection(collect($resolved));

        return $page;
    }

    public function keys(Request $request)
    {
        return ['data' => Favorite::query()->where('user_id', $request->user()->id)->orderByDesc('id')->get(['entity_type', 'entity_id', 'property_id'])->map(fn ($favorite) => ['type' => $favorite->entity_type, 'id' => (int) ($favorite->entity_type === 'property' ? $favorite->property_id : $favorite->entity_id)])];
    }

    public function store(Request $request, string $type, int $id)
    {
        $item = $this->objects->resolve([['type' => $type, 'id' => $id]])[0];
        abort_unless($item['available'], 404);
        $this->save($request, $type, $id);

        return response()->json($item, 201);
    }

    public function destroy(Request $request, string $type, int $id)
    {
        Favorite::query()->where($this->key($request, $type, $id))->delete();

        return response()->noContent();
    }

    public function merge(Request $request)
    {
        $items = $this->objects->resolve($this->objects->validate($request->all()));
        DB::transaction(function () use ($request, $items) {
            foreach ($items as $item) {
                if ($item['available']) {
                    $this->save($request, $item['type'], $item['id']);
                }
            }
        });

        // Unpublished/missing guest references are discarded only after this explicit merge receipt.
        return ['merged' => array_values(array_filter($items, fn ($item) => $item['available'])), 'skipped' => array_values(array_filter($items, fn ($item) => ! $item['available']))];
    }

    private function key(Request $request, string $type, int $id): array
    {
        return ['user_id' => $request->user()->id, 'entity_type' => $type, $type === 'property' ? 'property_id' : 'entity_id' => $id];
    }

    private function save(Request $request, string $type, int $id): void
    {
        // Both legacy property and typed APIs use the same unique row, without duplicating notifications.
        Favorite::firstOrCreate($this->key($request, $type, $id));
    }
}

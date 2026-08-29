<?php

namespace App\Services\Residential;

use App\Models\DeveloperUnit;
use App\Models\NewBuilding;
use App\Models\User;
use App\Services\Crm\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class PhotoWriter
{
    public function __construct(private readonly ResidentialAccess $access, private readonly MediaAssets $media, private readonly AuditLogger $audit, private readonly InventoryWriter $inventory) {}

    public function add(User $actor, NewBuilding $building, ?DeveloperUnit $unit, array $files, array $input): array
    {
        $this->access->ensureManage($actor, $building);
        $data = Validator::make($input, [
            'kind' => ['sometimes', Rule::in($unit ? ['photo', 'plan'] : ['photo', 'masterplan'])],
            'alt' => 'nullable|string|max:255', 'is_cover' => 'sometimes|boolean', 'sort_order' => 'nullable|integer|min:0|max:10000',
            'path' => 'prohibited',
        ])->validate();
        Validator::make(['files' => $files], ['files' => 'required|array|min:1|max:20', 'files.*' => 'required|file|mimes:jpg,jpeg,png,webp,avif|max:10240'])->validate();
        $stored = [];
        try {
            foreach ($files as $file) {
                $stored[] = $this->media->upload($file, $building->id);
            }

            return $this->locked($actor, $building, $unit, function (Model $parent) use ($stored, $data, $actor) {
                $rows = [];
                $order = $data['sort_order'] ?? ((int) $parent->photos()->max('sort_order') + 1);
                foreach ($stored as $index => $fields) {
                    $cover = $index === 0 && ($data['is_cover'] ?? ! $parent->photos()->exists());
                    if ($cover) {
                        $parent->photos()->where('is_cover', true)->update(['is_cover' => false, 'version' => DB::raw('version + 1')]);
                    }
                    $photo = $parent->photos()->create($fields + ['kind' => $data['kind'] ?? 'photo', 'alt' => $data['alt'] ?? null, 'sort_order' => $order + $index, 'is_cover' => $cover, 'version' => 1]);
                    $this->audit->log($photo, $actor, 'residential.media.created', [], $photo->getAttributes());
                    $rows[] = $photo;
                }

                return $rows;
            });
        } catch (\Throwable $error) {
            foreach ($stored as $fields) {
                $this->media->discard($fields);
            }
            throw $error;
        }
    }

    public function change(User $actor, NewBuilding $building, ?DeveloperUnit $unit, int $id, string $action, array $input): void
    {
        $data = Validator::make($input, ['version' => 'sometimes|integer|min:1', 'alt' => 'nullable|string|max:255', 'kind' => ['sometimes', Rule::in($unit ? ['photo', 'plan'] : ['photo', 'masterplan'])]])->validate();
        $this->locked($actor, $building, $unit, function (Model $parent) use ($actor, $id, $action, $data) {
            $photo = $parent->photos()->lockForUpdate()->findOrFail($id);
            if (isset($data['version'])) {
                $this->inventory->checkVersion($photo, $data);
            }
            $old = $photo->getAttributes();
            if ($action === 'delete') {
                // Preserve original bytes for recovery/audit; deleting the row revokes its delivery URL.
                // Orphan cleanup is a separate retention policy, never an arbitrary client-supplied path delete.
                $cover = $photo->is_cover;
                $photo->delete();
                if ($cover) {
                    $parent->photos()->orderBy('sort_order')->orderBy('id')->first()?->update(['is_cover' => true, 'version' => DB::raw('version + 1')]);
                }
            } else {
                if ($action === 'cover') {
                    $parent->photos()->where('is_cover', true)->whereKeyNot($id)->update(['is_cover' => false, 'version' => DB::raw('version + 1')]);
                    $photo->is_cover = true;
                } else {
                    $photo->fill(array_intersect_key($data, array_flip(['alt', 'kind'])));
                }
                $photo->version++;
                $photo->save();
            }
            $this->audit->log($photo, $actor, 'residential.media.'.$action, $old, $action === 'delete' ? [] : $photo->getAttributes());
        });
    }

    public function reorder(User $actor, NewBuilding $building, ?DeveloperUnit $unit, array $orders): void
    {
        $data = Validator::make(['orders' => $orders], ['orders' => 'required|array|min:1|max:500', 'orders.*.id' => 'required|integer|distinct', 'orders.*.sort_order' => 'required|integer|between:0,10000', 'orders.*.version' => 'sometimes|integer|min:1'])->validate();
        $this->locked($actor, $building, $unit, function (Model $parent) use ($actor, $data) {
            foreach ($data['orders'] as $order) {
                $photo = $parent->photos()->lockForUpdate()->findOrFail($order['id']);
                if (isset($order['version'])) {
                    $this->inventory->checkVersion($photo, $order);
                }
                $old = $photo->getAttributes();
                $photo->update(['sort_order' => $order['sort_order'], 'version' => $photo->version + 1]);
                $this->audit->log($photo, $actor, 'residential.media.reordered', $old, $photo->getAttributes());
            }
        });
    }

    private function locked(User $actor, NewBuilding $building, ?DeveloperUnit $unit, callable $operation): mixed
    {
        return DB::transaction(function () use ($actor, $building, $unit, $operation) {
            $building = NewBuilding::query()->lockForUpdate()->findOrFail($building->id);
            $this->access->ensureManage($actor, $building);
            $parent = $unit ? $building->units()->lockForUpdate()->findOrFail($unit->id) : $building;
            $result = $operation($parent);
            $old = $parent->getAttributes();
            $publication = $unit ? InventoryStatus::unit($old)[0] : InventoryStatus::building($old);
            if (! $this->access->canPublish($actor, $building) && $publication === 'published') {
                $parent->publication_status = 'pending';
                $parent->moderation_status = 'pending';
                if ($unit) {
                    $parent->is_available = false;
                }
            }
            $parent->version++;
            $parent->save();
            $this->audit->log($parent, $actor, 'residential.media.changed', $old, $parent->getAttributes());

            return $result;
        }, 3);
    }
}

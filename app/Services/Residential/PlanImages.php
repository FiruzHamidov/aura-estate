<?php

namespace App\Services\Residential;

use App\Models\NewBuilding;
use App\Models\User;
use App\Services\Crm\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class PlanImages
{
    public function __construct(private readonly ResidentialAccess $access, private readonly MediaAssets $media, private readonly InventoryWriter $inventory, private readonly AuditLogger $audit) {}

    public function store(User $actor, NewBuilding $building, string $kind, int $id, UploadedFile $file, array $input)
    {
        $this->access->ensureManage($actor, $building);
        $input = Validator::make($input, ['version' => 'required|integer|min:1'])->validate();
        $stored = $this->media->upload($file, $building->id);
        try {
            return DB::transaction(function () use ($actor, $building, $kind, $id, $input, $stored) {
                $parent = NewBuilding::query()->lockForUpdate()->findOrFail($building->id);
                $this->access->ensureManage($actor, $parent);
                $relation = match ($kind) {
                    'layouts' => $parent->layouts(), 'floor-plans' => $parent->floorPlans(), default => abort(404)
                };
                $record = $relation->lockForUpdate()->findOrFail($id);
                $this->inventory->checkVersion($record, $input);
                $old = $record->getAttributes();
                $values = $stored;
                $values['image_path'] = $values['path'];
                unset($values['path']);
                $record->fill($values);
                $record->version++;
                $record->save();
                if ($kind === 'floor-plans' && ! empty($old['image_path'])) {
                    $record->unit_regions = [];
                    $record->save();
                }
                $this->audit->log($record, $actor, 'residential.plan.image', $old, $record->getAttributes());
                $oldParent = $parent->getAttributes();
                if (! $this->access->canPublish($actor, $parent) && InventoryStatus::building($oldParent) === 'published') {
                    $parent->publication_status = 'pending';
                    $parent->moderation_status = 'pending';
                }
                $parent->version++;
                $parent->save();
                $this->audit->log($parent, $actor, 'residential.media.changed', $oldParent, $parent->getAttributes());

                return $record;
            }, 3);
        } catch (\Throwable $error) {
            $this->media->discard($stored);
            throw $error;
        }
    }
}

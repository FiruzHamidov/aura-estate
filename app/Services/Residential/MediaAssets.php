<?php

namespace App\Services\Residential;

use App\Models\BuildingFloorPlan;
use App\Models\DeveloperUnitPhoto;
use App\Models\NewBuildingPhoto;
use App\Models\UnitLayout;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

/** Safe image decoding/storage and stable, access-controlled delivery for all ЖК media. */
final class MediaAssets
{
    public function upload(UploadedFile $file, int $buildingId): array
    {
        Validator::make(['file' => $file], ['file' => 'required|file|mimes:jpg,jpeg,png,webp,avif|max:10240'])->validate();
        $size = @getimagesize($file->getRealPath());
        if (! $size || $size[0] > 8000 || $size[1] > 8000 || $size[0] * $size[1] > 24000000) {
            throw ValidationException::withMessages(['file' => 'Изображение должно быть не больше 8000 px по стороне и 24 мегапикселей.']);
        }
        $base = 'buildings/'.$buildingId.'/'.Str::uuid();
        $paths = ['path' => $base.'-preview.webp', 'original_path' => $base.'-original.webp', 'variants' => []];
        $binaries = [];
        try {
            $image = (new ImageManager(new Driver))->read($file->getRealPath());
            // Re-encoding strips metadata and executable/polyglot payloads. No watermark over plans.
            $original = (string) $image->encode(new WebpEncoder(90));
            $seen = [];
            foreach ([320, 640, 960, 1600] as $edge) {
                $resized = (clone $image)->scaleDown($edge, $edge);
                if (isset($seen[$resized->width()])) {
                    continue;
                }
                $seen[$resized->width()] = true;
                $variant = 'w'.$edge;
                $path = $base.'-'.$variant.'.webp';
                $binaries[$path] = (string) $resized->encode(new WebpEncoder(82));
                $paths['variants'][$variant] = ['path' => $path, 'width' => $resized->width(), 'height' => $resized->height()];
            }
            $preview = (string) (clone $image)->scaleDown(1600, 1600)->encode(new WebpEncoder(82));
        } catch (\Throwable $error) {
            throw ValidationException::withMessages(['file' => 'Не удалось прочитать изображение. Используйте JPEG, PNG или WebP.']);
        }
        try {
            Storage::disk('residential')->put($paths['original_path'], $original);
            Storage::disk('residential')->put($paths['path'], $preview);
            foreach ($binaries as $path => $bytes) {
                Storage::disk('residential')->put($path, $bytes);
            }
        } catch (\Throwable $error) {
            $this->discard($paths);
            throw $error;
        }

        return $paths + ['storage_disk' => 'residential', 'width' => $size[0], 'height' => $size[1]];
    }

    public function discard(array $stored): void
    {
        foreach ($stored['variants'] ?? [] as $variant) {
            if (! empty($variant['path'])) {
                Storage::disk('residential')->delete($variant['path']);
            }
        }
        foreach (['path', 'original_path'] as $field) {
            if (! empty($stored[$field])) {
                Storage::disk('residential')->delete($stored[$field]);
            }
        }
    }

    public function kind(Model $record): string
    {
        return match (true) {
            $record instanceof NewBuildingPhoto => 'building-photos', $record instanceof DeveloperUnitPhoto => 'unit-photos',
            $record instanceof UnitLayout => 'layouts', $record instanceof BuildingFloorPlan => 'floor-plans', default => abort(404),
        };
    }

    public function url(Model $record, string $variant = 'preview', ?User $viewer = null): ?string
    {
        if (! ($record->path ?? $record->image_path)) {
            return null;
        }
        $params = ['kind' => $this->kind($record), 'record' => $record->id, 'variant' => $variant];

        // Private previews work in <img> without exposing a bearer token; permissions are checked again on read.
        return $viewer
            ? URL::temporarySignedRoute('residential.media', now()->addMinutes(10), $params + ['viewer' => $viewer->id])
            : route('residential.media', $params);
    }

    public function photo(Model $photo, ?User $viewer = null): array
    {
        return $photo->only(['id', 'path', 'is_cover', 'sort_order', 'kind', 'alt', 'width', 'height', 'version', 'created_at', 'updated_at']) + ($viewer && $photo instanceof NewBuildingPhoto ? ['block_regions' => $photo->block_regions ?? []] : []) + [
            'url' => $this->url($photo, 'preview', $viewer), 'original_url' => $this->url($photo, 'original', $viewer), 'sources' => $this->sources($photo, $viewer),
        ];
    }

    public function sources(Model $record, ?User $viewer = null): array
    {
        $result = [];
        foreach ($record->variants ?? [] as $variant => $meta) {
            if (! in_array($variant, ['w320', 'w640', 'w960', 'w1600'], true)) {
                continue;
            }
            $result[] = ['url' => $this->url($record, $variant, $viewer), 'width' => $meta['width'], 'height' => $meta['height']];
        }

        return $result;
    }
}

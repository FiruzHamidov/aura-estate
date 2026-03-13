<?php

namespace App\Services\Reels;

use App\Models\Reel;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ReelUploadService
{
    public function diskName(): string
    {
        return (string) config('filesystems.reels_disk', config('filesystems.default', 'public'));
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    public function directUploadPath(string $extension = 'mp4'): string
    {
        $normalized = strtolower(trim($extension)) ?: 'mp4';

        return 'reels/originals/'.now()->format('Y/m').'/'.Str::uuid().'.'.$normalized;
    }

    public function createTemporaryUpload(Reel $reel, string $path, string $mimeType, int $expiresMinutes = 15): array
    {
        $disk = Storage::disk($this->diskName());

        if (!method_exists($disk, 'temporaryUploadUrl')) {
            throw new RuntimeException('Configured reels disk does not support direct uploads.');
        }

        $upload = $disk->temporaryUploadUrl(
            $path,
            Carbon::now()->addMinutes($expiresMinutes),
            [
                'ContentType' => $mimeType,
                'Metadata' => [
                    'reel-id' => (string) $reel->id,
                ],
            ]
        );

        return [
            'disk' => $this->diskName(),
            'path' => $path,
            'method' => 'PUT',
            'upload_url' => $upload['url'],
            'headers' => $upload['headers'] ?? [],
            'expires_at' => Carbon::now()->addMinutes($expiresMinutes)->toIso8601String(),
        ];
    }

    public function fileExists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    public function fileSize(string $path): ?int
    {
        if (!$this->fileExists($path)) {
            return null;
        }

        return (int) $this->disk()->size($path);
    }

    public function publicUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        return $this->disk()->url($path);
    }
}

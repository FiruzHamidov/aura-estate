<?php

namespace App\Services;

use App\Models\ExternalPropertyRequestPhoto;
use Illuminate\Support\Facades\Storage;

final class ExternalRequestMedia
{
    public const DISK = 'external_requests';

    public function disk(ExternalPropertyRequestPhoto $photo): string
    {
        // Legacy files remain readable only through the authorized endpoint
        // while the release command moves them out of public storage.
        return Storage::disk(self::DISK)->exists($photo->file_path) ? self::DISK : 'public';
    }

    public function privatize(ExternalPropertyRequestPhoto $photo): void
    {
        $public = Storage::disk('public');
        $private = Storage::disk(self::DISK);
        if (! $public->exists($photo->file_path)) return;
        $stream = $public->readStream($photo->file_path);
        try {
            if (! $private->writeStream($photo->file_path, $stream)) throw new \RuntimeException('Private media copy failed.');
        } finally {
            if (is_resource($stream)) fclose($stream);
        }
        // Verify the complete copy before removing the publicly reachable original.
        if (hash_file('sha256', $public->path($photo->file_path)) !== hash_file('sha256', $private->path($photo->file_path))) {
            throw new \RuntimeException('Private media verification failed.');
        }
        if (! $public->delete($photo->file_path)) throw new \RuntimeException('Public media removal failed.');
    }
}

<?php

namespace App\Services\Reels;

use App\Models\Reel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use RuntimeException;

class ReelThumbnailGenerator
{
    public function generate(Reel $reel): array
    {
        $disk = Storage::disk(config('filesystems.reels_disk', config('filesystems.default', 'public')));
        $sourceStream = $disk->readStream($reel->video_url);

        if (!is_resource($sourceStream)) {
            throw new RuntimeException('Unable to read reel video from storage.');
        }

        $tempInput = tempnam(sys_get_temp_dir(), 'reel-video-');
        $tempPreview = tempnam(sys_get_temp_dir(), 'reel-preview-');
        $tempThumbnail = tempnam(sys_get_temp_dir(), 'reel-thumb-');

        if ($tempInput === false || $tempPreview === false || $tempThumbnail === false) {
            if (is_resource($sourceStream)) {
                fclose($sourceStream);
            }

            throw new RuntimeException('Unable to allocate temporary files for reel preview generation.');
        }

        $previewJpeg = $tempPreview.'.jpg';
        $thumbnailJpeg = $tempThumbnail.'.jpg';

        try {
            $target = fopen($tempInput, 'wb');

            if (!is_resource($target)) {
                throw new RuntimeException('Unable to create temporary video file.');
            }

            stream_copy_to_stream($sourceStream, $target);
            fclose($target);
            fclose($sourceStream);

            $this->extractFrame($tempInput, $previewJpeg, $reel->poster_second ?? 1);
            $this->makeThumbnail($previewJpeg, $thumbnailJpeg);

            $previewPath = $this->storeGeneratedImage($previewJpeg, 'reels/previews');
            $thumbnailPath = $this->storeGeneratedImage($thumbnailJpeg, 'reels/thumbnails');

            return [
                'preview_image' => $previewPath,
                'thumbnail_url' => $thumbnailPath,
            ];
        } finally {
            if (is_resource($sourceStream)) {
                fclose($sourceStream);
            }

            @unlink($tempInput);
            @unlink($tempPreview);
            @unlink($tempThumbnail);
            @unlink($previewJpeg);
            @unlink($thumbnailJpeg);
        }
    }

    protected function extractFrame(string $input, string $output, int $second): void
    {
        $binary = (string) env('REELS_FFMPEG_BINARY', 'ffmpeg');

        $command = sprintf(
            '%s -y -ss %s -i %s -frames:v 1 -q:v 2 %s 2>&1',
            escapeshellcmd($binary),
            escapeshellarg((string) max(0, $second)),
            escapeshellarg($input),
            escapeshellarg($output)
        );

        exec($command, $outputLines, $exitCode);

        if ($exitCode !== 0 || !is_file($output)) {
            throw new RuntimeException('ffmpeg failed to generate preview frame: '.trim(implode("\n", $outputLines)));
        }
    }

    protected function makeThumbnail(string $previewPath, string $thumbnailPath): void
    {
        $manager = new ImageManager(new Driver());
        $image = $manager->read($previewPath);

        $image->scaleDown(width: 480);
        $image->toJpeg(75)->save($thumbnailPath);
    }

    protected function storeGeneratedImage(string $localPath, string $directory): string
    {
        $extension = pathinfo($localPath, PATHINFO_EXTENSION) ?: 'jpg';
        $relativePath = trim($directory, '/').'/'.now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
        $stream = fopen($localPath, 'rb');

        if (!is_resource($stream)) {
            throw new RuntimeException('Unable to open generated reel preview.');
        }

        $stored = Storage::disk(config('filesystems.reels_disk', config('filesystems.default', 'public')))
            ->put($relativePath, $stream);

        fclose($stream);

        if (!$stored) {
            throw new RuntimeException('Unable to store generated reel preview.');
        }

        return $relativePath;
    }
}

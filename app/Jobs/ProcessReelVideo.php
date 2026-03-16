<?php

namespace App\Jobs;

use App\Models\Reel;
use App\Services\Reels\ReelThumbnailGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessReelVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $reelId
    ) {
    }

    public function handle(ReelThumbnailGenerator $thumbnailGenerator): void
    {
        $reel = Reel::query()->find($this->reelId);

        if (!$reel || $reel->trashed() || $reel->status === Reel::STATUS_ARCHIVED) {
            return;
        }

        $meta = $reel->processing_meta ?? [];
        $meta['processed_at'] = now()->toIso8601String();
        $meta['pipeline'] = $meta['pipeline'] ?? 'stub';

        $generatedAssets = [];
        $generationFailed = false;

        if (!$reel->preview_image || !$reel->thumbnail_url) {
            try {
                $generatedAssets = $thumbnailGenerator->generate($reel);
                $meta['pipeline'] = $reel->preview_image && !$reel->thumbnail_url ? 'image' : 'ffmpeg';
                $meta['preview_generation'] = [
                    'status' => 'generated',
                    'generated_at' => now()->toIso8601String(),
                ];
            } catch (Throwable $exception) {
                $generationFailed = true;
                $meta['preview_generation'] = [
                    'status' => 'failed',
                    'generated_at' => now()->toIso8601String(),
                    'message' => $exception->getMessage(),
                ];
            }
        }

        $reel->forceFill([
            'transcode_status' => $generationFailed ? Reel::TRANSCODE_FAILED : Reel::TRANSCODE_COMPLETED,
            'status' => $generationFailed ? Reel::STATUS_PROCESSING : Reel::STATUS_PUBLISHED,
            'published_at' => $generationFailed ? null : ($reel->published_at ?? now()),
            'mp4_url' => $reel->mp4_url ?: $reel->video_url,
            'preview_image' => $reel->preview_image ?: ($generatedAssets['preview_image'] ?? null),
            'thumbnail_url' => $reel->thumbnail_url ?: ($generatedAssets['thumbnail_url'] ?? null),
            'processing_meta' => $meta,
        ])->save();
    }
}

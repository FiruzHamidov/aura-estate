<?php

namespace App\Jobs;

use App\Models\Reel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessReelVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $reelId
    ) {
    }

    public function handle(): void
    {
        $reel = Reel::query()->find($this->reelId);

        if (!$reel || $reel->trashed() || $reel->status === Reel::STATUS_ARCHIVED) {
            return;
        }

        $meta = $reel->processing_meta ?? [];
        $meta['processed_at'] = now()->toIso8601String();
        $meta['pipeline'] = $meta['pipeline'] ?? 'stub';

        $reel->forceFill([
            'transcode_status' => Reel::TRANSCODE_COMPLETED,
            'status' => Reel::STATUS_PUBLISHED,
            'published_at' => $reel->published_at ?? now(),
            'mp4_url' => $reel->mp4_url ?: $reel->video_url,
            'processing_meta' => $meta,
        ])->save();
    }
}

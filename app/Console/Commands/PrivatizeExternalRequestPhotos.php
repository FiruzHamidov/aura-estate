<?php

namespace App\Console\Commands;

use App\Models\ExternalPropertyRequestPhoto;
use App\Services\ExternalRequestMedia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class PrivatizeExternalRequestPhotos extends Command
{
    protected $signature = 'rop-groups:privatize-request-photos {--apply}';
    protected $description = 'Audit or move private request photos out of the public web root.';

    public function handle(ExternalRequestMedia $media): int
    {
        $report = ['applied' => (bool) $this->option('apply'), 'moved' => 0, 'candidates' => 0, 'issues' => []];
        ExternalPropertyRequestPhoto::query()->orderBy('id')->chunkById(200, function ($photos) use ($media, &$report) {
            foreach ($photos as $photo) {
                if (! Storage::disk('public')->exists($photo->file_path)) {
                    if (! Storage::disk(ExternalRequestMedia::DISK)->exists($photo->file_path)) {
                        $report['issues'][] = ['photo_id' => $photo->id, 'reason' => 'missing_file'];
                    }
                    continue;
                }
                if (DB::table('property_photos')->where('file_path', $photo->file_path)->exists()) {
                    $report['issues'][] = ['photo_id' => $photo->id, 'reason' => 'shared_with_property_requires_classification'];
                    continue;
                }
                $report['candidates']++;
                if ($this->option('apply')) {
                    $media->privatize($photo);
                    $report['moved']++;
                }
            }
        });
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $report['issues'] === [] ? self::SUCCESS : self::FAILURE;
    }
}

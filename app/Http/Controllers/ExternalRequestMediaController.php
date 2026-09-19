<?php

namespace App\Http\Controllers;

use App\Models\ExternalPropertyRequestPhoto;
use App\Services\ExternalPropertyRequestService;
use App\Services\ExternalRequestMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class ExternalRequestMediaController extends Controller
{
    public function show(Request $http, ExternalPropertyRequestPhoto $photo, ExternalPropertyRequestService $requests, ExternalRequestMedia $media)
    {
        $actor = $http->user();
        $request = $photo->request;
        abort_unless($request, 404, 'NOT_FOUND');
        if ($actor->hasRole('external_agent')) {
            abort_unless((int) $request->external_agent_id === (int) $actor->id, 404, 'NOT_FOUND');
        } else {
            abort_unless($requests->scopedInternalQuery($actor)->whereKey($request->id)->exists(), 404, 'NOT_FOUND');
        }
        $disk = Storage::disk($media->disk($photo));
        abort_unless($disk->exists($photo->file_path), 404, 'NOT_FOUND');
        return response()->file($disk->path($photo->file_path), [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\FirebasePushService;
use Illuminate\Http\JsonResponse;

class PushHealthController extends Controller
{
    public function __invoke(FirebasePushService $firebase): JsonResponse
    {
        return response()->json([
            'status' => $firebase->isConfigured() ? 'ready' : 'not_configured',
        ])->header('Cache-Control', 'no-store');
    }
}

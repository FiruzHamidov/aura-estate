<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
            'platform' => ['nullable', 'string', 'in:web'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $tokenHash = hash('sha256', $validated['token']);

        $subscription = PushSubscription::query()->updateOrCreate(
            ['token_hash' => $tokenHash],
            [
                'user_id' => $request->user()->id,
                'token' => $validated['token'],
                'platform' => $validated['platform'] ?? 'web',
                'device_name' => $validated['device_name'] ?? null,
                'user_agent' => $request->userAgent(),
                'last_used_at' => now(),
            ]
        );

        return response()->json([
            'id' => $subscription->id,
            'registered' => true,
        ], $subscription->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
        ]);

        PushSubscription::query()
            ->where('user_id', $request->user()->id)
            ->where('token_hash', hash('sha256', $validated['token']))
            ->delete();

        return response()->json(['registered' => false]);
    }
}

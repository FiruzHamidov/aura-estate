<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsNotClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $user?->loadMissing('role');

        if (in_array($user?->role?->slug, ['client', 'external_agent'], true)) {
            return response()->json([
                'message' => 'Forbidden',
            ], 403);
        }

        return $next($request);
    }
}

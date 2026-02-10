<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->status !== 'active') {
            if (method_exists($user, 'tokens')) {
                $user->tokens()->delete();
            }

            return response()->json([
                'message' => 'Аккаунт деактивирован. Доступ запрещён.',
            ], 403);
        }

        return $next($request);
    }
}

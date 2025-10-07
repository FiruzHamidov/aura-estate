<?php

namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class B24Jwt
{
    public function handle(Request $request, Closure $next)
    {
        $auth = $request->bearerToken();
        if (!$auth) {
            return new JsonResponse(['message' => 'Unauthorized: missing Bearer token'], 401);
        }

        $secret = env('B24_WIDGET_JWT_KEY');
        if (!$secret) {
            return new JsonResponse(['message' => 'Server misconfiguration: B24_WIDGET_JWT_KEY is not set'], 500);
        }

        try {
            // HS256 decode (firebase/php-jwt v6)
            $payload = JWT::decode($auth, new Key($secret, 'HS256'));

            // (optional) basic payload checks — add your own domain/dealId/user checks if needed
            // if (empty($payload->dom) || empty($payload->dealId)) {
            //     return new JsonResponse(['message' => 'Invalid token payload'], 401);
            // }

            // make payload available for controllers
            $request->attributes->set('b24jwt', (array) $payload);
        } catch (\Throwable $e) {
            return new JsonResponse(['message' => 'Bad token', 'error' => $e->getMessage()], 401);
        }

        return $next($request);
    }
}

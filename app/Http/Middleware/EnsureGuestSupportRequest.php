<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGuestSupportRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');

        if ($origin && ! $this->isAllowedOrigin($origin)) {
            abort(403, 'Origin is not allowed.');
        }

        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
            && ! $request->routeIs('guest-support.broadcasting.auth')) {
            abort_unless($request->isJson(), 415, 'JSON request required.');
        }

        return $next($request);
    }

    private function isAllowedOrigin(string $origin): bool
    {
        foreach ((array) config('cors.allowed_origins', []) as $allowed) {
            $pattern = str_replace('\\*', '[^.]+', preg_quote((string) $allowed, '#'));

            if (preg_match('#^'.$pattern.'$#i', $origin)) {
                return true;
            }
        }

        foreach ((array) config('cors.allowed_origins_patterns', []) as $pattern) {
            if (@preg_match((string) $pattern, $origin) === 1) {
                return true;
            }
        }

        return false;
    }
}

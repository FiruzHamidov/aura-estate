<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureTraceId
{
    public function handle(Request $request, Closure $next): Response
    {
        $traceId = (string) ($request->header('X-Trace-Id') ?: Str::uuid());
        $request->attributes->set('trace_id', $traceId);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Trace-Id', $traceId);

        return $response;
    }
}

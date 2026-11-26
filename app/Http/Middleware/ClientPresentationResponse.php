<?php

namespace App\Http\Middleware;

use App\Support\ClientPresentation;
use Closure;
use Illuminate\Http\Request;

final class ClientPresentationResponse
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        // A staff response must never be reused for a client presentation.
        $response->setVary('X-Aura-Client-Presentation', false);
        if (ClientPresentation::enabled($request)) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}

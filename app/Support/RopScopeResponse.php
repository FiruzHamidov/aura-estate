<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RopScopeResponse
{
    public const VERSION_ATTRIBUTE = 'rop_access_scope_version';

    public static function headers(Response $response, Request $request): Response
    {
        $version = $request->attributes->get(self::VERSION_ATTRIBUTE);
        if ($version !== null) {
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('X-Access-Scope-Version', (string) $version);
        }

        return $response;
    }
}

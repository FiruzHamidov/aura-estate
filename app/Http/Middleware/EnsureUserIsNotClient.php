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

        // HR can select existing roles/groups, but cannot change these dictionaries.
        if ($user?->role?->slug === 'hr'
            && $request->is('api/roles', 'api/roles/*', 'api/branch-groups', 'api/branch-groups/*')
            && ! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Accountants can read the objects report and its filters, not modify properties or dictionaries.
        if ($user?->role?->slug === 'accountant') {
            if (in_array($request->method(), ['GET', 'HEAD'], true)
                && (in_array($request->path(), ['api/roles', 'api/branch-groups', 'api/my-properties', 'api/branches', 'api/contract-types', 'api/document-types'], true)
                    || ($request->path() === 'api/user' && $request->boolean('report_agents')))) {
                return $next($request);
            }

            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (in_array($user?->role?->slug, ['client', 'external_agent'], true)) {
            return response()->json([
                'message' => 'Forbidden',
            ], 403);
        }

        return $next($request);
    }
}

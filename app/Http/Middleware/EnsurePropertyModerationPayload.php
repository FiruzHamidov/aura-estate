<?php

namespace App\Http\Middleware;

use App\Models\Property;
use App\Services\PropertyModeration\PropertyModerationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePropertyModerationPayload
{
    public function __construct(private readonly PropertyModerationService $moderation) {}

    public function handle(Request $request, Closure $next, string ...$allowed): Response
    {
        $property = $request->route('property');
        $this->moderation->assertNoProtectedFields(
            $request, $allowed, $request->user(), $property instanceof Property ? $property : null,
        );

        return $next($request);
    }
}

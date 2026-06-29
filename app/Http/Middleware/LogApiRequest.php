<?php

namespace App\Http\Middleware;

use App\Models\ApiRequestLog;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        try {
            /** @var Response $response */
            $response = $next($request);
            $this->record($request, $startedAt, $response->getStatusCode(), null, $response);

            return $response;
        } catch (Throwable $e) {
            $this->record($request, $startedAt, $this->statusFromThrowable($e), $e);
            throw $e;
        }
    }

    private function record(
        Request $request,
        float $startedAt,
        ?int $statusCode,
        ?Throwable $e = null,
        ?Response $response = null
    ): void
    {
        if (! $this->shouldLog($request)) {
            return;
        }

        try {
            $user = $request->user();
            $route = $request->route();

            ApiRequestLog::create([
                'trace_id' => $request->attributes->get('trace_id'),
                'user_id' => $user?->id,
                'role_slug' => $user?->role?->slug,
                'method' => $request->method(),
                'path' => $request->path(),
                'route_name' => $route?->getName(),
                'controller_action' => $this->controllerAction($route?->getActionName()),
                'status_code' => $statusCode,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'client_locale' => $request->attributes->get('client_locale'),
                'request_query' => $this->sanitizeArray($request->query()),
                'request_body' => $this->requestBody($request),
                'error_code' => $this->errorCode($e, $statusCode, $response),
                'error_message' => $this->errorMessage($e, $statusCode, $response),
                'created_at' => now(),
            ]);
        } catch (Throwable $logError) {
            Log::warning('Failed to write API request audit log.', [
                'trace_id' => $request->attributes->get('trace_id'),
                'method' => $request->method(),
                'path' => $request->path(),
                'error' => $logError->getMessage(),
            ]);
        }
    }

    private function shouldLog(Request $request): bool
    {
        if (! (bool) config('audit.api_requests.enabled', true)) {
            return false;
        }

        $methods = array_map('strtoupper', (array) config('audit.api_requests.log_methods', []));
        if ($methods !== [] && ! in_array(strtoupper($request->method()), $methods, true)) {
            return false;
        }

        $path = trim($request->path(), '/');
        foreach ((array) config('audit.api_requests.excluded_paths', []) as $excludedPath) {
            $excludedPath = trim((string) $excludedPath, '/');
            if ($excludedPath === '') {
                continue;
            }

            $isWildcard = str_ends_with($excludedPath, '*');
            if ($path === $excludedPath || ($isWildcard && str_starts_with($path, rtrim($excludedPath, '*')))) {
                return false;
            }
        }

        return true;
    }

    private function requestBody(Request $request): ?array
    {
        $body = $request->except(array_keys($request->files->all()));

        foreach ($request->files->all() as $key => $file) {
            $body[$key] = $this->fileMeta($file);
        }

        return $body === [] ? null : $this->sanitizeArray($body);
    }

    private function fileMeta(mixed $file): array
    {
        if (is_array($file)) {
            return [
                'has_file' => true,
                'count' => count($file),
            ];
        }

        return ['has_file' => true];
    }

    private function sanitizeArray(array $payload): array
    {
        $maxItems = max(1, (int) config('audit.api_requests.max_array_items', 50));
        $sanitized = [];
        $index = 0;

        foreach ($payload as $key => $value) {
            if ($index >= $maxItems) {
                $sanitized['_truncated'] = true;
                break;
            }

            $sanitized[$key] = $this->isSensitiveKey((string) $key)
                ? '[redacted]'
                : $this->sanitizeValue($value);
            $index++;
        }

        return $sanitized;
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->sanitizeArray($value);
        }

        if (is_string($value)) {
            return $this->truncate($value);
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return '[unserializable]';
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        foreach ((array) config('audit.api_requests.sensitive_fields', []) as $field) {
            $field = strtolower((string) $field);
            if ($field !== '' && ($key === $field || str_contains($key, $field))) {
                return true;
            }
        }

        return false;
    }

    private function truncate(string $value): string
    {
        $maxLength = max(1, (int) config('audit.api_requests.max_string_length', 1000));

        return mb_strlen($value) > $maxLength
            ? mb_substr($value, 0, $maxLength).'...'
            : $value;
    }

    private function statusFromThrowable(Throwable $e): int
    {
        if ($e instanceof AuthenticationException) {
            return 401;
        }

        if ($e instanceof ValidationException) {
            return 422;
        }

        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode();
        }

        return 500;
    }

    private function errorCode(?Throwable $e, ?int $statusCode, ?Response $response = null): ?string
    {
        if ($e instanceof AuthenticationException) {
            return 'UNAUTHENTICATED';
        }

        if ($e) {
            return $this->defaultErrorCode($statusCode);
        }

        if (! $statusCode || $statusCode < 400) {
            return null;
        }

        $payload = $this->jsonResponsePayload($response);

        return isset($payload['code']) && is_string($payload['code'])
            ? $this->truncate($payload['code'])
            : $this->defaultErrorCode($statusCode);
    }

    private function defaultErrorCode(?int $statusCode): string
    {
        return match ($statusCode) {
            401 => 'UNAUTHENTICATED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            409 => 'CONFLICT',
            422 => 'VALIDATION_ERROR',
            500 => 'INTERNAL_ERROR',
            default => 'REQUEST_FAILED',
        };
    }

    private function errorMessage(?Throwable $e, ?int $statusCode, ?Response $response = null): ?string
    {
        if ($e) {
            return $this->truncate($e->getMessage());
        }

        if (! $statusCode || $statusCode < 400) {
            return null;
        }

        $payload = $this->jsonResponsePayload($response);

        return isset($payload['message']) && is_string($payload['message'])
            ? $this->truncate($payload['message'])
            : null;
    }

    private function jsonResponsePayload(?Response $response): array
    {
        if (! $response || ! str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            return [];
        }

        $decoded = json_decode((string) $response->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function controllerAction(?string $action): ?string
    {
        return $action && $action !== 'Closure' ? $action : null;
    }
}

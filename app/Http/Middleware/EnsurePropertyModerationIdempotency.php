<?php

namespace App\Http\Middleware;

use App\Models\PropertyModerationIdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePropertyModerationIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) ($request->header('Idempotency-Key') ?: $request->header('X-Idempotency-Key')));
        if ($key === '') {
            return $next($request);
        }

        if (mb_strlen($key) < 16 || mb_strlen($key) > 128) {
            return response()->json([
                'code' => 'INVALID_IDEMPOTENCY_KEY',
                'message' => 'Idempotency-Key должен содержать от 16 до 128 символов.',
            ], 422);
        }

        $userId = $request->user()?->id;
        if (! $userId) {
            return $next($request);
        }

        $fingerprint = hash('sha256', json_encode([
            'method' => $request->method(),
            'route' => $request->route()?->uri() ?? $request->path(),
            'parameters' => $this->canonicalize($request->route()?->parameters() ?? []),
            'payload' => $this->canonicalize($request->all()),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        try {
            $record = PropertyModerationIdempotencyKey::query()->firstOrCreate(
                ['user_id' => $userId, 'idempotency_key' => $key],
                [
                    'property_id' => $this->propertyId($request),
                    'request_fingerprint' => $fingerprint,
                    'route' => $request->route()?->uri() ?? $request->path(),
                    'status' => 'processing',
                ]
            );
        } catch (QueryException) {
            $record = PropertyModerationIdempotencyKey::query()
                ->where('user_id', $userId)
                ->where('idempotency_key', $key)
                ->firstOrFail();
        }

        if (! $record->wasRecentlyCreated) {
            if (! hash_equals((string) $record->request_fingerprint, $fingerprint)) {
                return response()->json([
                    'code' => 'IDEMPOTENCY_KEY_REUSED',
                    'message' => 'Idempotency-Key уже использован для другого запроса.',
                ], 409);
            }

            if ($record->status === 'completed') {
                return response((string) $record->response_body, (int) $record->response_status, [
                    'Content-Type' => $record->response_content_type ?: 'application/json',
                    'Idempotent-Replayed' => 'true',
                ]);
            }

            return response()->json([
                'code' => 'IDEMPOTENCY_REQUEST_IN_PROGRESS',
                'message' => 'Запрос с этим Idempotency-Key уже выполняется.',
            ], 409);
        }

        try {
            return DB::transaction(function () use ($request, $next, $record): Response {
                $response = $next($request);
                $record->forceFill([
                    'status' => 'completed',
                    'response_status' => $response->getStatusCode(),
                    'response_body' => $response->getContent(),
                    'response_content_type' => $response->headers->get('Content-Type', 'application/json'),
                ])->save();

                return $response;
            });
        } catch (\Throwable $exception) {
            $record->delete();
            throw $exception;
        }
    }

    private function propertyId(Request $request): ?int
    {
        $property = $request->route('property');
        if (is_object($property) && isset($property->id)) {
            return (int) $property->id;
        }

        return is_numeric($property) ? (int) $property : null;
    }

    private function canonicalize(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            return ['name' => $value->getClientOriginalName(), 'sha256' => hash_file('sha256', $value->getPathname())];
        }
        if (is_object($value) && method_exists($value, 'getRouteKey')) {
            return $value->getRouteKey();
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item) => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item) => $this->canonicalize($item), $value);
    }
}

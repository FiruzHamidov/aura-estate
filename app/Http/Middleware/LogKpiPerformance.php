<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogKpiPerformance
{
    public function handle(Request $request, Closure $next): Response
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $startedAt = microtime(true);

        try {
            /** @var Response $response */
            $response = $next($request);
            return $response;
        } finally {
            $queries = $connection->getQueryLog();
            $sqlMs = array_sum(array_map(fn (array $query) => (float) ($query['time'] ?? 0), $queries));
            $responseBytes = isset($response) ? strlen((string) $response->getContent()) : 0;
            $payload = isset($response) ? json_decode((string) $response->getContent(), true) : null;
            $responseRows = is_array($payload['data'] ?? null) ? count($payload['data']) : 0;
            Log::info('kpi.performance', [
                'trace_id' => $request->attributes->get('trace_id'),
                'endpoint' => '/'.$request->path(),
                'total_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'sql_ms' => round($sqlMs, 2),
                'non_sql_ms' => round(max(0, (microtime(true) - $startedAt) * 1000 - $sqlMs), 2),
                'sql_count' => count($queries),
                'response_bytes' => $responseBytes,
                'response_rows' => $responseRows,
                'queries' => array_map(fn (array $query) => [
                    'time_ms' => (float) ($query['time'] ?? 0),
                    'sql' => $query['query'] ?? '',
                ], $queries),
            ]);
            $connection->disableQueryLog();
        }
    }
}

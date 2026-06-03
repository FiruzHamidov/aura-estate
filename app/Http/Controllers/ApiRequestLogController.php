<?php

namespace App\Http\Controllers;

use App\Models\ApiRequestLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ApiRequestLogController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAuditAccess($request);

        $validated = $request->validate([
            'user_id' => 'nullable|integer|exists:users,id',
            'method' => 'nullable|string|max:12',
            'path' => 'nullable|string|max:255',
            'status_code' => 'nullable|integer|min:100|max:599',
            'trace_id' => 'nullable|string|max:64',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'min_duration_ms' => 'nullable|integer|min:0',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $logs = ApiRequestLog::query()
            ->with('user.role')
            ->when($validated['user_id'] ?? null, fn (Builder $query, int $userId) => $query->where('user_id', $userId))
            ->when($validated['method'] ?? null, fn (Builder $query, string $method) => $query->where('method', strtoupper($method)))
            ->when($validated['path'] ?? null, fn (Builder $query, string $path) => $query->where('path', 'like', '%'.$path.'%'))
            ->when($validated['status_code'] ?? null, fn (Builder $query, int $status) => $query->where('status_code', $status))
            ->when($validated['trace_id'] ?? null, fn (Builder $query, string $traceId) => $query->where('trace_id', $traceId))
            ->when($validated['date_from'] ?? null, fn (Builder $query, string $date) => $query->where('created_at', '>=', $date))
            ->when($validated['date_to'] ?? null, fn (Builder $query, string $date) => $query->where('created_at', '<=', $date))
            ->when($validated['min_duration_ms'] ?? null, fn (Builder $query, int $duration) => $query->where('duration_ms', '>=', $duration))
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 20))
            ->through(fn (ApiRequestLog $log) => $this->serializeSummary($log));

        return response()->json($logs);
    }

    public function show(Request $request, ApiRequestLog $apiRequestLog)
    {
        $this->authorizeAuditAccess($request);

        $apiRequestLog->loadMissing('user.role');

        return response()->json($this->serializeDetail($apiRequestLog));
    }

    private function authorizeAuditAccess(Request $request): void
    {
        $role = $request->user()?->role?->slug;

        abort_unless(in_array($role, ['admin', 'superadmin'], true), 403, 'Forbidden');
    }

    private function serializeSummary(ApiRequestLog $log): array
    {
        return [
            'id' => $log->id,
            'trace_id' => $log->trace_id,
            'user_id' => $log->user_id,
            'user_name' => $log->user?->name,
            'role_slug' => $log->role_slug,
            'method' => $log->method,
            'path' => $log->path,
            'status_code' => $log->status_code,
            'duration_ms' => $log->duration_ms,
            'error_code' => $log->error_code,
            'error_message' => $log->error_message,
            'created_at' => $log->created_at,
        ];
    }

    private function serializeDetail(ApiRequestLog $log): array
    {
        return array_merge($this->serializeSummary($log), [
            'route_name' => $log->route_name,
            'controller_action' => $log->controller_action,
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
            'client_locale' => $log->client_locale,
            'request_query' => $log->request_query,
            'request_body' => $log->request_body,
        ]);
    }
}

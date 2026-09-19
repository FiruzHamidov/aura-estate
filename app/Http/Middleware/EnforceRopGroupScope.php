<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\RopGroupAccess;
use App\Support\RopScopeResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class EnforceRopGroupScope
{
    public function __construct(private readonly RopGroupAccess $access) {}

    public function handle(Request $request, Closure $next)
    {
        $actor = $request->user() ?? $request->user('sanctum');
        if (! $this->access->applies($actor)) {
            return $next($request);
        }
        $request->attributes->set(RopScopeResponse::VERSION_ATTRIBUTE, (int) $actor->access_scope_version);

        // These internal modules have no trustworthy group ownership yet (spec §6).
        // Their public storefront endpoints retain the public projection.
        $controller = $request->route()?->getActionName();
        abort_if($controller === 'App\\Http\\Controllers\\ChatController@feedback', 403, 'FORBIDDEN_ACTION');
        abort_if(in_array($controller, [
            'App\\Http\\Controllers\\KpiModuleController@integrationsStatus',
            'App\\Http\\Controllers\\KpiModuleController@telegramConfig',
            'App\\Http\\Controllers\\KpiModuleController@updateTelegramConfig',
        ], true), 403, 'FORBIDDEN_ACTION');
        abort_if(! $request->isMethodSafe() && $controller
            && (str_starts_with($controller, 'App\\Http\\Controllers\\RoleController@')
                || str_starts_with($controller, 'App\\Http\\Controllers\\BranchController@')), 403, 'FORBIDDEN_ACTION');
        $publicStory = in_array($controller, [
            'App\\Http\\Controllers\\StoryController@feed',
            'App\\Http\\Controllers\\StoryController@show',
            'App\\Http\\Controllers\\StoryController@trackView',
        ], true);
        abort_if(($controller && str_starts_with($controller, 'App\\Http\\Controllers\\AdminStoryController@'))
            || ($controller && str_starts_with($controller, 'App\\Http\\Controllers\\StoryController@') && ! $publicStory)
            || ($controller && str_starts_with($controller, 'App\\Http\\Controllers\\MotivationController@')
                && $controller !== 'App\\Http\\Controllers\\MotivationController@rules'), 403, 'FORBIDDEN_ACTION');

        $run = function () use ($request, $next, $actor) {
            $this->validateFilters($request->all(), $actor, $request->isMethodSafe());
            return RopScopeResponse::headers($next($request), $request);
        };

        if ($request->isMethodSafe()) {
            return $run();
        }

        // Assignment changes and writes serialize on the same actor row.
        return DB::transaction(function () use ($actor, $run, $request) {
            $fresh = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($fresh->status === User::STATUS_ACTIVE && ! $fresh->isDeletedAccount(), 403, 'FORBIDDEN_ACTION');
            $request->attributes->set(RopScopeResponse::VERSION_ATTRIBUTE, (int) $fresh->access_scope_version);
            abort_unless($fresh->hasRole('rop') && (int) $fresh->access_scope_version === (int) $actor->access_scope_version, 409, 'ACCESS_SCOPE_VERSION_CONFLICT');

            $response = $run();
            if ($response->getStatusCode() >= 400) {
                // Laravel's routing pipeline can render exceptions before returning
                // here. An error response must still roll back the enclosing write.
                throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
            }

            return $response;
        });
    }

    private function validateFilters(array $input, User $actor, bool $read): void
    {
        foreach ($input as $key => $value) {
            if (is_array($value) && ! in_array($key, ['branch_id', 'branch_group_id', 'agent_id', 'user_id', 'responsible_agent_id', 'responsible_user_id', 'assignee_id', 'co_owner_user_id', 'sale_user_id', 'deposit_user_id'], true)) {
                $this->validateFilters($value, $actor, $read);
                continue;
            }
            if (! in_array($key, ['branch_id', 'branch_group_id', 'agent_id', 'user_id', 'responsible_agent_id', 'responsible_user_id', 'assignee_id', 'co_owner_user_id', 'sale_user_id', 'deposit_user_id'], true)) {
                continue;
            }
            $values = is_array($value) ? $value : explode(',', (string) $value);
            foreach ($values as $id) {
                if ($id === null || $id === '') {
                    continue;
                }
                abort_unless(is_scalar($id) && ctype_digit((string) $id) && (int) $id > 0, 422, 'INVALID_SCOPE_FILTER');
                if ($key === 'branch_id') {
                    abort_unless((int) $id === (int) $actor->branch_id, 403, 'RBAC_GROUP_SCOPE_VIOLATION');
                } elseif ($key === 'branch_group_id') {
                    $this->access->ensureGroup($actor, (int) $id);
                } else {
                    // Self-service endpoints still enforce their own subject/ability.
                    if ($key === 'user_id' && (int) $id === (int) $actor->id) {
                        continue;
                    }
                    if ($read && $key === 'assignee_id'
                        && request()->is('api/crm/tasks', 'api/crm/tasks/kpi-daily-summary', 'api/crm/tasks/kpi-weekly-summary')
                        && $this->access->hasVisibleTaskHistory($actor, (int) $id)) {
                        continue;
                    }
                    if ($read && $key === 'user_id' && request()->is('api/daily-reports', 'api/kpi-reports', 'api/kpi/weekly', 'api/kpi/monthly', 'api/kpi/weekly-daily', 'api/kpi/daily', 'api/kpi/dashboard', 'api/kpi/dashboard/debug')
                        && $this->access->hasVisibleDailyReportHistory($actor, (int) $id)) {
                        continue;
                    }
                    $this->access->ensureEmployee($actor, (int) $id, assignable: ! $read);
                }
            }
        }
    }
}

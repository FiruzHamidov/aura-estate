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

        $action = $request->route()?->getActionName();
        if ($action && str_starts_with($action, 'App\\Http\\Controllers\\PropertyReportController@')) {
            abort_unless($user?->hasPermission('property_reports.view'), 403, 'FORBIDDEN_ACTION');
            return $next($request);
        }

        // Only audited property actions may replace the legacy role gate.
        // Controllers/services still enforce the operation, record scope and fresh write checks.
        foreach ($this->propertyActionGrants($action) as $permission) {
            if ($user?->hasPermission($permission)) return $next($request);
        }

        // Photo mutations enforce both permission and ownership before validation;
        // keep their established denial response and group-scope checks.
        if (in_array($action, [
            \App\Http\Controllers\PropertyPhotoController::class.'@store',
            \App\Http\Controllers\PropertyPhotoController::class.'@destroy',
            \App\Http\Controllers\PropertyPhotoController::class.'@reorder',
        ], true)) return $next($request);

        // Catalog mutation grants replace the old internal-role blanket access.
        foreach (config('catalog_permissions', []) as $catalog => $definition) {
            $controller = 'App\\Http\\Controllers\\'.$definition['controller'].'@';
            if (!$action || !str_starts_with($action, $controller)) continue;
            $allowed = $user?->hasPermission('catalogs.entries.'.$catalog.'.manage');
            abort_unless($allowed || ($request->isMethodSafe() && $user?->hasPermission('catalogs.details.view')), 403, 'FORBIDDEN_ACTION');
            return $next($request);
        }

        if ($request->is('api/roles', 'api/roles/*')
            && ($user?->hasPermission('roles.manage') || ($request->isMethodSafe() && $user?->hasPermission('roles.view')))) {
            return $next($request);
        }

        if ($action && str_starts_with($action, 'App\\Http\\Controllers\\SelectionController@')) {
            $permission = str_ends_with($action, '@store') ? 'selections.create' : 'selections.view';
            abort_unless($user?->hasPermission($permission), 403, 'FORBIDDEN_ACTION');
            return $next($request);
        }

        // These controllers enforce action grants and resource scope after admission.
        [$controller] = explode('@', $action ?? '', 2);
        if (in_array($controller, [
            \App\Http\Controllers\NewBuildingController::class,
            \App\Http\Controllers\NewBuildingBlockController::class,
            \App\Http\Controllers\NewBuildingPhotoController::class,
            \App\Http\Controllers\DeveloperUnitController::class,
            \App\Http\Controllers\DeveloperUnitPhotoController::class,
            \App\Http\Controllers\ResidentialStructureController::class,
            \App\Http\Controllers\ResidentialPlanController::class,
        ], true) && $user?->hasPermission('new_buildings.manage')) {
            return $next($request);
        }
        if (in_array($controller, [
            \App\Http\Controllers\ClientTypeController::class,
            \App\Http\Controllers\ClientNeedTypeController::class,
            \App\Http\Controllers\ClientNeedStatusController::class,
        ], true)) {
            abort_unless($user?->hasPermission('clients.dictionaries.manage')
                || ($request->isMethodSafe() && $user?->hasPermission('catalogs.details.view')), 403, 'FORBIDDEN_ACTION');
            return $next($request);
        }

        // Migrated CRM endpoints enforce action permissions and record scope themselves.
        // A grant must work for any role, including a former client/accountant role.
        $crmScopes = [
            'leads' => ['api/leads', 'api/leads/*', 'api/crm/leads/*', 'api/crm/marketing-leads', 'api/crm/marketing-leads/*'],
            'deals' => ['api/deals', 'api/deals/*', 'api/crm/deals/*'],
            'pipelines' => ['api/deal-pipelines', 'api/deal-pipelines/*', 'api/deal-stages', 'api/deal-stages/*'],
        ];
        foreach ($crmScopes as $module => $paths) {
            if ($request->is(...$paths)) {
                foreach (['all', 'branch', 'own', 'hr', 'control'] as $scope) {
                    if ($user?->hasPermission($module.'.scope.'.$scope)) {
                        return $next($request);
                    }
                }
            }
        }

        if ($request->is('api/crm/reports/performance')) {
            foreach (['all', 'branch', 'own_operator', 'own_manager'] as $scope) {
                if ($user?->hasPermission('crm_reports.scope.'.$scope)) return $next($request);
            }
        }

        if (($request->is('api/clients/duplicate-check') && $user?->hasPermission('clients.duplicates.search'))
            || ($request->is('api/clients/attach-existing') && $user?->hasPermission('clients.attach'))) {
            return $next($request);
        }
        if ($request->is('api/clients/settings') && $user?->hasPermission('clients.settings.manage')) {
            return $next($request);
        }
        if ($request->is('api/clients', 'api/clients/*', 'api/client-needs/*')) {
            foreach (['all', 'branch', 'own', 'agent_policy', 'group_policy'] as $scope) {
                if ($user?->hasPermission('clients.scope.'.$scope)) {
                    return $next($request);
                }
            }
            if ($user?->hasPermission('clients.shared.view')) {
                return $next($request);
            }
        }

        if (($request->is('api/bookings') && $request->isMethod('POST') && $user?->hasPermission('bookings.create'))
            || ($request->is('api/bookings/agents-report') && $user?->hasPermission('bookings.reports.view'))
            || ($request->is('api/bookings/*') && in_array($request->method(), ['PUT', 'PATCH'], true)
                && ($user?->hasPermission('bookings.update.own') || $user?->hasPermission('bookings.update.all')))) {
            return $next($request);
        }

        if ($request->is('api/user') && $request->isMethod('GET') && $request->boolean('report_agents') && $user?->hasPermission('users.report_agents.view_all')) return $next($request);
        if ($request->is('api/user', 'api/user/*')) {
            foreach (['users.scope.all', 'users.scope.branch', 'users.clients.view', 'users.create', 'users.update', 'users.dismiss', 'users.restore'] as $permission) {
                if ($user?->hasPermission($permission)) return $next($request);
            }
        }

        if ($request->is('api/branch-groups', 'api/branch-groups/*')) {
            foreach (['scope.all', 'scope.branch', 'labels.list', 'labels.show', 'manage.all', 'manage.branch'] as $grant) {
                if ($user?->hasPermission('branch_groups.'.$grant)) return $next($request);
            }
        }

        if ($request->is('api/reels', 'api/reels/*') && $user?->hasPermission('reels.view')
            && ($user->hasPermission('reels.scope.all') || $user->hasPermission('reels.scope.branch'))) return $next($request);

        // Admission is explicit; controllers still enforce actions and record scope.
        // New internal routes must declare their entry permission here.
        return response()->json(['message' => 'Forbidden', 'code' => 'FORBIDDEN_ACTION'], 403);
    }

    private function propertyActionGrants(?string $action): array
    {
        [$controller, $method] = array_pad(explode('@', $action ?? '', 2), 2, '');
        if ($controller === \App\Http\Controllers\PropertyController::class) {
            return match ($method) {
                'myProperties' => ['properties.list.internal'],
                'store' => ['properties.create'],
                'update', 'destroy', 'duplicateCandidates', 'updateModerationAndListingType' => ['properties.edit'],
                'updateCoOwner' => ['properties.co_owner.manage'],
                'refreshListingDate' => ['properties.listing_date.refresh'],
                'logs' => ['properties.history.view'],
                'saveDeal' => ['properties.deal.manage'],
                'matchingClients' => ['properties.matching.view'],
                default => [],
            };
        }
        if ($controller === \App\Http\Controllers\PropertyControlReportController::class && in_array($method, ['summary', 'meta', 'export'], true)) {
            return ['properties.control_report.view'];
        }
        if ($controller === \App\Http\Controllers\PropertyContactAnalyticsController::class && $method === 'index') {
            return ['properties.analytics.view'];
        }
        if ($controller === \App\Http\Controllers\PropertyLiquidityController::class) {
            return match ($method) {
                'feed', 'report', 'history' => ['liquidity.view'],
                'updateBusinessPriority' => ['liquidity.priority.manage'],
                'updatePromotion' => ['liquidity.promotion.manage'],
                'recalculate' => ['liquidity.settings.manage'],
                default => [],
            };
        }
        if ($controller === \App\Http\Controllers\PropertyPhotoController::class) {
            return in_array($method, ['store', 'destroy', 'reorder'], true) ? ['properties.edit'] : [];
        }
        if ($controller === \App\Http\Controllers\PropertyModerationController::class) {
            return match ($method) {
                'submit', 'withdrawChanges', 'withdrawCase', 'withdrawCaseForProperty', 'withdrawListing', 'appeal', 'appealForProperty' => ['properties.edit'],
                'queue', 'promotionQueue', 'trustReport', 'approve', 'approveAll', 'reject', 'approveForProperty', 'rejectForProperty',
                'markDetectedDuplicate', 'decideDuplicate', 'mergeDuplicate', 'rejectDuplicate', 'resolveAppeal',
                'decideDuplicateForProperty', 'confirmDuplicateForProperty', 'dismissDuplicateForProperty', 'mergeDuplicateForProperty', 'rejectDuplicateForProperty', 'reopen' => ['properties.moderate'],
                'breakGlassApprove' => ['properties.break_glass'],
                'transfer' => ['properties.transfer'],
                'requestPromotion' => ['properties.promotions.request'],
                'setPromotion', 'approvePromotion', 'rejectPromotion', 'approvePromotionForProperty', 'rejectPromotionForProperty' => ['properties.promotions.manage'],
                'revokePromotion', 'revokePromotionForProperty' => ['properties.promotions.request', 'properties.promotions.manage'],
                default => [],
            };
        }
        return [];
    }
}

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

        if ($request->is('api/roles', 'api/roles/*') && $user?->hasPermission('roles.manage')) {
            return $next($request);
        }

        // Migrated CRM endpoints enforce action permissions and record scope themselves.
        // A grant must work for any role, including a former client/accountant role.
        $crmScopes = [
            'leads' => ['api/leads', 'api/leads/*', 'api/crm/leads/*'],
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

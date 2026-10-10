<?php

namespace App\Services;

use App\Models\Role;

final class KpiRoleCatalog
{
    /** Defaults also cover installations where an unused system role has not been seeded. */
    public function templates(): array
    {
        $roles = Role::query()->get()->keyBy('slug');
        foreach ((config('permissions', [])['kpi.subject.plan_templates']['roles'] ?? []) as $slug) {
            if (! $roles->has($slug)) $roles->put($slug, new Role(['slug' => $slug, 'name' => match ($slug) {
                'agent' => 'Агент', 'intern' => 'Стажёр', 'mop' => 'МОП', 'rop' => 'РОП', default => $slug,
            }]));
        }
        return $roles->filter(fn (Role $role) => $role->hasPermission('kpi.subject.plan_templates'))
            ->map(fn (Role $role) => ['slug' => $role->slug, 'name' => $role->name,
                'group_templates' => $role->hasPermission('kpi.subject.group_templates')])->values()->all();
    }

    public function reportPresentation(\App\Models\User $actor): array
    {
        $selected = function (string $view) use ($actor): ?string {
            $prefix = 'kpi.reports.'.$view.'.role.';
            foreach ($actor->role?->permissionKeys() ?? [] as $key) {
                if (str_starts_with($key, $prefix) && $actor->hasPermission($key)) return substr($key, strlen($prefix));
            }
            return null;
        };
        $visibleRoles = null;
        if ($actor->hasPermission('kpi.reports.site.restrict_rows')) {
            $roles = Role::query()->get()->keyBy('slug');
            foreach ((config('permissions', [])['kpi.subject.site_rows']['roles'] ?? []) as $slug) {
                if (! $roles->has($slug)) $roles->put($slug, new Role(['slug' => $slug]));
            }
            $visibleRoles = $roles->filter(fn (Role $role) => $role->hasPermission('kpi.subject.site_rows'))->keys()->values()->all();
        }
        return ['dashboard_role' => $selected('dashboard'), 'visible_roles' => $visibleRoles];
    }

    public function templateSlugs(): array
    {
        return array_column($this->templates(), 'slug');
    }

    public function allowsGroupTemplates(string $slug): bool
    {
        $role = Role::where('slug', $slug)->first() ?? new Role(['slug' => $slug]);
        return $role->hasPermission('kpi.subject.group_templates');
    }
}

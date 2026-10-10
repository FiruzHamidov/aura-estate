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

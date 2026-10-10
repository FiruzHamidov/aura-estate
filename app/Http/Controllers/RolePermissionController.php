<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Services\Rbac\PermissionCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RolePermissionController extends Controller
{
    public function index(Request $request, PermissionCatalog $catalog)
    {
        $this->authorizeManager($request);

        return response()->json([
            'permissions' => $catalog->publicDefinitions(),
            'roles' => Role::withCount('users')->orderBy('id')->get()->map(fn (Role $role) => $this->serialize($role)),
        ]);
    }

    public function update(Request $request, Role $role, PermissionCatalog $catalog)
    {
        $this->authorizeManager($request);
        $definitions = $catalog->definitions();
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'permissions' => ['present', 'array', 'max:1000'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::in(array_keys($definitions))],
        ]);

        $exclusive = [];
        foreach ($data['permissions'] as $permission) {
            $group = $definitions[$permission]['exclusive_group'] ?? null;
            if ($group !== null && isset($exclusive[$group])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'permissions' => ['Для одного раздела можно выбрать только одну область данных.'],
                ]);
            }
            if ($group !== null) $exclusive[$group] = true;
        }

        $result = DB::transaction(function () use ($request, $role, $data) {
            // A single lock order serializes permission editors, including edits to their own role.
            $roles = Role::orderBy('id')->lockForUpdate()->get();
            $actor = $request->user()->fresh();
            abort_unless($actor && $roles->firstWhere('id', $actor->role_id)?->hasPermission('roles.manage'), 403);
            $target = $roles->firstWhere('id', $role->id);
            abort_unless($target, 404);
            abort_unless((int) $target->permissions_version === $data['version'], 409, 'Права уже изменены другим пользователем. Обновите страницу.');

            $before = $target->permissionKeys();
            $after = array_values($data['permissions']);
            sort($before);
            sort($after);
            if ($before === $after) return $this->serialize($target->loadCount('users'));

            // Self lock-out is rejected even if another administrator exists.
            abort_if($actor->role_id === $target->id && ! in_array('roles.manage', $after, true), 422, 'Нельзя отключить управление правами у собственной роли.');
            $target->permissions = $after;
            $target->permissions_version++;
            $target->save();
            DB::table('role_permission_audits')->insert([
                'role_id' => $target->id, 'role_name' => $target->name, 'actor_id' => $actor->id,
                'before' => json_encode($before, JSON_THROW_ON_ERROR),
                'after' => json_encode($after, JSON_THROW_ON_ERROR),
                'version' => $target->permissions_version, 'created_at' => now(),
            ]);

            return $this->serialize($target->loadCount('users'));
        });

        return response()->json($result);
    }

    public function history(Request $request, Role $role)
    {
        $this->authorizeManager($request);
        $history = DB::table('role_permission_audits as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
            ->where('a.role_id', $role->id)->select('a.*', 'u.name as actor_name')->orderByDesc('a.id')->paginate(25);
        $history->through(function ($row) {
            $row->created_at = \Carbon\Carbon::parse($row->created_at)->toIso8601String();
            $row->before = json_decode($row->before, true);
            $row->after = json_decode($row->after, true);
            return $row;
        });

        return response()->json($history);
    }

    private function authorizeManager(Request $request): void
    {
        abort_unless($request->user()?->hasPermission('roles.manage'), 403, 'Недостаточно прав для управления доступом.');
    }

    private function serialize(Role $role): array
    {
        return [...$role->toArray(), 'permissions' => array_values(array_filter($role->permissionKeys(), fn (string $key) => $role->hasPermission($key))), 'system' => in_array($role->slug, array_column(Role::systemRoles(), 'slug'), true)];
    }
}

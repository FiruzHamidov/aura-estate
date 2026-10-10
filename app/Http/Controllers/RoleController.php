<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    // GET /roles — список ролей
    public function index(Request $request)
    {
        // При желании можно добавить фильтры ?q=...
        $query = Role::query()->withCount('users');

        if ($search = trim($request->get('q', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Для селекта — отдать все. Если нужна пагинация, раскомментируйте ниже.
        $roles = $query->orderBy('id', 'asc')->get();
        // $roles = $query->orderBy('id', 'desc')->paginate($request->integer('per_page', 20));

        return response()->json($roles);
    }

    // POST /roles — создание
    public function store(Request $request)
    {
        abort_unless($request->user()?->hasPermission('roles.manage'), 403);
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'slug'        => ['required', 'regex:/^[a-z0-9_-]+$/', 'max:255', 'unique:roles,slug', Rule::notIn([...array_column(Role::systemRoles(), 'slug'), 'owner'])],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $role = Role::create($validated);

        return response()->json($role, 201);
    }

    // GET /roles/{role} — просмотр
    public function show(Role $role)
    {
        return response()->json($role);
    }

    // PUT /roles/{role} — обновление
    public function update(Request $request, Role $role)
    {
        abort_unless($request->user()?->hasPermission('roles.manage'), 403);
        $validated = $request->validate([
            'name'        => ['sometimes', 'required', 'string', 'max:255'],
            'slug'        => [
                'sometimes', 'required', 'alpha_dash', 'max:255',
                Rule::in([$role->slug]),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $role->update($validated);

        return response()->json($role);
    }

    // DELETE /roles/{role} — удаление
    public function destroy(Request $request, Role $role)
    {
        abort_unless($request->user()?->hasPermission('roles.manage'), 403);
        abort_if(in_array($role->slug, [...array_column(Role::systemRoles(), 'slug'), 'owner'], true), 409, 'Системную роль нельзя удалить.');
        \Illuminate\Support\Facades\DB::transaction(function () use ($role, $request) {
            $roles = Role::orderBy('id')->lockForUpdate()->get();
            $actor = $request->user()->fresh();
            abort_unless($actor && $roles->firstWhere('id', $actor->role_id)?->hasPermission('roles.manage'), 403);
            $locked = $roles->firstWhere('id', $role->id);
            abort_unless($locked, 404);
            abort_if($locked->users()->exists(), 409, 'Нельзя удалить роль: к ней привязаны пользователи');
            $assignmentKey = 'users.assign_role.'.$locked->slug;
            foreach ($roles as $affected) {
                if ($affected->id === $locked->id || !in_array($assignmentKey, $affected->permissions ?? [], true)) continue;
                $before = $affected->permissions;
                $after = array_values(array_diff($before, [$assignmentKey]));
                $affected->permissions = $after;
                $affected->permissions_version++;
                $affected->save();
                \Illuminate\Support\Facades\DB::table('role_permission_audits')->insert([
                    'role_id' => $affected->id, 'role_name' => $affected->name, 'actor_id' => $request->user()->id,
                    'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($after, JSON_THROW_ON_ERROR),
                    'version' => $affected->permissions_version, 'created_at' => now(),
                ]);
            }
            $locked->delete();
        });

        return response()->json(['message' => 'Role deleted']);
    }
}

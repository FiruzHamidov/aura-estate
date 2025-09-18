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
        $query = Role::query();

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
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'slug'        => ['required', 'alpha_dash', 'max:255', 'unique:roles,slug'],
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
        $validated = $request->validate([
            'name'        => ['sometimes', 'required', 'string', 'max:255'],
            'slug'        => [
                'sometimes', 'required', 'alpha_dash', 'max:255',
                Rule::unique('roles', 'slug')->ignore($role->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $role->update($validated);

        return response()->json($role);
    }

    // DELETE /roles/{role} — удаление
    public function destroy(Role $role)
    {
        // Защитимся от удаления роли, если к ней привязаны пользователи
        if ($role->users()->exists()) {
            return response()->json([
                'message' => 'Нельзя удалить роль: к ней привязаны пользователи',
            ], 409);
        }

        $role->delete();

        return response()->json(['message' => 'Role deleted']);
    }
}

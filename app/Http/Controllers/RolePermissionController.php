<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Services\Rbac\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            'permission_expirations' => ['sometimes', 'array', 'max:1000'],
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

            $before = $target->configuredPermissionKeys();
            $after = array_values($data['permissions']);
            $beforeExpirations = $target->permission_expirations ?? [];
            $afterExpirations = $this->expirations($data, $after, $beforeExpirations);
            sort($before);
            sort($after);
            ksort($beforeExpirations);
            ksort($afterExpirations);
            if ($before === $after && $beforeExpirations === $afterExpirations) return $this->serialize($target->loadCount('users'));

            // Self lock-out is rejected even if another administrator exists.
            abort_if($actor->role_id === $target->id && ! in_array('roles.manage', $after, true), 422, 'Нельзя отключить управление правами у собственной роли.');
            $ownExpiry = $afterExpirations['roles.manage'] ?? null;
            $previousExpiry = $beforeExpirations['roles.manage'] ?? null;
            abort_if($actor->role_id === $target->id && $ownExpiry !== null
                && ($previousExpiry === null || $ownExpiry < $previousExpiry),
                422, 'Нельзя ограничить срок управления правами у собственной роли.');
            $target->permissions = $after;
            $supportsExpirations = Schema::hasColumn('roles', 'permission_expirations');
            if ($supportsExpirations) $target->permission_expirations = (object) $afterExpirations;
            else abort_if($afterExpirations !== [], 422, 'Сроки доступа пока недоступны. Примените обновление базы данных.');
            $target->permissions_version++;
            $target->save();
            DB::table('role_permission_audits')->insert([
                'role_id' => $target->id, 'role_name' => $target->name, 'actor_id' => $actor->id,
                'before' => json_encode($before, JSON_THROW_ON_ERROR),
                'after' => json_encode($after, JSON_THROW_ON_ERROR),
                ...($supportsExpirations ? [
                    'before_expirations' => json_encode((object) $beforeExpirations, JSON_THROW_ON_ERROR),
                    'after_expirations' => json_encode((object) $afterExpirations, JSON_THROW_ON_ERROR),
                ] : []),
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
            $row->before_expirations = (object) json_decode($row->before_expirations ?? '{}', true);
            $row->after_expirations = (object) json_decode($row->after_expirations ?? '{}', true);
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
        $registered = array_keys(app(PermissionCatalog::class)->definitions());
        return [...$role->toArray(),
            // Keep expired grants in the editor so administrators can inspect or renew them.
            'permissions' => array_values(array_intersect($role->configuredPermissionKeys(), $registered)),
            'permission_expirations' => (object) ($role->permission_expirations ?? []),
            'system' => in_array($role->slug, array_column(Role::systemRoles(), 'slug'), true)];
    }

    private function expirations(array $data, array $permissions, array $before): array
    {
        // Older clients must not silently turn temporary grants into permanent grants.
        $input = $data['permission_expirations'] ?? array_intersect_key($before, array_flip($permissions));
        $result = [];
        foreach ($input as $permission => $value) {
            if (! in_array($permission, $permissions, true)) {
                throw ValidationException::withMessages(['permission_expirations' => ['Срок можно задать только для выбранного разрешения.']]);
            }
            $valid = Validator::make(['expiry' => $value], [
                'expiry' => ['required', 'string', 'date', 'max:40', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/'],
            ]);
            if ($valid->fails()) {
                throw ValidationException::withMessages(['permission_expirations' => ['Укажите корректные дату, время и часовой пояс окончания доступа.']]);
            }
            $date = CarbonImmutable::parse($value)->utc();
            $normalized = $date->format('Y-m-d\TH:i:s\Z');
            if (! CarbonImmutable::parse($normalized)->isFuture() && ($before[$permission] ?? null) !== $normalized) {
                throw ValidationException::withMessages(['permission_expirations' => ['Новый срок доступа должен быть в будущем.']]);
            }
            $result[$permission] = $normalized;
        }
        return $result;
    }
}

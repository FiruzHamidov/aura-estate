<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    // Список всех пользователей
    public function index()
    {
        $users = User::with('role')->get();
        return response()->json($users);
    }

    // Создание пользователя
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'description' => 'nullable|string',
            'birthday' => 'nullable|date',
            'phone' => 'required|string|unique:users,phone',
            'email' => 'nullable|email|unique:users,email',
            'role_id' => 'required|exists:roles,id',
            'auth_method' => 'required|in:password,sms',
            'password' => 'nullable|min:6|required_if:auth_method,password'
        ]);

        $data = $request->only(['name', 'phone', 'email', 'role_id', 'auth_method', 'status', 'birthday']);

        if ($request->auth_method === 'password') {
            $data['password'] = Hash::make($request->password);
        }

        $user = User::create($data);

        return response()->json($user, 201);
    }

    // Просмотр конкретного пользователя
    public function show(User $user)
    {
        return response()->json($user->load('role'));
    }

    // Обновление пользователя
    public function update(Request $request, User $user)
    {
        $request->validate([
            'name' => 'sometimes|string',
            'description' => 'nullable|string',
            'birthday' => 'nullable|date',
            'phone' => 'sometimes|string|unique:users,phone,' . $user->id,
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'role_id' => 'sometimes|exists:roles,id',
            'auth_method' => 'sometimes|in:password,sms',
            'password' => 'nullable|min:6|required_if:auth_method,password'
        ]);

        $data = $request->only(['name', 'phone', 'email', 'role_id', 'auth_method', 'status', 'description', 'birthday']);

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $filename = 'users/' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = \Storage::disk('public')->put($filename, file_get_contents($file));
            $data['photo'] = $filename;
        }

        $user->update($data);

        return response()->json($user->fresh(['role']));
    }

    public function updatePhoto(Request $request, User $user)
    {
        $request->validate([
            'photo' => 'required|image|max:2048'
        ]);

        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $filename = 'users/' . uniqid() . '.' . $file->getClientOriginalExtension();
            \Storage::disk('public')->put($filename, file_get_contents($file));
            $user->update(['photo' => $filename]);
        }

        return response()->json(['message' => 'Photo updated', 'photo' => $user->photo]);
    }

    public function deleteMyPhoto()
    {
        $user = Auth::user();

        if ($user->photo && \Storage::disk('public')->exists($user->photo)) {
            \Storage::disk('public')->delete($user->photo);
        }

        $user->update(['photo' => null]);

        return response()->json(['message' => 'Ваша фотография была удалена']);
    }

    public function agents()
    {
        $agents = User::with('role')->whereHas('role', function ($q) {
            $q->where('slug', 'agent');
        })->get();

        return response()->json($agents);
    }

    // Удаление пользователя и перераспределения
    public function destroy(Request $request, User $user)
    {
        // Валидация входных параметров
        $request->validate([
            'distribute_to_agents' => 'nullable|boolean',
            'agent_id' => 'nullable|integer|exists:users,id',
        ]);

        $distribute = (bool) $request->boolean('distribute_to_agents');
        $agentId = $request->input('agent_id');

        // Нормы: либо distribute_to_agents=true, либо agent_id обязателен
        if (!$distribute && !$agentId) {
            return response()->json([
                'message' => 'Укажите distribute_to_agents=true для авто-распределения ИЛИ передайте agent_id.',
            ], 422);
        }

        if ($agentId && (int)$agentId === (int)$user->id) {
            return response()->json([
                'message' => 'Нельзя передать объекты самому удаляемому пользователю.',
            ], 422);
        }

        // Проверка, что целевой получатель — агент (если указан)
        if ($agentId) {
            $target = User::with('role')->find($agentId);
            if (!$target || !$target->role || $target->role->slug !== 'agent') {
                return response()->json([
                    'message' => 'agent_id должен указывать на пользователя с ролью агент.',
                ], 422);
            }
        }

        DB::transaction(function () use ($user, $distribute, $agentId) {
            // Блокируем набор properties пользователя на время операции
            $props = Property::where('user_id', $user->id)
                ->lockForUpdate()
                ->get(['id', 'user_id']);

            if ($props->isNotEmpty()) {
                if ($distribute) {
                    // Соберём список доступных агентов (кроме удаляемого)
                    $agentIds = User::whereHas('role', fn($q) => $q->where('slug', 'agent'))
                        ->where('id', '!=', $user->id)
                        ->pluck('id')
                        ->all();

                    if (empty($agentIds)) {
                        // Нет агентов — нельзя распределить
                        throw new \RuntimeException('Нет доступных агентов для авто-распределения.');
                    }

                    // Равномерно распределяем (round-robin)
                    $countAgents = count($agentIds);
                    $i = 0;
                    foreach ($props as $p) {
                        $newAgentId = $agentIds[$i % $countAgents];
                        Property::whereKey($p->id)->update(['agent_id' => $newAgentId, 'created_by' => $newAgentId]);
                        $i++;
                    }
                } else {
                    // Передаём все объекты одному агенту
                    Property::where('user_id', $user->id)->update(['agent_id' => $agentId, 'created_by' => $agentId]);
                }
            }

            // Удаляем пользователя
            $user->delete();
        });

        return response()->json(['message' => 'Пользователь удалён и объекты перераспределены.']);
    }

    public function updatePassword(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'current_password' => 'required',
            'new_password'     => 'required|string|min:6|confirmed',
        ]);

        // Проверка текущего пароля
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Текущий пароль введён неверно'
            ], 422);
        }

        // Обновление
        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json(['message' => 'Пароль успешно обновлён']);
    }
}

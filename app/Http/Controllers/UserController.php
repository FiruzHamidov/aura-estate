<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
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
            'description' => 'sometimes|string',
            'birthday' => 'nullable',
            'phone' => 'required|string|unique:users,phone',
            'email' => 'nullable|email|unique:users,email',
            'role_id' => 'required|exists:roles,id',
            'auth_method' => 'required|in:password,sms',
            'password' => 'nullable|min:6|required_if:auth_method,password'
        ]);

        $data = $request->only(['name', 'phone', 'email', 'role_id', 'auth_method', 'status']);

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
            'description' => 'sometimes|string',
            'birthday' => 'nullable',
            'phone' => 'sometimes|string|unique:users,phone,' . $user->id,
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'role_id' => 'sometimes|exists:roles,id',
            'auth_method' => 'sometimes|in:password,sms',
            'password' => 'nullable|min:6|required_if:auth_method,password'
        ]);

        $data = $request->only(['name', 'phone', 'email', 'role_id', 'auth_method', 'status', 'description']);

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

        return response()->json($user);
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

        return response()->json(['message' => 'Your photo has been deleted']);
    }

    public function agents()
    {
        $agents = User::with('role')->whereHas('role', function ($q) {
            $q->where('slug', 'agent');
        })->get();

        return response()->json($agents);
    }

    // Удаление пользователя
    public function destroy(User $user)
    {
        $user->delete();
        return response()->json(['message' => 'User deleted']);
    }
}

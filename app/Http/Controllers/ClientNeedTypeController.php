<?php

namespace App\Http\Controllers;

use App\Models\ClientNeedType;
use App\Models\User;
use Illuminate\Http\Request;

class ClientNeedTypeController extends Controller
{
    private function authUser(Request $request): ?User
    {
        /** @var User|null $user */
        $user = $request->user();
        $user?->loadMissing('role');

        return $user;
    }

    private function ensurePrivileged(Request $request): void
    {
        $user = $this->authUser($request);
        abort_unless($user && in_array($user->role?->slug, ['admin', 'superadmin', 'marketing'], true), 403, 'Forbidden');
    }

    public function index()
    {
        return response()->json(
            ClientNeedType::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
        );
    }

    public function show(ClientNeedType $clientNeedType)
    {
        return response()->json($clientNeedType);
    }

    public function store(Request $request)
    {
        $this->ensurePrivileged($request);

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:client_need_types,name',
            'slug' => 'required|string|max:255|unique:client_need_types,slug',
            'sort_order' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        $type = ClientNeedType::create($validated);

        return response()->json($type, 201);
    }

    public function update(Request $request, ClientNeedType $clientNeedType)
    {
        $this->ensurePrivileged($request);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|unique:client_need_types,name,' . $clientNeedType->id,
            'slug' => 'sometimes|string|max:255|unique:client_need_types,slug,' . $clientNeedType->id,
            'sort_order' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        $clientNeedType->update($validated);

        return response()->json($clientNeedType);
    }

    public function destroy(Request $request, ClientNeedType $clientNeedType)
    {
        $this->ensurePrivileged($request);

        $clientNeedType->delete();

        return response()->json(['message' => 'Удалено']);
    }
}

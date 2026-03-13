<?php

namespace App\Http\Controllers;

use App\Models\ClientType;
use App\Models\User;
use Illuminate\Http\Request;

class ClientTypeController extends Controller
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
            ClientType::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
        );
    }

    public function show(ClientType $clientType)
    {
        return response()->json($clientType);
    }

    public function store(Request $request)
    {
        $this->ensurePrivileged($request);

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:client_types,name',
            'slug' => 'required|string|max:255|unique:client_types,slug',
            'is_business' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        $type = ClientType::create($validated);

        return response()->json($type, 201);
    }

    public function update(Request $request, ClientType $clientType)
    {
        $this->ensurePrivileged($request);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|unique:client_types,name,' . $clientType->id,
            'slug' => 'sometimes|string|max:255|unique:client_types,slug,' . $clientType->id,
            'is_business' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        $clientType->update($validated);

        return response()->json($clientType);
    }

    public function destroy(Request $request, ClientType $clientType)
    {
        $this->ensurePrivileged($request);

        $clientType->delete();

        return response()->json(['message' => 'Удалено']);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\ClientNeedStatus;
use App\Models\User;
use Illuminate\Http\Request;

class ClientNeedStatusController extends Controller
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
        abort_unless($user && in_array($user->role?->slug, ['admin', 'superadmin'], true), 403, 'Forbidden');
    }

    public function index()
    {
        return response()->json(
            ClientNeedStatus::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
        );
    }

    public function show(ClientNeedStatus $clientNeedStatus)
    {
        return response()->json($clientNeedStatus);
    }

    public function store(Request $request)
    {
        $this->ensurePrivileged($request);

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:client_need_statuses,name',
            'slug' => 'required|string|max:255|unique:client_need_statuses,slug',
            'is_closed' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        $status = ClientNeedStatus::create($validated);

        return response()->json($status, 201);
    }

    public function update(Request $request, ClientNeedStatus $clientNeedStatus)
    {
        $this->ensurePrivileged($request);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|unique:client_need_statuses,name,' . $clientNeedStatus->id,
            'slug' => 'sometimes|string|max:255|unique:client_need_statuses,slug,' . $clientNeedStatus->id,
            'is_closed' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        $clientNeedStatus->update($validated);

        return response()->json($clientNeedStatus);
    }

    public function destroy(Request $request, ClientNeedStatus $clientNeedStatus)
    {
        $this->ensurePrivileged($request);

        $clientNeedStatus->delete();

        return response()->json(['message' => 'Удалено']);
    }
}

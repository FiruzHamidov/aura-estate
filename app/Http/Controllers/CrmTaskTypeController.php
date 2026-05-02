<?php

namespace App\Http\Controllers;

use App\Models\CrmTaskType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CrmTaskTypeController extends Controller
{
    public function index(Request $request)
    {
        $this->ensureInternalRole($this->authUser());

        $validated = $request->validate([
            'group' => 'nullable|string|max:64',
            'is_kpi' => 'nullable|boolean',
        ]);

        $query = CrmTaskType::query()->orderBy('group')->orderBy('name');

        if (array_key_exists('group', $validated)) {
            $query->where('group', $validated['group']);
        }

        if (array_key_exists('is_kpi', $validated)) {
            $query->where('is_kpi', (bool) $validated['is_kpi']);
        }

        return response()->json($query->get());
    }

    private function authUser(): User
    {
        /** @var User $user */
        $user = Auth::user();
        abort_unless($user, 401, 'Unauthenticated.');
        $user->loadMissing('role');

        return $user;
    }

    private function ensureInternalRole(User $user): void
    {
        abort_unless(in_array($user->role?->slug, ['admin', 'superadmin', 'rop', 'branch_director', 'mop', 'agent'], true), 403, 'Forbidden');
    }
}

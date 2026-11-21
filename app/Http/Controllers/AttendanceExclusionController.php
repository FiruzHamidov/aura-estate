<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Attendance\AttendanceAuditService;
use App\Services\Attendance\AttendancePrivacy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AttendanceExclusionController extends Controller
{
    public function __construct(private readonly AttendancePrivacy $privacy) {}

    private function authorizeManager(Request $request): void
    {
        abort_unless($this->privacy->isSuperadmin($request->user()), 403,
            'Только суперадминистратор может управлять исключениями посещаемости.');
    }

    public function index(Request $request)
    {
        $this->authorizeManager($request);
        $data = $request->validate([
            'mode' => ['sometimes', 'in:excluded,available'],
            'search' => ['nullable', 'string', 'max:150'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $excluded = ($data['mode'] ?? 'excluded') === 'excluded';
        $query = User::query()->select(['id', 'name', 'phone', 'role_id', 'branch_id', 'status', AttendancePrivacy::FIELD, AttendancePrivacy::HIDDEN_FROM_EVERYONE])
            ->with(['role:id,name,slug', 'branch:id,name']);
        if ($excluded) {
            $query->where(fn ($users) => $users->where(AttendancePrivacy::FIELD, true)
                ->orWhere(AttendancePrivacy::HIDDEN_FROM_EVERYONE, true));
        } else {
            $query->where(AttendancePrivacy::FIELD, false)->where(AttendancePrivacy::HIDDEN_FROM_EVERYONE, false);
        }
        // Keep existing exceptions removable even after dismissal or a role change.
        if (! $excluded) {
            $query->where(fn ($users) => $users->where('status', User::STATUS_ACTIVE)->orWhereNull('status'))
                ->whereHas('role', fn ($role) => $role->withPermission('attendance.subject.exclude_table', false));
        }
        if (($search = trim($data['search'] ?? '')) !== '') {
            $query->where(function ($users) use ($search) {
                $users->where('name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%');
                if (ctype_digit($search)) $users->orWhere('id', (int) $search);
            });
        }
        $page = $query->orderBy('name')->orderBy('id')->paginate($data['per_page'] ?? 20);
        $page->getCollection()->each(fn (User $user) => $this->privacy->exposeSetting($user, $request->user()));

        return response()->json($page);
    }

    public function update(Request $request, User $user)
    {
        $this->authorizeManager($request);
        $request->validate([
            AttendancePrivacy::FIELD => ['required_without:'.AttendancePrivacy::HIDDEN_FROM_EVERYONE, 'boolean'],
            AttendancePrivacy::HIDDEN_FROM_EVERYONE => ['required_without:'.AttendancePrivacy::FIELD, 'boolean'],
        ]);
        $values = $this->privacy->settingsForMutation($request, $request->user());
        DB::transaction(function () use ($request, $user, $values) {
            $target = User::query()->lockForUpdate()->findOrFail($user->id);
            $old = $target->only(array_keys($values));
            $target->fill($values);
            if ($target->isDirty(array_keys($values))) {
                $target->save();
                app(AttendanceAuditService::class)->record($request->user(), 'attendance.privacy.updated', $target, $old, $values, $request);
            }
        });

        return response()->json(['id' => $user->id, ...$values]);
    }
}

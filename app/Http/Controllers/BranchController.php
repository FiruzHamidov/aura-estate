<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index(Request $request)
    {
        $query = Branch::query();
        if ($request->boolean('include_hidden')) {
            $actor = $request->user() ?? auth('sanctum')->user();
            abort_unless($actor?->hasPermission('catalogs.entries.branches.manage'), 403, 'FORBIDDEN_ACTION');
            $query->withoutGlobalScope('visible');
        }
        return response()->json($query->orderBy('id')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'landmark' => 'nullable|string|max:255',
            'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:8192',
        ]);

        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $filename = 'branches/' . uniqid('', true) . '.' . $file->getClientOriginalExtension();
            \Storage::disk('public')->put($filename, file_get_contents($file));
            $validated['photo'] = $filename;
        }

        $branch = Branch::create($validated);

        return response()->json($branch, 201);
    }

    public function show(Branch $branch)
    {
        return response()->json($branch);
    }

    public function update(Request $request, Branch $branch)
    {
        $validated = $request->validate([
            'is_hidden' => 'sometimes|boolean',
            'name' => 'sometimes|required|string|max:255',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'landmark' => 'nullable|string|max:255',
            'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:8192',
        ]);

        if ($request->hasFile('photo')) {
            if ($branch->photo && \Storage::disk('public')->exists($branch->photo)) {
                \Storage::disk('public')->delete($branch->photo);
            }

            $file = $request->file('photo');
            $filename = 'branches/' . uniqid('', true) . '.' . $file->getClientOriginalExtension();
            \Storage::disk('public')->put($filename, file_get_contents($file));
            $validated['photo'] = $filename;
        }

        $branch->update($validated);

        return response()->json($branch);
    }

    public function destroy(Branch $branch)
    {
        return \DB::transaction(function () use ($branch) {
            $branch = Branch::withoutGlobalScope('visible')->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            if ($branch->users()->exists()) {
                return response()->json([
                    'message' => 'Нельзя удалить филиал: к нему привязаны пользователи',
                ], 409);
            }

            foreach (\App\Models\BranchGroup::withoutGlobalScope('active')->where('branch_id', $branch->id)->orderBy('id')->lockForUpdate()->get() as $group) {
                abort_if($group->hasAssignedData(true) || (\Schema::hasTable('rop_branch_groups') && $group->hasAssignedRops(true)), 409, 'GROUP_HAS_ASSIGNED_DATA');
            }

            $branch->delete();

            return response()->json(['message' => 'Филиал удален']);
        });
    }
}

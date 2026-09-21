<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\GroupAccess\RopGroupAssignments;
use App\Support\RopGroupAccess;
use Illuminate\Http\Request;

final class RopGroupAccessController extends Controller
{
    public function show(Request $request, int $ropId, RopGroupAssignments $assignments)
    {
        $rop = User::query()->findOrFail($ropId);
        $assignments->authorize($request->user(), $rop, false);

        return response()->json($assignments->payload($rop));
    }

    public function update(Request $request, int $ropId, RopGroupAssignments $assignments)
    {
        $assignments->authorize($request->user(), User::query()->findOrFail($ropId), true);
        $data = $request->validate([
            'branch_group_ids' => ['present', 'array', 'max:500'],
            'branch_group_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'version' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json($assignments->replace($request->user(), $ropId, $data['branch_group_ids'], $data['version'], $data['reason'] ?? null));
    }

    public function me(Request $request, RopGroupAccess $access)
    {
        $actor = $request->user()->fresh('role');

        return response()->json($access->describe($actor));
    }
}

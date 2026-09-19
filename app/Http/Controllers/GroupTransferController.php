<?php

namespace App\Http\Controllers;

use App\Services\GroupAccess\GroupRecordTransfer;
use Illuminate\Http\Request;

final class GroupTransferController extends Controller
{
    public function preview(Request $request, string $type, int $id, GroupRecordTransfer $transfers)
    {
        return response()->json($transfers->preview($request->user(), $type, $id));
    }

    public function store(Request $request, string $type, int $id, GroupRecordTransfer $transfers)
    {
        $data = $request->validate([
            'branch_group_id' => ['required', 'integer', 'min:1'],
            'responsible_user_id' => ['required', 'integer', 'min:1'],
            'revision' => ['required', 'string', 'size:64'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'pipeline_id' => ['sometimes', 'integer', 'min:1'],
            'stage_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        return response()->json(['data' => $transfers->transfer($request->user(), $type, $id, $data)]);
    }
}

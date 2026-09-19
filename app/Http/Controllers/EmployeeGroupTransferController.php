<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\GroupAccess\EmployeeGroupTransfer;
use App\Services\GroupAccess\GroupRecordTransfer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class EmployeeGroupTransferController extends Controller
{
    public function preview(Request $request, User $user, EmployeeGroupTransfer $transfers)
    {
        return response()->json($transfers->preview($request->user(), $user));
    }

    public function store(Request $request, User $user, EmployeeGroupTransfer $transfers)
    {
        $data = $request->validate([
            'branch_group_id' => ['required', 'integer', 'min:1'],
            'revision' => ['required', 'string', 'size:64'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'records' => ['present', 'array'],
            'records.*.type' => ['required', Rule::in(array_keys(GroupRecordTransfer::MODELS))],
            'records.*.id' => ['required', 'integer', 'min:1'],
            'records.*.action' => ['required', Rule::in(['move', 'retain'])],
            'records.*.responsible_user_id' => ['nullable', 'integer', 'min:1'],
            'records.*.pipeline_id' => ['sometimes', 'integer', 'min:1'],
            'records.*.stage_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        return response()->json($transfers->transfer($request->user(), $user, $data));
    }
}

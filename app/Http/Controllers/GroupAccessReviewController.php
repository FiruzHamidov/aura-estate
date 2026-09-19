<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class GroupAccessReviewController extends Controller
{
    public function index(Request $request)
    {
        $actor = $request->user();
        $role = $actor?->role?->slug;
        abort_unless(in_array($role, ['superadmin', 'admin', 'branch_director'], true), 403);
        $query = DB::table('group_access_review_items')->whereNull('resolved_at');
        if ($role === 'branch_director') {
            abort_unless($actor->branch_id, 403);
            $query->where('branch_id', $actor->branch_id);
        }
        return response()->json($query->orderBy('id')->paginate(50));
    }
}

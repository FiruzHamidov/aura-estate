<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class GroupAccessReviewController extends Controller
{
    public function index(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor && !$actor->hasRole('rop') && ($actor->hasPermission('group_access_review.scope.all') || $actor->hasPermission('group_access_review.scope.branch')), 403);
        $query = DB::table('group_access_review_items')->whereNull('resolved_at');
        if (!$actor->hasPermission('group_access_review.scope.all')) {
            abort_unless($actor->branch_id, 403);
            $query->where('branch_id', $actor->branch_id);
        }
        return response()->json($query->orderBy('id')->paginate(50));
    }
}

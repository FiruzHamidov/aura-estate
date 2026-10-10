<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Services\PropertyModeration\PropertyModerationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PropertyContactAnalyticsController extends Controller
{
    public function track(Request $request, Property $property, PropertyModerationService $moderation)
    {
        $data = $request->validate(['action' => ['required', Rule::in(['phone', 'chat', 'whatsapp'])]]);
        $moderation->publicOrFail($property, $request->user() ?? $request->user('sanctum'));
        // Atomic updates preserve concurrent clicks. No visitor identifiers are stored.
        DB::table('properties')->where('id', $property->id)->increment($data['action'].'_clicks');
        return response()->noContent();
    }

    public function index(Request $request)
    {
        abort_unless(in_array($request->user()?->role?->slug, ['marketing', 'admin', 'superadmin'], true), 403);
        $data = $request->validate([
            'sort' => ['sometimes', Rule::in(['views_count', 'phone_clicks', 'chat_clicks', 'whatsapp_clicks', 'total_clicks'])],
            'search' => ['nullable', 'string', 'max:150'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = DB::table('properties')->select(['id', 'title', 'address', 'moderation_status', 'views_count', 'phone_clicks', 'chat_clicks', 'whatsapp_clicks'])
            ->selectRaw('(phone_clicks + chat_clicks + whatsapp_clicks) as total_clicks');
        if ($search = trim($data['search'] ?? '')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', '%'.$search.'%')->orWhere('address', 'like', '%'.$search.'%');
                if (ctype_digit($search)) $q->orWhere('id', $search);
            });
        }
        return response()->json($query->orderByDesc($data['sort'] ?? 'views_count')->orderByDesc('id')->paginate(25));
    }
}

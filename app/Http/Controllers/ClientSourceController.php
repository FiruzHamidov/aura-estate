<?php

namespace App\Http\Controllers;

use App\Models\ClientSource;
use Illuminate\Http\Request;

class ClientSourceController extends Controller
{
    public function index(Request $request)
    {
        $activeOnly = $request->boolean('active_only', true);

        return response()->json(
            ClientSource::query()
                ->when($activeOnly, fn ($query) => $query->where('is_active', true))
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
        );
    }
}


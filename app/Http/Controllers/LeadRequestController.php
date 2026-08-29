<?php

namespace App\Http\Controllers;

use App\Services\Crm\PublicLeadIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadRequestController extends Controller
{
    public function store(Request $request, PublicLeadIntake $intake): JsonResponse
    {
        $data = $request->all();
        if ($request->hasHeader('Idempotency-Key')) {
            $data['idempotency_key'] = $request->header('Idempotency-Key');
        }
        $result = $intake->accept($data);

        return response()->json($result, $result['replayed'] ? 200 : 201);
    }
}

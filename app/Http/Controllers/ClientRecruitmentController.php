<?php

namespace App\Http\Controllers;

use App\Rules\InternationalPhoneNumber;
use App\Services\GroupAccess\UserOrganizationService;
use Illuminate\Http\Request;

final class ClientRecruitmentController extends Controller
{
    public function __construct(private readonly UserOrganizationService $organization) {}

    public function lookup(Request $request)
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:48', new InternationalPhoneNumber]]);
        $client = $this->organization->findRecruitableClient($request->user(), $data['phone']);

        return response()->json($client->only(['id', 'name']));
    }

    public function store(Request $request, int $clientId)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:48', new InternationalPhoneNumber],
            'branch_group_id' => ['required', 'integer', 'min:1'],
        ]);
        $agent = $this->organization->recruitAgent($request->user(), $clientId, $data['phone'], (int) $data['branch_group_id']);

        return response()->json($agent->only(['id', 'name', 'branch_id', 'branch_group_id']));
    }
}

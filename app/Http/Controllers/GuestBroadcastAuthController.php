<?php

namespace App\Http\Controllers;

use App\Services\Messaging\GuestSupportSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

class GuestBroadcastAuthController extends Controller
{
    public function __construct(
        private readonly GuestSupportSessionService $sessions,
    ) {}

    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'socket_id' => ['required', 'string', 'max:100', 'regex:/^\d+\.\d+$/'],
            'channel_name' => [
                'required',
                'string',
                'max:160',
                'regex:/^private-guest-support\.conversation\.[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            ],
        ]);

        $session = $this->sessions->resolve($request);
        abort_unless($session, 401, 'Guest support session is required.');

        $request->merge($validated);
        $request->setUserResolver(fn () => $session);

        return Broadcast::auth($request);
    }
}

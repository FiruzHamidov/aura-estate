<?php

namespace App\Services\Messaging;

use App\Models\GuestSupportSession;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

class GuestSupportSessionService
{
    public function resolve(Request $request): ?GuestSupportSession
    {
        $token = $request->cookie($this->cookieName());

        if (! is_string($token) || strlen($token) !== 43) {
            return null;
        }

        $session = GuestSupportSession::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();

        if (! $session) {
            return null;
        }

        if (! $session->last_seen_at || $session->last_seen_at->lt(now()->subMinutes(5))) {
            $session->forceFill(['last_seen_at' => now()])->save();
        }

        return $session;
    }

    /** @return array{0: GuestSupportSession, 1: string} */
    public function issue(Request $request): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $session = GuestSupportSession::query()->create([
            'public_id' => (string) Str::uuid(),
            'token_hash' => hash('sha256', $token),
            'last_seen_at' => now(),
            'expires_at' => now()->addMinutes($this->lifetimeMinutes()),
            'meta' => [
                'created_from' => 'guest_support',
                'user_agent_hash' => hash('sha256', (string) $request->userAgent()),
            ],
        ]);

        return [$session, $token];
    }

    public function cookie(string $token): Cookie
    {
        return cookie(
            $this->cookieName(),
            $token,
            $this->lifetimeMinutes(),
            '/api/guest-support',
            null,
            (bool) config('guest-support.cookie_secure', true),
            true,
            false,
            (string) config('guest-support.cookie_same_site', 'lax')
        );
    }

    public function rateLimitKey(Request $request): string
    {
        $token = (string) $request->cookie($this->cookieName());

        return hash('sha256', $token !== '' ? $token : (string) ($request->ip() ?? 'unknown'));
    }

    public function cookieName(): string
    {
        return (string) config('guest-support.cookie', 'aura_guest_support');
    }

    private function lifetimeMinutes(): int
    {
        return max(60, (int) config('guest-support.lifetime_minutes', 43200));
    }
}

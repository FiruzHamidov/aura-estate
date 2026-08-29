<?php

namespace App\Services\Residential;

use Illuminate\Validation\ValidationException;

/** Parse links, never fetch remote URLs or accept arbitrary embed HTML. */
final class VideoLink
{
    public function parse(string $url): array
    {
        $parts = parse_url($url);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
            $this->invalid();
        }
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        $id = null;
        parse_str($parts['query'] ?? '', $query);
        if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'www.youtube-nocookie.com', 'youtube-nocookie.com'], true)) {
            $id = $path === '/watch' && is_string($query['v'] ?? null) ? $query['v'] : (preg_match('#^/(?:embed|shorts)/([A-Za-z0-9_-]{11})/?$#', $path, $matches) ? $matches[1] : null);
        } elseif ($host === 'youtu.be') {
            $id = ltrim($path, '/');
        }
        if (is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
            return ['provider' => 'youtube', 'provider_id' => $id, 'url' => 'https://www.youtube.com/watch?v='.$id];
        }
        if (in_array($host, ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true) && ! isset($query['h']) && preg_match('#^/(?:video/)?([0-9]{1,12})/?$#', $path, $matches)) {
            return ['provider' => 'vimeo', 'provider_id' => $matches[1], 'url' => 'https://vimeo.com/'.$matches[1]];
        }
        $this->invalid();
    }

    public function embed(string $provider, string $id): ?string
    {
        return match (true) {
            $provider === 'youtube' && (bool) preg_match('/^[A-Za-z0-9_-]{11}$/', $id) => 'https://www.youtube-nocookie.com/embed/'.$id,
            $provider === 'vimeo' && (bool) preg_match('/^[0-9]{1,12}$/', $id) => 'https://player.vimeo.com/video/'.$id,
            default => null,
        };
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['url' => 'Укажите HTTPS-ссылку на публичный ролик YouTube или Vimeo без приватных ключей, iframe-кода и других провайдеров.']);
    }
}

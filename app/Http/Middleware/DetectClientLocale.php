<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DetectClientLocale
{
    /** Пробуем вытащить язык из Accept-Language (ru, tg, en и т.п.) */
    public function handle(Request $request, Closure $next)
    {
        $accept = $request->header('Accept-Language', '');
        $locale = $this->parseLocale($accept) ?? 'ru'; // дефолт
        app()->setLocale($locale);
        $request->attributes->set('client_locale', $locale);
        return $next($request);
    }

    private function parseLocale(string $header): ?string
    {
        if (!$header) return null;
        // Берём первый тег языка вида ru-RU, tg, en-US и т.п.
        $first = trim(explode(',', $header)[0] ?? '');
        if (!$first) return null;
        $lang = Str::of($first)->lower()->before('-')->value(); // ru-RU => ru
        if (!$lang) return null;
        // при желании whitelisting:
        if (!in_array($lang, ['ru','tg','en'])) return 'ru';
        return $lang;
    }
}

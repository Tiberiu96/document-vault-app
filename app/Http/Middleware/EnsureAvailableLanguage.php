<?php

namespace App\Http\Middleware;

use App\Services\LanguageCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAvailableLanguage
{
    public function handle(Request $request, Closure $next): Response
    {
        $language = app(LanguageCatalog::class)->available()->firstWhere('locale', app()->getLocale());
        abort_unless($language, 404);
        $request->attributes->set('display_currency', $language->default_currency);

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventFoundationIndexing
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $public = $request->route()?->defaults['indexable'] ?? false;
        if (! app()->environment('production') || ! $public || $response->getStatusCode() !== 200) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        if (str_contains((string) $request->route()?->getName(), 'password.') || str_contains((string) $request->route()?->getName(), 'verification.')) {
            $response->headers->set('Referrer-Policy', 'no-referrer');
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}

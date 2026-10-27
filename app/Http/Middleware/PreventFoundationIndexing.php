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

        return $response;
    }
}

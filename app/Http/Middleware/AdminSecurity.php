<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminSecurity
{
    public function handle(Request $request, Closure $next)
    {
        app()->setLocale('ro');
        if (app()->environment('production')) {
            abort_unless($request->isSecure() && config('session.secure'), 503, 'Administrarea necesita HTTPS si cookie securizat.');
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");

        return $response;
    }
}

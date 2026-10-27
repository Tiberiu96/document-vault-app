<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class BlockNonLocalAiRoutes
{
    public function handle(Request $request, Closure $next)
    {
        // Inainte de sesiune/CSRF/auth: inclusiv POST fara token ramane 404 in productie.
        if (! app()->environment('local')) {
            foreach (array_keys(config('vault.locales')) as $locale) {
                $path = $locale.'/'.trans('routes.ai.test', [], $locale);
                abort_if($request->is($path, $path.'/*'), 404);
            }
        }

        return $next($request);
    }
}

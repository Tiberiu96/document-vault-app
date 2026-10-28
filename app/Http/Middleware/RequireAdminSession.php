<?php

namespace App\Http\Middleware;

use App\Services\Admin\AdminAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RequireAdminSession
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::guard('admin')->user()?->fresh();
        if ($user) {
            Auth::guard('admin')->setUser($user);
        }
        if (! $user) {
            return redirect()->route('admin.login');
        }
        $access = app(AdminAccess::class);
        if (! $user->is_super_admin) {
            $access->logout($request);
            abort(403);
        }
        $fingerprint = $request->session()->get('admin.password_fingerprint');
        $idle = (int) $request->session()->get('admin.last_seen', 0);
        if (! is_string($fingerprint) || ! hash_equals(hash('sha256', $user->password), $fingerprint)
            || ! $idle || $idle > now()->timestamp || now()->timestamp - $idle > config('admin.idle_seconds')
            || (! $access->verified($request, $user) && ! $access->passwordFresh($request))) {
            $access->logout($request);

            return redirect()->route('admin.login')->with('admin_status', 'Sesiunea a expirat. Autentifica-te din nou.');
        }
        $request->session()->put('admin.last_seen', now()->timestamp);
        Auth::shouldUse('admin');

        return $next($request);
    }
}

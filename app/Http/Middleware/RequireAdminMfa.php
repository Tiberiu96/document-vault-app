<?php

namespace App\Http\Middleware;

use App\Services\Admin\AdminAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class RequireAdminMfa
{
    public function handle(Request $request, Closure $next)
    {
        Gate::authorize('platform.admin');
        $user = Auth::guard('admin')->user();
        if (! app(AdminAccess::class)->verified($request, $user)) {
            return redirect()->route($user->admin_mfa_confirmed_at ? 'admin.mfa.challenge' : 'admin.mfa.setup');
        }

        return $next($request);
    }
}

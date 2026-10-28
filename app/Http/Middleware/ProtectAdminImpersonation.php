<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Admin\AdminAccess;
use App\Services\Admin\AdminAudit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProtectAdminImpersonation
{
    public function handle(Request $request, Closure $next)
    {
        $state = $request->session()->get('admin.impersonation');
        if (! is_array($state) || $request->routeIs('admin.impersonation.stop')) {
            return $next($request);
        }
        $actor = User::find($state['actor_id'] ?? 0);
        $target = Auth::guard('web')->user()?->fresh();
        $idle = (int) $request->session()->get('admin.last_seen', 0);
        $valid = $actor && $target && ! $target->is_super_admin && $target->id === ($state['target_id'] ?? null)
            && Auth::guard('admin')->id() === $actor->id && app(AdminAccess::class)->verified($request, $actor)
            && hash_equals(hash('sha256', $actor->password), (string) $request->session()->get('admin.password_fingerprint'))
            && $idle > 0 && $idle <= now()->timestamp && now()->timestamp - $idle <= config('admin.idle_seconds');
        if (! $valid) {
            if ($actor) {
                AdminAudit::record($actor, 'impersonation.expired', 'user', $state['target_id'] ?? null);
            }
            Auth::guard('web')->logoutCurrentDevice();
            app(AdminAccess::class)->logout($request);

            return redirect()->route('admin.login');
        }
        $request->session()->put('admin.last_seen', now()->timestamp);
        Auth::shouldUse('web');
        if ($request->is('admin', 'admin/*') || ! in_array($request->method(), ['GET', 'HEAD'], true) || str_contains((string) $request->route()?->getName(), 'verification.verify')) {
            app()->setLocale('ro');

            return response()->view('admin.impersonation-readonly', [], 403)->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow');
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}

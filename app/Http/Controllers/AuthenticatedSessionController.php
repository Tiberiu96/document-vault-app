<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Services\WebsiteContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class AuthenticatedSessionController extends Controller
{
    public function create(Request $request)
    {
        return view('auth.login', app(WebsiteContext::class)->forPage($request, 'login'));
    }

    public function store(LoginRequest $request)
    {
        $credentials = $request->safe()->only(['email', 'password']);
        $accountKey = 'login:account:'.hash('sha256', $credentials['email']);
        $ipKey = 'login:ip:'.hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($accountKey, 5) || RateLimiter::tooManyAttempts($ipKey, 20)) {
            $seconds = max(RateLimiter::availableIn($accountKey), RateLimiter::availableIn($ipKey));

            return response()->view('auth.login', [
                ...app(WebsiteContext::class)->forPage($request, 'login'),
                'loginError' => __('auth.throttle', ['seconds' => $seconds]),
            ], 429)->header('Retry-After', max(1, $seconds));
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($accountKey, 60);
            RateLimiter::hit($ipKey, 60);

            return back()->withErrors(['email' => __('auth.failed')])->withInput($request->only('email'));
        }

        RateLimiter::clear($accountKey);
        $request->session()->regenerate();

        // Destinatie localizata fixa; nu accepta redirect arbitrar din input.
        if (is_string($request->session()->get('pending_invitation'))) {
            return redirect()->route('invitations.show', ['invitation' => $request->session()->get('pending_invitation')]);
        }

        return redirect()->route('dashboard');
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->boolean('switch_account')) {
            return redirect()->route('login');
        }

        return redirect()->route('home');
    }
}

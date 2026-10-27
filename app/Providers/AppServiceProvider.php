<?php

namespace App\Providers;

use App\Services\WebsiteContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Inchidere explicita inclusiv in local, pana la autentificare + MFA.
        Horizon::auth(fn (Request $request): bool => false);
        RateLimiter::for('admin', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('demo', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('registration', fn (Request $request) => Limit::perHour(config('vault.rate_limits.registration_per_hour'))
            ->by(hash('sha256', (string) $request->ip()))
            ->response(fn (Request $request, array $headers) => response()->view('auth.register', [
                ...app(WebsiteContext::class)->forPage($request, 'register'),
                'registrationError' => __('auth.throttle', ['seconds' => $headers['Retry-After'] ?? 3600]),
            ], 429, $headers)));
    }
}

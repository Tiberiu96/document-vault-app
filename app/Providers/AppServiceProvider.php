<?php

namespace App\Providers;

use App\Contracts\Ai\AiProviderInterface;
use App\Contracts\Ocr\OcrProviderInterface;
use App\Services\Ai\OllamaProvider;
use App\Services\Ocr\LocalOcrProvider;
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
        $this->app->bind(AiProviderInterface::class, OllamaProvider::class);
        $this->app->bind(OcrProviderInterface::class, LocalOcrProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Inchidere explicita inclusiv in local, pana la autentificare + MFA.
        Horizon::auth(fn (Request $request): bool => false);
        RateLimiter::for('password.email', function (Request $request) {
            $email = is_string($request->input('email')) ? mb_strtolower(trim($request->input('email'))) : '';

            return [Limit::perHour(3)->by('reset-account:'.hash('sha256', $email)), Limit::perHour(20)->by('reset-ip:'.hash('sha256', (string) $request->ip()))];
        });
        RateLimiter::for('password.reset', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('verification.verify', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('local.ai', fn (Request $request) => Limit::perMinute(3)->by($request->user()->id));
        RateLimiter::for('local.ai.status', fn (Request $request) => Limit::perMinute(60)->by($request->user()->id));
        RateLimiter::for('document.processing', fn (Request $request) => Limit::perMinute(3)->by($request->user()->id));
        RateLimiter::for('document.upload', fn (Request $request) => Limit::perMinute(10)->by($request->user()->id));
        RateLimiter::for('document.search', fn (Request $request) => Limit::perMinute(60)->by($request->user()->id));
        RateLimiter::for('taxonomy', fn (Request $request) => Limit::perMinute(30)->by($request->user()->id));
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

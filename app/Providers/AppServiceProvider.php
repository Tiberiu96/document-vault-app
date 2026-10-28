<?php

namespace App\Providers;

use App\Contracts\Ai\AiProviderInterface;
use App\Contracts\Ocr\OcrProviderInterface;
use App\Models\User;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\GroqProvider;
use App\Services\Ai\OllamaProvider;
use App\Services\Ocr\LocalOcrProvider;
use App\Services\WebsiteContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        $this->app->bind(AiProviderInterface::class, fn ($app) => match (config('ai.provider')) {
            'ollama' => $app->make(OllamaProvider::class),
            'groq' => $app->make(GroqProvider::class),
            default => throw new AiProviderException('UNSUPPORTED_AI_PROVIDER'),
        });
        $this->app->bind(OcrProviderInterface::class, LocalOcrProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('platform.admin', fn (User $user) => (bool) $user->is_super_admin);
        $adminThrottle = function (Request $request, array $headers) {
            return response()->view('admin.auth.throttled', ['seconds' => max(1, (int) ($headers['Retry-After'] ?? 60))], 429, $headers);
        };
        RateLimiter::for('admin.panel', fn (Request $request) => Limit::perMinute(config('admin.requests_per_minute'))->by('admin-panel:'.($request->user('admin')?->id ?? $request->ip()))->response($adminThrottle));
        RateLimiter::for('admin.login', function (Request $request) use ($adminThrottle) {
            $email = is_string($request->input('email')) ? mb_strtolower(trim($request->input('email'))) : '';

            return [Limit::perMinute(config('admin.login_per_minute'))->by('admin-login:'.hash('sha256', $email))->response($adminThrottle), Limit::perMinute(20)->by('admin-ip:'.$request->ip())->response($adminThrottle)];
        });
        RateLimiter::for('admin.mfa', fn (Request $request) => Limit::perMinute(config('admin.mfa_per_minute'))->by('admin-mfa:'.$request->user('admin')->id)->response($adminThrottle));
        // Horizon brut ramane inchis: dashboardul admin prezinta numai date sanitizate.
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
        RateLimiter::for('reminders', fn (Request $request) => Limit::perMinute(config('reminders.changes_per_minute'))->by($request->user()->id));
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

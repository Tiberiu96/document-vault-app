<?php

namespace App\Providers;

use App\Contracts\Ai\AiProviderInterface;
use App\Contracts\Billing\StripeBillingGateway;
use App\Contracts\Ocr\OcrProviderInterface;
use App\Http\Middleware\EnsureActiveWorkspace;
use App\Http\Middleware\EnsureAvailableLanguage;
use App\Models\User;
use App\Notifications\Channels\WorkspaceDatabaseChannel;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\GeminiProvider;
use App\Services\Ai\GroqProvider;
use App\Services\Ai\OllamaProvider;
use App\Services\Backup\BackupBarrier;
use App\Services\Billing\StripeGateway;
use App\Services\Ocr\LocalOcrProvider;
use App\Services\WebsiteContext;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Queue\Events\WorkerIdle;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;
use Livewire\Livewire;
use NielsNumbers\LaravelLocalizer\Middleware\SetLocale;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DatabaseChannel::class, WorkspaceDatabaseChannel::class);
        $this->app->bind(AiProviderInterface::class, fn ($app) => match (config('ai.provider')) {
            'ollama' => $app->make(OllamaProvider::class),
            'groq' => $app->make(GroqProvider::class),
            'gemini' => $app->make(GeminiProvider::class),
            default => throw new AiProviderException('UNSUPPORTED_AI_PROVIDER'),
        });
        $this->app->bind(OcrProviderInterface::class, LocalOcrProvider::class);
        $this->app->bind(StripeBillingGateway::class, StripeGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->singleton(BackupBarrier::class);
        Event::listen(WorkerIdle::class, fn () => app(BackupBarrier::class)->idle());
        Queue::looping(fn () => app(BackupBarrier::class)->loop());
        Queue::before(fn ($event) => app(BackupBarrier::class)->jobStart($event->job));
        Queue::after(fn ($event) => app(BackupBarrier::class)->jobEnd($event->job));
        Queue::exceptionOccurred(fn ($event) => app(BackupBarrier::class)->jobEnd($event->job));
        Livewire::setUpdateRoute(fn ($handle, $path) => Route::post($path, $handle)->middleware(['web', 'auth:web', 'auth.session', 'verified', 'throttle:notifications.livewire']));
        RateLimiter::for('notifications.livewire', fn (Request $request) => Limit::perMinute(120)->by($request->user('web')?->id ?? $request->ip()));
        Livewire::addPersistentMiddleware([SetLocale::class, AuthenticateSession::class, EnsureEmailIsVerified::class, EnsureActiveWorkspace::class, EnsureAvailableLanguage::class]);
        Gate::define('platform.admin', fn (User $user) => (bool) $user->is_super_admin);
        $adminThrottle = function (Request $request, array $headers) {
            return response()->view('admin.auth.throttled', ['seconds' => max(1, (int) ($headers['Retry-After'] ?? 60))], 429, $headers);
        };
        RateLimiter::for('admin.panel', fn (Request $request) => Limit::perMinute(config('admin.requests_per_minute'))->by('admin-panel:'.($request->user('admin')?->id ?? $request->ip()))->response($adminThrottle));
        RateLimiter::for('admin.login', function (Request $request) use ($adminThrottle) {
            $email = is_string($request->input('email')) ? mb_strtolower(trim($request->input('email'))) : '';

            return [Limit::perMinute(config('admin.login_per_minute'))->by('admin-login:'.hash('sha256', $email))->response($adminThrottle), Limit::perMinute(20)->by('admin-ip:'.$request->ip())->response($adminThrottle)];
        });
        RateLimiter::for('admin.mfa', fn (Request $request) => Limit::perMinute(config('admin.mfa_per_minute'))->by('admin-mfa:'.($request->user('admin')?->id ?? $request->ip()))->response($adminThrottle));
        // Horizon brut ramane inchis: dashboardul admin prezinta numai date sanitizate.
        Horizon::auth(fn (Request $request): bool => false);
        RateLimiter::for('turnstile.forms', fn (Request $request) => config('turnstile.enabled') ? Limit::perMinute(10)->by('turnstile:'.$request->ip()) : Limit::none());
        RateLimiter::for('password.email', function (Request $request) {
            $email = is_string($request->input('email')) ? mb_strtolower(trim($request->input('email'))) : '';

            return [Limit::perHour(3)->by('reset-account:'.hash('sha256', $email)), Limit::perHour(20)->by('reset-ip:'.hash('sha256', (string) $request->ip()))];
        });
        RateLimiter::for('plan.requests', fn (Request $request) => Limit::perMinute(config('vault.rate_limits.plan_requests_per_minute'))->by('plan-requests:'.$request->user()->id));
        RateLimiter::for('billing', fn (Request $request) => Limit::perMinute(config('vault.rate_limits.billing_per_minute'))->by('billing:'.$request->user()->id));
        RateLimiter::for('stripe.webhook', fn (Request $request) => Limit::perMinute(config('vault.rate_limits.billing_webhooks_per_minute'))->by('stripe-webhook:'.$request->ip()));
        RateLimiter::for('profile.update', fn (Request $request) => Limit::perMinute(config('vault.rate_limits.profile_updates_per_minute'))->by('profile:'.$request->user()->id));
        RateLimiter::for('profile.password', fn (Request $request) => Limit::perMinute(config('vault.rate_limits.profile_password_per_minute'))->by('profile-password:'.$request->user()->id));
        RateLimiter::for('profile.avatar', fn (Request $request) => Limit::perMinute(config('vault.rate_limits.profile_avatar_per_minute'))->by('profile-avatar:'.$request->user()->id));
        RateLimiter::for('password.reset', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('verification.verify', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('local.ai', fn (Request $request) => Limit::perMinute(3)->by($request->user()->id));
        RateLimiter::for('local.ai.status', fn (Request $request) => Limit::perMinute(60)->by($request->user()->id));
        RateLimiter::for('document.processing', fn (Request $request) => Limit::perMinute(3)->by($request->user()->id));
        RateLimiter::for('document.upload', fn (Request $request) => Limit::perMinute(10)->by($request->user()->id));
        RateLimiter::for('document.search', fn (Request $request) => Limit::perMinute(60)->by($request->user()->id));
        RateLimiter::for('reminders', fn (Request $request) => Limit::perMinute(config('reminders.changes_per_minute'))->by($request->user()->id));
        RateLimiter::for('notifications', fn (Request $request) => Limit::perMinute(30)->by($request->user()->id));
        RateLimiter::for('invitations', fn (Request $request) => Limit::perMinute(10)->by('invitations:'.$request->user()->id));
        RateLimiter::for('taxonomy', fn (Request $request) => Limit::perMinute(30)->by($request->user()->id));
        RateLimiter::for('admin', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('registration', fn (Request $request) => Limit::perHour(config('vault.rate_limits.registration_per_hour'))
            ->by(hash('sha256', (string) $request->ip()))
            ->response(fn (Request $request, array $headers) => response()->view('auth.register', [
                ...app(WebsiteContext::class)->forPage($request, 'register'),
                'registrationError' => __('auth.throttle', ['seconds' => $headers['Retry-After'] ?? 3600]),
            ], 429, $headers)));
    }
}

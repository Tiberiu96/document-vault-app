<?php

use App\Http\Middleware\BlockNonLocalAiRoutes;
use App\Http\Middleware\PreventFoundationIndexing;
use App\Services\WebsiteContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\Middleware\SubstituteBindings;
use NielsNumbers\LaravelLocalizer\Middleware\RedirectLocale;
use NielsNumbers\LaravelLocalizer\Middleware\SetLocale;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(BlockNonLocalAiRoutes::class);
        $middleware->append(PreventFoundationIndexing::class);
        $middleware->web(remove: [SubstituteBindings::class]);
        $middleware->web(append: [SetLocale::class, RedirectLocale::class, SubstituteBindings::class]);
        $middleware->redirectGuestsTo(fn (Request $request) => route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (InvalidSignatureException $exception, Request $request) {
            if (str_contains((string) $request->route()?->getName(), 'verification.verify')) {
                return response()->view('auth.verification-error', [
                    ...app(WebsiteContext::class)->forPage($request, 'security'),
                    'verificationProblem' => 'expired_link',
                ], 403);
            }
        });
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'token']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

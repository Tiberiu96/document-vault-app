<?php

use App\Http\Middleware\PreventFoundationIndexing;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
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
        $middleware->append(PreventFoundationIndexing::class);
        $middleware->web(remove: [SubstituteBindings::class]);
        $middleware->web(append: [SetLocale::class, RedirectLocale::class, SubstituteBindings::class]);
        $middleware->redirectGuestsTo(fn (Request $request) => route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

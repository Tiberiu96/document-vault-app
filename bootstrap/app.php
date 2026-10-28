<?php

use App\Http\Middleware\BackupSnapshotBarrier;
use App\Http\Middleware\BlockNonLocalAiRoutes;
use App\Http\Middleware\PreventFoundationIndexing;
use App\Http\Middleware\ProtectAdminImpersonation;
use App\Services\WebsiteContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use NielsNumbers\LaravelLocalizer\Middleware\RedirectLocale;
use NielsNumbers\LaravelLocalizer\Middleware\SetLocale;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(BlockNonLocalAiRoutes::class);
        $middleware->prepend(BackupSnapshotBarrier::class);
        $middleware->append(PreventFoundationIndexing::class);
        $middleware->web(remove: [SubstituteBindings::class]);
        $middleware->web(append: [SetLocale::class, RedirectLocale::class, SubstituteBindings::class, ProtectAdminImpersonation::class]);
        $middleware->redirectGuestsTo(fn (Request $request) => route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('admin', 'admin/*') || $exception instanceof ValidationException || $exception instanceof HttpResponseException) {
                return null;
            }
            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;

            return response()->view('admin.auth.error', ['status' => $status], $status);
        });
        $exceptions->respond(function (Response $response) {
            if (request()->is('admin', 'admin/*')) {
                $response->headers->set('Cache-Control', 'no-store, private');
                $response->headers->set('Referrer-Policy', 'no-referrer');
                $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
                $response->headers->set('X-Frame-Options', 'DENY');
            }

            return $response;
        });
        $exceptions->render(function (InvalidSignatureException $exception, Request $request) {
            if (str_contains((string) $request->route()?->getName(), 'verification.verify')) {
                return response()->view('auth.verification-error', [
                    ...app(WebsiteContext::class)->forPage($request, 'security'),
                    'verificationProblem' => 'expired_link',
                ], 403);
            }
        });
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'token', 'code', 'recovery_code', 'cf-turnstile-response']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

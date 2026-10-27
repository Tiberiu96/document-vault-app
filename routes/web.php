<?php

use App\Http\Controllers\AuthenticatedSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PublicDiscoveryController;
use App\Http\Controllers\PublicPageController;
use App\Http\Controllers\RegisteredUserController;
use App\Http\Controllers\SwitchWorkspaceController;
use App\Http\Middleware\EnsureAvailableLanguage;
use App\Services\LanguageCatalog;
use Illuminate\Support\Facades\Route;
use NielsNumbers\LaravelLocalizer\Facades\Localizer;

Route::get('/', fn () => redirect('/'.app(LanguageCatalog::class)->defaultLocale()))->name('root');
Route::get('/sitemap.xml', [PublicDiscoveryController::class, 'sitemap'])->name('sitemap');
Route::get('/robots.txt', [PublicDiscoveryController::class, 'robots'])->name('robots');
Route::get('/llms.txt', [PublicDiscoveryController::class, 'llms'])->name('llms');

Route::translate(function () {
    Route::middleware(EnsureAvailableLanguage::class)->group(function () {
        foreach (config('vault.public_pages') as $page) {
            Route::get(Localizer::url($page), [PublicPageController::class, 'index'])->name($page)->defaults('indexable', true);
        }
        Route::get(Localizer::url('demo'), [PublicPageController::class, 'demo'])->middleware('throttle:demo')->name('demo');
        Route::middleware('guest')->group(function () {
            Route::get(Localizer::url('login'), [AuthenticatedSessionController::class, 'create'])->name('login');
            Route::post(Localizer::url('login'), [AuthenticatedSessionController::class, 'store'])->name('login.store');
            Route::get(Localizer::url('register'), [RegisteredUserController::class, 'create'])->name('register');
            Route::post(Localizer::url('register'), [RegisteredUserController::class, 'store'])->middleware('throttle:registration')->name('register.store');
        });
        Route::middleware('auth')->group(function () {
            Route::get(Localizer::url('dashboard'), DashboardController::class)->name('dashboard');
            Route::post(Localizer::url('logout'), [AuthenticatedSessionController::class, 'destroy'])->name('logout');
            Route::post(Localizer::url('workspace.switch'), SwitchWorkspaceController::class)->name('workspace.switch');
        });
    });
});

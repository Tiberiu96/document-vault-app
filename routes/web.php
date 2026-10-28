<?php

use App\Http\Controllers\AccountSecurityController;
use App\Http\Controllers\AuthenticatedSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentProcessingController;
use App\Http\Controllers\DocumentScannerController;
use App\Http\Controllers\LocalAiController;
use App\Http\Controllers\PublicDiscoveryController;
use App\Http\Controllers\PublicPageController;
use App\Http\Controllers\RegisteredUserController;
use App\Http\Controllers\ReminderController;
use App\Http\Controllers\SwitchWorkspaceController;
use App\Http\Controllers\TaxonomyController;
use App\Http\Middleware\EnsureActiveWorkspace;
use App\Http\Middleware\EnsureAvailableLanguage;
use App\Http\Middleware\EnsureLocalEnvironment;
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
        Route::middleware([EnsureLocalEnvironment::class, 'auth', 'auth.session', 'verified', EnsureActiveWorkspace::class])->group(function () {
            Route::get(Localizer::url('ai.test'), [LocalAiController::class, 'index'])->name('ai.playground');
            Route::post(Localizer::url('ai.test'), [LocalAiController::class, 'store'])->middleware('throttle:local.ai')->name('ai.run');
            Route::get(Localizer::url('ai.test').'/{run}', [LocalAiController::class, 'status'])->whereUuid('run')->middleware('throttle:local.ai.status')->name('ai.status');
        });
        Route::get(Localizer::url('demo'), [PublicPageController::class, 'demo'])->middleware('throttle:demo')->name('demo');
        Route::get(Localizer::url('verification.verify').'/{id}/{hash}', [AccountSecurityController::class, 'verify'])->whereNumber('id')->where('hash', '[a-f0-9]{40}')->middleware(['signed:relative', 'throttle:verification.verify'])->name('verification.verify');
        Route::middleware('guest')->group(function () {
            Route::get(Localizer::url('password.request'), [AccountSecurityController::class, 'forgot'])->name('password.request');
            Route::post(Localizer::url('password.request'), [AccountSecurityController::class, 'sendReset'])->middleware('throttle:password.email')->name('password.email');
            Route::get(Localizer::url('password.reset').'/{token}', [AccountSecurityController::class, 'resetForm'])->name('password.reset');
            Route::post(Localizer::url('password.reset'), [AccountSecurityController::class, 'reset'])->middleware('throttle:password.reset')->name('password.update');
            Route::get(Localizer::url('login'), [AuthenticatedSessionController::class, 'create'])->name('login');
            Route::post(Localizer::url('login'), [AuthenticatedSessionController::class, 'store'])->name('login.store');
            Route::get(Localizer::url('register'), [RegisteredUserController::class, 'create'])->name('register');
            Route::post(Localizer::url('register'), [RegisteredUserController::class, 'store'])->middleware('throttle:registration')->name('register.store');
        });
        Route::middleware(['auth', 'auth.session'])->group(function () {
            Route::get(Localizer::url('verification.notice'), [AccountSecurityController::class, 'notice'])->name('verification.notice');
            Route::post(Localizer::url('verification.send'), [AccountSecurityController::class, 'resend'])->name('verification.send');
            Route::middleware(['verified', EnsureActiveWorkspace::class])->group(function () {
                Route::get(Localizer::url('reminders'), [ReminderController::class, 'index'])->name('reminders.index');
                Route::post(Localizer::url('documents').'/{document}/'.Localizer::url('reminders'), [ReminderController::class, 'store'])->whereUuid('document')->middleware('throttle:reminders')->name('reminders.store');
                Route::patch(Localizer::url('reminders').'/{reminder}', [ReminderController::class, 'update'])->whereUuid('reminder')->middleware('throttle:reminders')->name('reminders.update');
                Route::get(Localizer::url('documents'), [DocumentController::class, 'index'])->middleware('throttle:document.search')->name('documents.index');
                Route::get(Localizer::url('documents.scan'), [DocumentScannerController::class, 'index'])->name('documents.scan');
                Route::post(Localizer::url('documents.scan'), [DocumentScannerController::class, 'store'])->middleware('throttle:document.upload')->name('documents.scan-store');
                Route::get(Localizer::url('documents.upload'), [DocumentController::class, 'create'])->name('documents.create');
                Route::post(Localizer::url('documents'), [DocumentController::class, 'store'])->middleware('throttle:document.upload')->name('documents.store');
                Route::post(Localizer::url('documents').'/{document}/'.Localizer::url('process').'/{kind}', [DocumentProcessingController::class, 'start'])->whereUuid('document')->where('kind', 'ocr|ai')->middleware('throttle:document.processing')->name('documents.process');
                Route::get(Localizer::url('documents').'/{document}/'.Localizer::url('processing.status'), [DocumentProcessingController::class, 'status'])->whereUuid('document')->middleware('throttle:document.search')->name('documents.processing-status');
                Route::get(Localizer::url('documents').'/{document}/'.Localizer::url('suggestions'), [DocumentProcessingController::class, 'suggestions'])->whereUuid('document')->name('documents.suggestions');
                Route::post(Localizer::url('documents').'/{document}/'.Localizer::url('suggestions.reject'), [DocumentProcessingController::class, 'reject'])->whereUuid('document')->name('documents.suggestions.reject');
                Route::get(Localizer::url('documents').'/{document}', [DocumentController::class, 'show'])->whereUuid('document')->name('documents.show');
                Route::get(Localizer::url('documents').'/{document}/'.Localizer::url('edit'), [DocumentController::class, 'edit'])->whereUuid('document')->name('documents.edit');
                Route::patch(Localizer::url('documents').'/{document}', [DocumentController::class, 'update'])->whereUuid('document')->name('documents.update');
                Route::post(Localizer::url('documents').'/{document}/'.Localizer::url('source.received'), [DocumentController::class, 'sourceReceived'])->whereUuid('document')->middleware('throttle:document.upload')->name('documents.source-received');
                Route::delete(Localizer::url('documents').'/{document}/'.Localizer::url('draft.discard'), [DocumentController::class, 'discard'])->whereUuid('document')->name('documents.discard');
                Route::get(Localizer::url('documents').'/{document}/'.Localizer::url('delete.confirm'), [DocumentController::class, 'confirmDeletion'])->whereUuid('document')->name('documents.delete-confirmation');
                Route::delete(Localizer::url('documents').'/{document}', [DocumentController::class, 'destroy'])->whereUuid('document')->name('documents.destroy');
                Route::get(Localizer::url('documents').'/{document}/'.Localizer::url('preview'), [DocumentController::class, 'file'])->whereUuid('document')->name('documents.preview');
                Route::get(Localizer::url('documents').'/{document}/'.Localizer::url('download'), [DocumentController::class, 'file'])->whereUuid('document')->defaults('download', true)->name('documents.download');
                Route::get(Localizer::url('categories'), [TaxonomyController::class, 'index'])->name('categories.index');
                foreach (['category' => 'categories', 'tag' => 'tags'] as $kind => $slug) {
                    Route::post(Localizer::url($slug), [TaxonomyController::class, 'save'])->defaults('taxonomy', $kind)->middleware('throttle:taxonomy')->name($slug.'.store');
                    Route::patch(Localizer::url($slug).'/{item}', [TaxonomyController::class, 'save'])->whereNumber('item')->defaults('taxonomy', $kind)->middleware('throttle:taxonomy')->name($slug.'.update');
                    Route::delete(Localizer::url($slug).'/{item}', [TaxonomyController::class, 'destroy'])->whereNumber('item')->defaults('taxonomy', $kind)->middleware('throttle:taxonomy')->name($slug.'.destroy');
                }
            });
            Route::get(Localizer::url('dashboard'), DashboardController::class)->name('dashboard');
            Route::post(Localizer::url('logout'), [AuthenticatedSessionController::class, 'destroy'])->name('logout');
            Route::post(Localizer::url('workspace.switch'), SwitchWorkspaceController::class)->name('workspace.switch');
        });
    });
});

require __DIR__.'/admin.php';

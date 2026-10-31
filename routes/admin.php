<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminBackupController;
use App\Http\Controllers\Admin\AdminBillingPriceController;
use App\Http\Controllers\Admin\AdminCatalogController;
use App\Http\Controllers\Admin\AdminClientController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminImpersonationController;
use App\Http\Controllers\Admin\AdminLanguageController;
use App\Http\Controllers\Admin\AdminPlanController;
use App\Http\Middleware\AdminSecurity;
use App\Http\Middleware\RequireAdminMfa;
use App\Http\Middleware\RequireAdminSession;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->middleware([AdminSecurity::class, 'throttle:admin.panel'])->group(function () {
    Route::post('client/opreste-accesul', [AdminImpersonationController::class, 'stop'])->name('impersonation.stop');
    Route::get('autentificare', [AdminAuthController::class, 'login'])->name('login');
    Route::post('autentificare', [AdminAuthController::class, 'authenticate'])->middleware('throttle:admin.login')->name('login.store');
    Route::middleware(RequireAdminSession::class)->group(function () {
        Route::post('deconectare', [AdminAuthController::class, 'logout'])->name('logout');
        Route::get('mfa/configurare', [AdminAuthController::class, 'setup'])->name('mfa.setup');
        Route::post('mfa/configurare', [AdminAuthController::class, 'enroll'])->middleware('throttle:admin.mfa')->name('mfa.enroll');
        Route::get('mfa/verificare', [AdminAuthController::class, 'challenge'])->name('mfa.challenge');
        Route::post('mfa/verificare', [AdminAuthController::class, 'verify'])->middleware('throttle:admin.mfa')->name('mfa.verify');
        Route::middleware(RequireAdminMfa::class)->group(function () {
            Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
            Route::get('backup/stare-restaurare', [AdminBackupController::class, 'status'])->name('backup.restore.status');
            Route::post('backup/detalii', [AdminBackupController::class, 'inspect'])->middleware('throttle:3,1')->name('backup.inspect');
            Route::post('backup/restaureaza', [AdminBackupController::class, 'restore'])->middleware('throttle:admin.mfa')->name('backup.restore');
            Route::get('backup', [AdminDashboardController::class, 'backup'])->name('backup');
            Route::get('notificari', [AdminDashboardController::class, 'notifications'])->name('notifications');
            Route::get('utilizatori', [AdminDashboardController::class, 'users'])->name('users');
            Route::get('utilizatori/creeaza', [AdminClientController::class, 'create'])->name('users.create');
            Route::post('utilizatori', [AdminClientController::class, 'save'])->name('users.store');
            Route::get('utilizatori/{client}/editeaza', [AdminClientController::class, 'edit'])->whereNumber('client')->name('users.edit');
            Route::patch('utilizatori/{client}', [AdminClientController::class, 'save'])->whereNumber('client')->name('users.update');
            Route::delete('utilizatori/{client}', [AdminClientController::class, 'destroy'])->whereNumber('client')->name('users.destroy');
            Route::post('utilizatori/{client}/acceseaza', [AdminImpersonationController::class, 'start'])->whereNumber('client')->name('users.impersonate');
            Route::get('spatii', [AdminDashboardController::class, 'workspaces'])->name('workspaces');
            Route::get('spatii/{workspace}/plan', [AdminPlanController::class, 'workspace'])->whereNumber('workspace')->name('workspace.plan');
            Route::patch('spatii/{workspace}/plan', [AdminPlanController::class, 'assign'])->whereNumber('workspace')->name('workspace.plan.update');
            Route::post('spatii/{workspace}/plan/cerere/refuza', [AdminPlanController::class, 'rejectUpgrade'])->whereNumber('workspace')->name('workspace.upgrade.reject');
            Route::get('securitate', [AdminAuthController::class, 'security'])->name('security');
            Route::get('mfa/coduri-recuperare', [AdminAuthController::class, 'recovery'])->name('mfa.recovery');
            Route::post('mfa/coduri-recuperare', [AdminAuthController::class, 'regenerate'])->middleware('throttle:admin.mfa')->name('mfa.regenerate');
            Route::get('limite', [AdminPlanController::class, 'index'])->name('plans');
            Route::patch('limite', [AdminPlanController::class, 'update'])->name('plans.update');
            Route::patch('preturi-abonamente', [AdminBillingPriceController::class, 'update'])->name('billing-prices.update');
            Route::get('limbi', [AdminLanguageController::class, 'index'])->name('languages');
            Route::post('limbi', [AdminLanguageController::class, 'save'])->name('languages.store');
            Route::patch('limbi/{language}', [AdminLanguageController::class, 'save'])->whereNumber('language')->name('languages.update');
            Route::post('limbi/{language}/implicit', [AdminLanguageController::class, 'makeDefault'])->whereNumber('language')->name('languages.default');
            Route::delete('limbi/{language}', [AdminLanguageController::class, 'destroy'])->whereNumber('language')->name('languages.destroy');
            foreach (['categories' => 'categorii', 'tags' => 'etichete'] as $kind => $slug) {
                Route::get($slug, [AdminCatalogController::class, 'index'])->name($kind);
                Route::post($slug, [AdminCatalogController::class, 'save'])->name($kind.'.store');
                Route::patch($slug.'/{item}', [AdminCatalogController::class, 'save'])->whereNumber('item')->name($kind.'.update');
                Route::delete($slug.'/{item}', [AdminCatalogController::class, 'destroy'])->whereNumber('item')->name($kind.'.destroy');
            }
            Route::post('procesari/{run}/reluare', [AdminDashboardController::class, 'retry'])->whereUuid('run')->name('processing.retry');
        });
    });
});

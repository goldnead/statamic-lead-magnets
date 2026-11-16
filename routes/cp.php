<?php

use Goldnead\BrandContext\Http\Middleware\GuardSetup;
use Goldnead\LeadMagnets\Http\Controllers\Cp\GrantController;
use Goldnead\LeadMagnets\Http\Controllers\Cp\ResourceController;
use Goldnead\LeadMagnets\Support\Setup;
use Illuminate\Support\Facades\Route;

/*
 * Every write route below carries `can:` middleware AND calls
 * authorizeOrFail() in the controller. That is not belt-and-braces for its own
 * sake: the middleware is what a reader of this file can verify, and the
 * controller check is what survives a route being re-registered elsewhere or
 * an action being called directly from a test or a console command.
 */

// The setup guard for the detail routes and writes: the tables the resources
// listing checks (ResourceController::index). Runs as middleware, before the
// route reaches for a model.
$guard = GuardSetup::using(Setup::class, 'lead-magnets::nav.lead_magnets', 'lead_magnet_resources', 'lead_magnet_grants', 'entitlements');

Route::prefix('lead-magnets')->name('lead-magnets.')->group(function () use ($guard) {

    Route::prefix('resources')->name('resources.')->group(function () use ($guard) {
        Route::get('/', [ResourceController::class, 'index'])
            ->name('index')
            ->middleware('can:view lead magnets');

        Route::get('/create', [ResourceController::class, 'create'])
            ->name('create')
            ->middleware('can:manage lead magnets');

        Route::post('/', [ResourceController::class, 'store'])
            ->name('store')
            ->middleware('can:manage lead magnets');

        Route::get('/{resource}', [ResourceController::class, 'show'])
            ->name('show')
            ->whereNumber('resource')
            ->middleware(['can:view lead magnets', $guard]);

        // Kein `edit`. Die Detailseite ist das Formular — wie beim
        // Collection-Entry — und speichert ueber `update` unten.
        Route::patch('/{resource}', [ResourceController::class, 'update'])
            ->name('update')
            ->whereNumber('resource')
            ->middleware(['can:manage lead magnets', $guard]);

        Route::delete('/{resource}', [ResourceController::class, 'destroy'])
            ->name('destroy')
            ->whereNumber('resource')
            ->middleware(['can:manage lead magnets', $guard]);
    });

    Route::prefix('grants')->name('grants.')->group(function () use ($guard) {
        Route::post('/{grant}/revoke', [GrantController::class, 'revoke'])
            ->name('revoke')
            ->whereNumber('grant')
            ->middleware(['can:manage lead magnet grants', $guard]);

        Route::post('/{grant}/reinstate', [GrantController::class, 'reinstate'])
            ->name('reinstate')
            ->whereNumber('grant')
            ->middleware(['can:manage lead magnet grants', $guard]);

        Route::post('/{grant}/resend', [GrantController::class, 'resend'])
            ->name('resend')
            ->whereNumber('grant')
            ->middleware(['can:manage lead magnet grants', $guard]);
    });
});

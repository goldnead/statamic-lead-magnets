<?php

use Goldnead\LeadMagnets\Http\Controllers\Cp\GrantController;
use Goldnead\LeadMagnets\Http\Controllers\Cp\ResourceController;
use Illuminate\Support\Facades\Route;

/*
 * Every write route below carries `can:` middleware AND calls
 * authorizeOrFail() in the controller. That is not belt-and-braces for its own
 * sake: the middleware is what a reader of this file can verify, and the
 * controller check is what survives a route being re-registered elsewhere or
 * an action being called directly from a test or a console command.
 */

Route::prefix('lead-magnets')->name('lead-magnets.')->group(function () {

    Route::prefix('resources')->name('resources.')->group(function () {
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
            ->middleware('can:view lead magnets');

        Route::get('/{resource}/edit', [ResourceController::class, 'edit'])
            ->name('edit')
            ->whereNumber('resource')
            ->middleware('can:manage lead magnets');

        Route::patch('/{resource}', [ResourceController::class, 'update'])
            ->name('update')
            ->whereNumber('resource')
            ->middleware('can:manage lead magnets');

        Route::delete('/{resource}', [ResourceController::class, 'destroy'])
            ->name('destroy')
            ->whereNumber('resource')
            ->middleware('can:manage lead magnets');
    });

    Route::prefix('grants')->name('grants.')->group(function () {
        Route::post('/{grant}/revoke', [GrantController::class, 'revoke'])
            ->name('revoke')
            ->whereNumber('grant')
            ->middleware('can:manage lead magnet grants');

        Route::post('/{grant}/reinstate', [GrantController::class, 'reinstate'])
            ->name('reinstate')
            ->whereNumber('grant')
            ->middleware('can:manage lead magnet grants');

        Route::post('/{grant}/resend', [GrantController::class, 'resend'])
            ->name('resend')
            ->whereNumber('grant')
            ->middleware('can:manage lead magnet grants');
    });
});

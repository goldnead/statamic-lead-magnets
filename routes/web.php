<?php

use Goldnead\BrandContext\Http\Middleware\SetBrandFromRouteValue;
use Goldnead\LeadMagnets\Http\Controllers\Web\ConfirmController;
use Goldnead\LeadMagnets\Http\Controllers\Web\DownloadController;
use Goldnead\LeadMagnets\Http\Controllers\Web\RequestController;
use Goldnead\LeadMagnets\Http\Middleware\SetBrandFromConfirmationToken;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
use Illuminate\Support\Facades\Route;

/*
 * Every route in this file is opened without a session — by a form on a
 * marketing page, by a mail client, by a stranger's browser. Under multi-brand
 * that means no brand is current and the fail-closed scope would hide the very
 * record the request points at, so each route derives the brand from the one
 * value the visitor already carries. Each of those values addresses exactly
 * one record across all brands, which is what makes the derivation safe rather
 * than a hole in the isolation.
 */

Route::prefix(config('lead-magnets.routes.prefix', '!/lead-magnets'))->group(function () {

    // The form names its resource, and a resource handle belongs to exactly
    // one brand (see the migration for why that unique is global).
    Route::post('/request', [RequestController::class, 'store'])
        ->name('lead-magnets.request')
        ->middleware([
            SetBrandFromRouteValue::class.':'.Resource::class.',handle,resource',
            'throttle:'.config('lead-magnets.requests.throttle', '10,1'),
        ]);

    Route::get('/confirm/{token}', ConfirmController::class)
        ->name('lead-magnets.confirm')
        ->middleware(SetBrandFromConfirmationToken::class.':token');

    /*
     * The delivery route.
     *
     * `signed` is the whole security model and it is not decoration: it
     * answers 403 for an expired link and for any link whose grant id, expiry
     * or query string was edited, before the controller runs. Nothing else in
     * this addon re-implements that check, and nothing should — a bespoke
     * token scheme here would be a second thing to get right for no gain.
     *
     * The signature proves the link was issued. Whether the access still
     * stands is a separate question, and DownloadController asks it: a revoked
     * grant holds links that verify perfectly and must not serve.
     */
    Route::get('/download/{grant}', DownloadController::class)
        ->name('lead-magnets.download')
        ->whereNumber('grant')
        ->middleware([
            'signed',
            SetBrandFromRouteValue::class.':'.Grant::class.',id,grant',
        ]);
});

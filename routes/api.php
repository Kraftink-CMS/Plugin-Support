<?php

use App\Http\Middleware\ApiAvailability;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;
use KraftInk\Plugins\Support\Http\Controllers\Api\ApiTicketController;

Route::prefix('api/support/tickets')
    ->name('plugins.support.api.tickets.')
    ->middleware(['api', ApiAvailability::class, AuthenticateApiToken::class.':support:tickets:read', 'permission:support.view', 'throttle:60,1'])
    ->group(function () {
        Route::get('/', [ApiTicketController::class, 'index'])->name('index');
        Route::get('/{id}', [ApiTicketController::class, 'show'])->whereNumber('id')->name('show');
    });

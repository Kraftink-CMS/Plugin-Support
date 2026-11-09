<?php

use Illuminate\Support\Facades\Route;
use KraftInk\Plugins\Support\Http\Controllers\MessageHistoryController;
use KraftInk\Plugins\Support\Http\Controllers\DraftController;
use KraftInk\Plugins\Support\Http\Controllers\FileController;
use KraftInk\Plugins\Support\Http\Controllers\TicketController;
use KraftInk\Plugins\Support\Http\Middleware\RequireTicketAccess;

Route::prefix('support')
    ->name('plugins.support.public.')
    ->middleware(['web', 'auth', RequireTicketAccess::class])
    ->group(function () {
        Route::name('tickets.')->group(function () {
            Route::get('/', [TicketController::class, 'index'])
                ->middleware(['permission:support.view', 'capability:extension,support'])
                ->name('index');
            Route::post('/', [TicketController::class, 'store'])
                ->middleware(['permission:support.view', 'throttle:5,60,support-ticket-create:'])
                ->name('store');
            Route::get('/{id}', [TicketController::class, 'show'])
                ->whereNumber('id')
                ->name('show');
            Route::post('/{id}/reply', [TicketController::class, 'reply'])
                ->whereNumber('id')
                ->middleware('throttle:20,1')
                ->name('reply');
            Route::patch('/{id}', [TicketController::class, 'update'])
                ->whereNumber('id')
                ->name('update');
            Route::patch('/{id}/assignment', [TicketController::class, 'assign'])
                ->whereNumber('id')
                ->middleware(['permission:support.manage', 'permission:support.assign'])
                ->name('assign');
            Route::put('/{id}/fields', [TicketController::class, 'updateFields'])
                ->whereNumber('id')
                ->middleware('permission:support.manage')
                ->name('fields.update');
        });

        Route::prefix('drafts')
            ->name('drafts.')
            ->group(function () {
                Route::post('/', [DraftController::class, 'store'])
                    ->middleware('throttle:30,1,support-drafts:')
                    ->name('store');
                Route::delete('/', [DraftController::class, 'discard'])
                    ->middleware('throttle:30,1,support-drafts:')
                    ->name('destroy');
            });

        Route::prefix('images')
            ->name('images.')
            ->group(function () {
                Route::post('/', [FileController::class, 'upload'])
                    ->middleware('throttle:20,1')
                    ->name('store');
            });

        Route::prefix('files')
            ->name('files.')
            ->group(function () {
                Route::get('/{id}', [FileController::class, 'show'])
                    ->whereNumber('id')
                    ->name('show');
            });

        Route::prefix('messages')
            ->name('messages.')
            ->group(function () {
                Route::get('/{id}/history', [MessageHistoryController::class, 'index'])
                    ->whereNumber('id')
                    ->name('history');
                Route::put('/{id}', [MessageHistoryController::class, 'edit'])
                    ->whereNumber('id')
                    ->middleware('throttle:20,1')
                    ->name('update');
                Route::delete('/{id}', [MessageHistoryController::class, 'destroy'])
                    ->whereNumber('id')
                    ->name('destroy');
                Route::post('/{id}/revisions/{revision}', [MessageHistoryController::class, 'restore'])
                    ->whereNumber(['id', 'revision'])
                    ->name('revisions.restore');
            });

        Route::prefix('attachments')
            ->name('attachments.')
            ->group(function () {
                Route::get('/{id}', [FileController::class, 'download'])
                    ->whereNumber('id')
                    ->name('download');
            });
    });

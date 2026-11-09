<?php

use Illuminate\Support\Facades\Route;
use KraftInk\Plugins\Support\Http\Controllers\Admin\SupportAdminController;
use KraftInk\Plugins\Support\Http\Controllers\TicketController;

Route::prefix('admin/plugins/support')
    ->name('plugins.support.admin.')
    ->middleware(['web', 'auth', 'permission:admin.access', 'permission:support.manage'])
    ->group(function () {
        Route::prefix('tickets')
            ->name('tickets.')
            ->group(function () {
                Route::get('/', [SupportAdminController::class, 'index'])->name('index');
                Route::get('/{id}', [TicketController::class, 'show'])
                    ->whereNumber('id')
                    ->name('show');
                Route::delete('/{id}', [SupportAdminController::class, 'destroy'])
                    ->whereNumber('id')
                    ->name('destroy');
            });

        Route::prefix('categories')
            ->name('categories.')
            ->group(function () {
                Route::get('/', [SupportAdminController::class, 'categories'])->name('index');
                Route::post('/', [SupportAdminController::class, 'saveCategory'])->name('store');
                Route::put('/{id}', [SupportAdminController::class, 'saveCategory'])
                    ->whereNumber('id')
                    ->name('update');
                Route::delete('/{id}', [SupportAdminController::class, 'deleteCategory'])
                    ->whereNumber('id')
                    ->name('destroy');
            });

        Route::prefix('fields')
            ->name('fields.')
            ->group(function () {
                Route::get('/', [SupportAdminController::class, 'fields'])
                    ->middleware('permission:support.fields.manage')
                    ->name('index');
                Route::post('/{id?}', [SupportAdminController::class, 'saveField'])
                    ->whereNumber('id')
                    ->middleware('permission:support.fields.manage')
                    ->name('store');
                Route::delete('/{id}', [SupportAdminController::class, 'deleteField'])
                    ->whereNumber('id')
                    ->middleware('permission:support.fields.manage')
                    ->name('destroy');
            });

        Route::prefix('templates')
            ->name('templates.')
            ->group(function () {
                Route::get('/', [SupportAdminController::class, 'templates'])->name('index');
                Route::post('/', [SupportAdminController::class, 'saveTemplate'])->name('store');
                Route::put('/{id}', [SupportAdminController::class, 'saveTemplate'])
                    ->whereNumber('id')
                    ->name('update');
                Route::delete('/{id}', [SupportAdminController::class, 'deleteTemplate'])
                    ->whereNumber('id')
                    ->name('destroy');
            });

        Route::prefix('settings')
            ->name('settings.')
            ->group(function () {
                Route::get('/', [SupportAdminController::class, 'settings'])->name('edit');
                Route::post('/', [SupportAdminController::class, 'saveSettings'])->name('update');
            });

        Route::prefix('deliveries')
            ->name('deliveries.')
            ->group(function () {
                Route::post('/{id}/retry', [SupportAdminController::class, 'retryDelivery'])
                    ->whereUuid('id')
                    ->name('retry');
            });
    });

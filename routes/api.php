<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::name('api.')->group(function () {
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('login');

    Route::post('refresh', [AuthController::class, 'refresh'])
        ->middleware('throttle:api')
        ->name('refresh');

    Route::post('logout', [AuthController::class, 'logout'])
        ->middleware(['auth:api', 'throttle:api'])
        ->name('logout');

    Route::middleware(['auth:api', 'admin', 'throttle:api'])->group(function () {
        Route::post('users/bulk-delete', [UserController::class, 'bulkDestroy'])->name('users.bulk-destroy');
        Route::apiResource('users', UserController::class);
    });
});

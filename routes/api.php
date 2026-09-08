<?php

// use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\StaffController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {

    // Route::get('/dashboard', DashboardController::class)
    //     ->middleware('can:dashboard.view');

    // Route::get('/staff', [StaffController::class, 'index'])
    //     ->middleware('can:staff.view');

    // Route::post('/staff', [StaffController::class, 'store'])
    //     ->middleware('can:staff.create');

    // Route::get('/groups', [GroupController::class, 'index'])
    //     ->middleware('can:group.view');

    // Route::post('/groups', [GroupController::class, 'store'])
    //     ->middleware('can:group.create');
});

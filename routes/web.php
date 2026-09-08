<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Auth\QrLoginController;
use App\Http\Controllers\Group\GroupController;
use App\Http\Controllers\Notification\NotificationController;
use App\Http\Controllers\Staff\StaffController;
use App\Http\Controllers\Task\SprintController;
use App\Http\Controllers\Task\TaskCommentController;
use App\Http\Controllers\Task\TaskController;
use App\Http\Controllers\Telegram\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Guest Routes
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {

    Route::get(
        '/login',
        [QrLoginController::class, 'show']
    )->name('login');

    Route::post(
        '/login/qr/create',
        [QrLoginController::class, 'create']
    )->name('login.qr.create');

    Route::get(
        '/login/qr/{session}/status',
        [QrLoginController::class, 'status']
    )->name('login.qr.status');

    Route::post(
        '/login/qr/{session}/authenticate',
        [QrLoginController::class, 'authenticate']
    )->name('login.qr.authenticate');

    Route::post(
        '/login',
        [LoginController::class, 'login']
    )->name('login.post');
});


/*
|--------------------------------------------------------------------------
| Telegram Webhook
|--------------------------------------------------------------------------
|
| Telegram does not authenticate using the web session.
|
*/

Route::post(
    '/telegram/webhook',
    [WebhookController::class, 'handle']
)->name('telegram.webhook');


/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/dashboard',
        [DashboardController::class, 'index']
    )->name('dashboard');


    /*
    |--------------------------------------------------------------------------
    | Logout
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/logout',
        [QrLoginController::class, 'logout']
    )->name('logout');


    /*
    |--------------------------------------------------------------------------
    | Groups
    |--------------------------------------------------------------------------
    */

    // Route::resource(
    //     'groups',
    //     GroupController::class
    // );

    // Group membership
    // Route::post(
    //     '/groups/{group}/staff',
    //     [GroupController::class, 'addStaff']
    // )->name('groups.staff.add');

    // Route::delete(
    //     '/groups/{group}/staff/{staff}',
    //     [GroupController::class, 'removeStaff']
    // )->name('groups.staff.remove');


    /*
    |--------------------------------------------------------------------------
    | Staff
    |--------------------------------------------------------------------------
    */

    Route::resource(
        'staff',
        StaffController::class
    );

    Route::post(
        '/staff/{staff}/regenerate-token',
        [StaffController::class, 'regenerateToken']
    )
        ->name('staff.regenerate-token')
        ->middleware('can:staff.update');


    /*
    |--------------------------------------------------------------------------
    | Tasks
    |--------------------------------------------------------------------------
    */

    Route::prefix('tasks')
        ->name('tasks.')
        ->group(function () {

            /*
            |----------------------------------------------------------------------
            | Task CRUD
            |----------------------------------------------------------------------
            */

            Route::get(
                '/',
                [TaskController::class, 'index']
            )->name('index');

            Route::post(
                '/',
                [TaskController::class, 'store']
            )->name('store');

            Route::get(
                '/{task}',
                [TaskController::class, 'show']
            )->name('show');

            Route::put(
                '/{task}',
                [TaskController::class, 'update']
            )->name('update');

            Route::delete(
                '/{task}',
                [TaskController::class, 'destroy']
            )->name('destroy');


            /*
            |----------------------------------------------------------------------
            | Task Lifecycle
            |----------------------------------------------------------------------
            */

            Route::post(
                '/{task}/assign',
                [TaskController::class, 'assign']
            )->name('assign');

            Route::post(
                '/{task}/reassign',
                [TaskController::class, 'reassign']
            )->name('reassign');

            Route::post(
                '/{task}/status',
                [TaskController::class, 'changeStatus']
            )->name('status');

            Route::post(
                '/{task}/accept',
                [TaskController::class, 'accept']
            )->name('accept');


            /*
            |----------------------------------------------------------------------
            | Comments
            |----------------------------------------------------------------------
            */

            Route::get(
                '/{task}/comments',
                [TaskCommentController::class, 'index']
            )->name('comments.index');

            Route::post(
                '/{task}/comments',
                [TaskCommentController::class, 'store']
            )->name('comments.store');
        });

    Route::prefix('notifications')
    ->name('notifications.')
    ->group(function () {

        Route::get(
            '/',
            [NotificationController::class, 'index']
        )->name('index');

        Route::post(
            '/{notification}/read',
            [NotificationController::class, 'read']
        )->name('read');

        Route::post(
            '/read-all',
            [NotificationController::class, 'readAll']
        )->name('read-all');
    });


    /*
    |--------------------------------------------------------------------------
    | Sprints
    |--------------------------------------------------------------------------
    */

    Route::prefix('sprints')
        ->name('sprints.')
        ->group(function () {

            Route::get(
                '/',
                [SprintController::class, 'index']
            )->name('index');

            Route::post(
                '/',
                [SprintController::class, 'store']
            )->name('store');

            Route::get(
                '/{sprint}',
                [SprintController::class, 'show']
            )->name('show');

            Route::put(
                '/{sprint}',
                [SprintController::class, 'update']
            )->name('update');

            Route::delete(
                '/{sprint}',
                [SprintController::class, 'destroy']
            )->name('destroy');

            Route::post(
                '/{sprint}/tasks/{task}',
                [SprintController::class, 'addTask']
            )->name('tasks.add');

            Route::delete(
                '/{sprint}/tasks/{task}',
                [SprintController::class, 'removeTask']
            )->name('tasks.remove');
        });

    Route::get('security', fn () => view('security.index'))->name('security.index');
    Route::get('chain', fn () => view('chain.index'))->name('chain.index');
    Route::get('reports', fn () => view('reports.index'))->name('reports.index');
});

<?php

namespace App\Providers;

use App\Events\TaskStatusChanged;
use App\Events\TaskChanged;
use App\Listeners\SendTaskStatusChangedNotification;
use App\Listeners\SendTaskChangedNotification;
use App\Models\Task;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use App\Support\TashkentDateTime;
use Illuminate\Support\ServiceProvider;
use Auth;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        config(['app.timezone' => TashkentDateTime::TIMEZONE]);
        date_default_timezone_set(TashkentDateTime::TIMEZONE);
        /*
        |--------------------------------------------------------------------------
        | Super Admin Gate
        |--------------------------------------------------------------------------
        */

        Gate::before(function ($staff, $ability) {
            return $staff->hasRole('super-admin')
                ? true
                : null;
        });

        /*
        |--------------------------------------------------------------------------
        | Application Sidebar
        |--------------------------------------------------------------------------
        |
        | Groups are no longer separate models.
        |
        | The group information lives directly on Staff:
        |
        |   staff.group_chat_id
        |   staff.group_name
        |
        | Therefore the sidebar group is derived from the
        | authenticated staff member's group information.
        |
        */
         View::composer(
            'layouts.admin',
            function ($view) {

                $user = Auth::user();

                /*
                |--------------------------------------------------------------------------
                | Logged-in user role
                |--------------------------------------------------------------------------
                */

                $role = $user?->getRoleNames()
                    ->first();

                /*
                |--------------------------------------------------------------------------
                | Sidebar data
                |--------------------------------------------------------------------------
                */

                $view->with(
                    'sidebarData',
                    [
                        'tasksCount' => Task::query()->count(),

                        'user' => [
                            'full_name' => $user?->full_name,

                            'telegram_chat_id' =>
                                $user?->telegram_chat_id,

                            'role' => $role,

                            'lavozim' =>
                                $user?->lavozim,
                        ],
                    ]
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Topbar
        |--------------------------------------------------------------------------
        */

        View::composer('components.topbar', function ($view) {
            $staff = auth()->user();

            if (!$staff) {
                $view->with([
                    'headerNotifications' => collect(),

                    'headerUnreadNotifications' => 0,
                ]);

                return;
            }

            $headerNotifications = $staff->notifications()
                ->latest()
                ->limit(8)
                ->get();

            $headerUnreadNotifications = $staff
                ->unreadNotifications()
                ->count();

            $view->with([
                'headerNotifications' => $headerNotifications,

                'headerUnreadNotifications' => $headerUnreadNotifications,

                // 'summary' => Task::query()->count(),
            ]);
        });
    }
}

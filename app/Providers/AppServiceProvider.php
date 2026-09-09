<?php

namespace App\Providers;

use App\Events\TaskStatusChanged;
use App\Events\TaskChanged;
use App\Listeners\SendTaskStatusChangedNotification;
use App\Listeners\SendTaskChangedNotification;
use App\Models\Task;
use Carbon\Carbon;
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
        | Locale
        |--------------------------------------------------------------------------
        |
        | The whole admin panel UI is Uzbek (Latin script). Carbon's
        | human-readable diffs (diffForHumans()) must match, otherwise
        | English strings ("3 hours ago") leak into an otherwise fully
        | Uzbek page.
        */

        Carbon::setLocale('uz_Latn');

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

        View::composer([
            'components.topbar',
            'components.notifications',
        ], function ($view) {
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
                ->get()
                ->map(function ($notification) {
                    $data = $notification->data;

                    $message = match ($data['type'] ?? null) {
                        'task_status_changed' => trim(
                            ($data['actor_name'] ?? '')
                            . ': '
                            . ($data['to_status_label'] ?? '')
                        ),

                        'task_changed' =>
                            "Topshiriq ma'lumotlari yangilandi.",

                        default =>
                            $data['message']
                            ?? 'Tizimda yangi yangilanish mavjud.',
                    };

                    return (object) [
                        'id' => $notification->id,

                        'title' => $data['title']
                            ?? ($data['task_number'] ?? 'Bildirishnoma'),

                        'message' => $message,

                        'created_at' => $notification->created_at,

                        'read' => $notification->read_at !== null,
                    ];
                });

            $headerUnreadNotifications = $staff
                ->unreadNotifications()
                ->count();

            $view->with([
                'headerNotifications' => $headerNotifications,

                'headerUnreadNotifications' => $headerUnreadNotifications,
            ]);
        });
    }
}

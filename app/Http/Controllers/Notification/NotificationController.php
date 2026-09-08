<?php

namespace App\Http\Controllers\Notification;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Show all notifications for authenticated staff.
     */
    public function index(Request $request)
    {
        $staff = $request->user();

        $notifications = $staff->notifications()
            ->latest()
            ->get();

        $unreadCount = $staff->unreadNotifications()->count();

        return view('notifications.index', [
            'notifications' => $notifications,
            'unreadCount' => $unreadCount,
        ]);
    }

    /**
     * Mark one notification as read.
     */
    public function read(Request $request, string $notification)
    {
        $staff = $request->user();

        $item = $staff->notifications()
            ->where('id', $notification)
            ->firstOrFail();

        $item->markAsRead();

        return back();
    }

    /**
     * Mark all notifications as read.
     */
    public function readAll(Request $request)
    {
        $request->user()
            ->unreadNotifications
            ->markAsRead();

        return back();
    }
}

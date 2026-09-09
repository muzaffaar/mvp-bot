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

        return response()->json([
            'message' => 'Bildirishnoma o\'qilgan deb belgilandi.',
            'unread_count' => $staff->unreadNotifications()->count(),
        ]);
    }

    /**
     * Mark all notifications as read.
     */
    public function readAll(Request $request)
    {
        $staff = $request->user();

        $staff->unreadNotifications->markAsRead();

        return response()->json([
            'message' => 'Barcha bildirishnomalar o\'qilgan deb belgilandi.',
            'unread_count' => 0,
        ]);
    }
}

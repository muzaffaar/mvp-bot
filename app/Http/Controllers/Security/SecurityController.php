<?php

namespace App\Http\Controllers\Security;

use App\Enums\SecurityLogEventType;
use App\Http\Controllers\Controller;
use App\Models\SecurityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class SecurityController extends Controller
{
    public function index(Request $request): View
    {
        $staff = $request->user();

        $sessions = DB::table('sessions')
            ->where('user_id', $staff->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn ($session) => [
                'id' => $session->id,
                'device' => $this->describeUserAgent($session->user_agent),
                'ip_address' => $session->ip_address,
                'last_active' => Carbon::createFromTimestamp(
                    $session->last_activity
                )->diffForHumans(),
                'is_current' => $session->id === $request->session()->getId(),
            ])
            ->sortByDesc('is_current')
            ->values();

        $securityLogs = SecurityLog::query()
            ->where('staff_id', $staff->id)
            ->latest('created_at')
            ->limit(5)
            ->get();

        return view('security.index', [
            'sessions' => $sessions,
            'securityLogs' => $securityLogs,
        ]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $staff = $request->user();

        $staff->update([
            'password' => Hash::make($validated['new_password']),
        ]);

        SecurityLog::record(
            eventType: SecurityLogEventType::PASSWORD_CHANGED,
            staffId: $staff->id,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return back()->with(
            'security_success',
            'Parolingiz muvaffaqiyatli yangilandi.'
        );
    }

    public function destroySession(Request $request, string $session): RedirectResponse
    {
        $staff = $request->user();

        if ($session === $request->session()->getId()) {
            return back()->with(
                'security_error',
                'Joriy sessiyani bu yerdan o\'chirib bo\'lmaydi. Buning o\'rniga tizimdan chiqing.'
            );
        }

        $deleted = DB::table('sessions')
            ->where('id', $session)
            ->where('user_id', $staff->id)
            ->delete();

        if ($deleted) {
            SecurityLog::record(
                eventType: SecurityLogEventType::SESSION_REVOKED,
                staffId: $staff->id,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        return back()->with(
            'security_success',
            'Sessiya o\'chirildi.'
        );
    }

    private function describeUserAgent(?string $userAgent): string
    {
        if (! $userAgent) {
            return 'Noma\'lum qurilma';
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };

        $platform = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Mac OS') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        $label = trim(implode(' · ', array_filter([$browser, $platform])));

        return $label !== '' ? $label : 'Noma\'lum qurilma';
    }
}

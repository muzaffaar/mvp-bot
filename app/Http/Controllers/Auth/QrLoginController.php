<?php

namespace App\Http\Controllers\Auth;

use App\Enums\SecurityLogEventType;
use App\Http\Controllers\Controller;
use App\Models\QrLoginSession;
use App\Models\SecurityLog;
use App\Services\Authentication\QrLoginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class QrLoginController extends Controller
{
    public function __construct(
        private readonly QrLoginService $qrLoginService,
    ) {
    }

    /**
     * Display login page.
     */
    public function show(): mixed
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login');
    }

    /**
     * Create QR login session.
     */
    public function create(
        Request $request
    ): JsonResponse {
        $browserHash = $request->input('browser_hash');

        if (
            ! is_string($browserHash)
            || ! preg_match(
                '/^[a-f0-9]{64}$/',
                $browserHash
            )
        ) {
            return response()->json([
                'message' => 'Invalid browser hash.',
            ], 422);
        }

        $result = $this->qrLoginService->create(
            $browserHash
        );

        $token = $result['token'];

        $botUsername = config(
            'services.telegram.bot_username'
        );

        $qrUrl = sprintf(
            'https://t.me/%s?start=%s',
            $botUsername,
            urlencode($token)
        );

        return response()->json([
            'session_id' => $result['session']->id,
            'qr_url' => $qrUrl,
            'expires_at' => $result['session']
                ->expires_at
                ?->toIso8601String(),
        ]);
    }

    /**
     * Check QR login status.
     */
    public function status(
        QrLoginSession $session
    ): JsonResponse {
        /*
         * Expire old pending sessions.
         */
        if (
            $session->isPending()
            && $session->isExpired()
        ) {
            $this->qrLoginService->expire($session);
            $session->refresh();
        }

        return response()->json([
            'status' => $session->status,
            'approved' => $session->isApproved(),
            'expired' => $session->isExpired(),
        ]);
    }

    /**
     * Finalize authenticated browser session.
     */
    public function authenticate(
        Request $request,
        QrLoginSession $session
    ): JsonResponse {
        /*
         * The session must have been approved by Telegram.
         */
        if (! $session->isApproved()) {
            return response()->json([
                'message' => 'QR login is not approved.',
            ], 403);
        }

        /*
         * Never authenticate an expired session.
         */
        if (
            $session->expires_at
            && $session->expires_at->isPast()
        ) {
            $this->qrLoginService->expire($session);

            return response()->json([
                'message' => 'QR login session expired.',
            ], 403);
        }

        /*
         * Get staff attached to the approved QR session.
         */
        $staff = $session->staff;

        if (! $staff) {
            return response()->json([
                'message' => 'Staff not found.',
            ], 403);
        }

        /*
         * Staff account must be active.
         */
        if (! $staff->isActive()) {
            return response()->json([
                'message' => 'Staff account is inactive.',
            ], 403);
        }

        /*
         * Authenticate Laravel session.
         */
        Auth::login($staff);

        /*
         * Prevent session fixation.
         */
        $request->session()->regenerate();

        SecurityLog::record(
            eventType: SecurityLogEventType::LOGIN_SUCCESS,
            staffId: $staff->id,
            description: 'Telegram QR orqali kirish',
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        /*
         * IMPORTANT:
         *
         * The QR session must not remain approved.
         * It has now been consumed.
         */
        $this->qrLoginService->consume($session);

        return response()->json([
            'success' => true,
            'redirect' => route('dashboard'),
        ]);
    }

    /**
     * Logout.
     */
    public function logout(
        Request $request
    ): RedirectResponse {
        $staffId = Auth::id();

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($staffId) {
            SecurityLog::record(
                eventType: SecurityLogEventType::LOGOUT,
                staffId: $staffId,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        return redirect()->route('login');
    }
}

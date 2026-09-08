<?php

namespace App\Http\Controllers\Auth;

use App\Enums\SecurityLogEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\SecurityLog;
use App\Models\Staff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function login(LoginRequest $request): RedirectResponse
    {
        $credentials = [
            'login' => $request->string('login')->toString(),
            'password' => $request->string('password')->toString(),
            'status' => 'active',
        ];

        $remember = $request->boolean('remember');

        if (! Auth::attempt($credentials, $remember)) {
            SecurityLog::record(
                eventType: SecurityLogEventType::LOGIN_FAILED,
                staffId: Staff::where('login', $credentials['login'])->value('id'),
                description: "Muvaffaqiyatsiz urinish: {$credentials['login']}",
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return back()
                ->withInput($request->only('login'))
                ->withErrors([
                    'login' => 'Login yoki parol noto\'g\'ri.',
                ]);
        }

        $request->session()->regenerate();

        SecurityLog::record(
            eventType: SecurityLogEventType::LOGIN_SUCCESS,
            staffId: Auth::id(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return redirect()->intended(
            route('dashboard')
        );
    }
}

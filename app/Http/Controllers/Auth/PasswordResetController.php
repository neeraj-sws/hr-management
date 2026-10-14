<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Access;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * Password reset by e-mail link — for administrator accounts only. Employee
 * passwords are set by an administrator (Employees > Reset password); an
 * employee cannot change a password themselves, from the UI or this endpoint.
 */
class PasswordResetController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /** Always answers the same way, so the form cannot be used to find out which emails exist. */
    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        $user = User::where('email', $request->input('email'))->where('is_active', true)->first();
        if ($user && Access::isAdmin($user)) {
            rescue(fn () => Password::sendResetLink(['email' => $user->email]), null, true);
        } elseif ($user) {
            $audit->record('auth.password_reset_refused', $user, ['reason' => 'not_admin']);
        }

        return back()->with('status', 'If that email belongs to an administrator account, a reset link is on its way (it expires in 60 minutes). Employees: please ask your administrator to reset your password.');
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->numbers()],
        ]);
        $user = User::where('email', $request->input('email'))->first();
        if (! $user || ! Access::isAdmin($user)) {
            return back()->withInput($request->only('email'))->withErrors(['email' => 'Only an administrator can change this password. Please contact your administrator.']);
        }
        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) use ($audit) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete(); // sign out mobile and desktop apps
            $audit->record('auth.password_reset', $user);
            event(new PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', 'Your password was changed. Sign in with the new password.')
            : back()->withInput($request->only('email'))->withErrors(['email' => __($status)]);
    }
}

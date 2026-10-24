<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    private const CHALLENGE = 'two_factor.challenge';

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        // "email" holds the sign-in name: an e-mail address or an employee ID (employee code).
        $credentials = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ], ['email.required' => 'Enter your email or employee ID.']);
        $user = User::findForLogin($credentials['email']);

        if (! $user || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
            return back()
                ->withErrors(['email' => 'These credentials do not match our records.'])
                ->onlyInput('email');
        }

        // Website access (Settings > Security): this person uses the desktop / mobile app, not the website.
        if (! app(\App\Services\WebAccessService::class)->allows($user)) {
            return back()->withErrors(['email' => \App\Services\WebAccessService::MESSAGE])->onlyInput('email');
        }

        // An older hash (e.g. imported accounts) is upgraded now that the password is known to be right.
        Auth::getProvider()->rehashPasswordIfRequired($user, ['password' => $credentials['password']]);

        // 2-step sign-in: the password was right; now the code e-mailed to the person (unless this browser is trusted).
        if ($twoFactor->requiredFor($user, 'web') && ! $twoFactor->isTrusted($request, $user)) {
            try {
                $challenge = $twoFactor->start($user, 'web', $request->boolean('remember'));
            } catch (\Throwable $error) {
                report($error);

                return back()->withErrors(['email' => 'Your sign-in code could not be e-mailed. Try again in a minute, or ask HR to check the e-mail settings.'])->onlyInput('email');
            }
            $request->session()->put(self::CHALLENGE, $challenge);

            return redirect()->route('login.two-factor');
        }

        return $this->signIn($request, $user, $request->boolean('remember'));
    }

    public function twoFactor(Request $request, TwoFactorService $twoFactor): View|RedirectResponse
    {
        $user = $twoFactor->userFor((string) $request->session()->get(self::CHALLENGE, ''));
        if (! $user) return redirect()->route('login')->withErrors(['email' => 'Your sign-in has expired. Please sign in again.']);

        return view('auth.two-factor', ['email' => $twoFactor->maskedEmail($user), 'minutes' => TwoFactorService::CODE_MINUTES, 'trustDays' => TwoFactorService::TRUST_DAYS]);
    }

    public function verifyTwoFactor(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:12']], ['code.required' => 'Enter the 6-digit code from the e-mail.']);
        $id = (string) $request->session()->get(self::CHALLENGE, '');
        $remember = $twoFactor->remember($id);
        try {
            $user = $twoFactor->verify($id, (string) $request->input('code'));
        } catch (ValidationException $error) {
            if (! $twoFactor->challenge($id)) {
                $request->session()->forget(self::CHALLENGE);

                return redirect()->route('login')->withErrors($error->errors());
            }
            throw $error;
        }
        $request->session()->forget(self::CHALLENGE);
        if ($request->boolean('trust')) Cookie::queue($twoFactor->trustCookie($user));

        return $this->signIn($request, $user, $remember);
    }

    public function resendTwoFactor(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $id = (string) $request->session()->get(self::CHALLENGE, '');
        if (! $twoFactor->challenge($id)) return redirect()->route('login')->withErrors(['email' => 'Your sign-in has expired. Please sign in again.']);
        try {
            $twoFactor->resend($id);
        } catch (ValidationException $error) {
            throw $error;
        } catch (\Throwable $error) {
            report($error);

            return back()->withErrors(['code' => 'The code could not be e-mailed. Try again in a minute.']);
        }

        return back()->with('status', 'A new code has been e-mailed. The earlier code no longer works.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        if (\App\Services\ImpersonationService::active()) {
            app(\App\Services\ImpersonationService::class)->stop($request);
            if (Auth::check()) return redirect()->route('dashboard')->with('toast', ['message' => 'Back to your own account.', 'type' => 'success']);
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function signIn(Request $request, User $user, bool $remember): RedirectResponse
    {
        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}

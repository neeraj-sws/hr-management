<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\TwoFactorCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * 2-step sign-in: after the password, a 6-digit code e-mailed to the person.
 * Settings > Security decides who needs it (nobody / admins and HR / everyone)
 * and whether the desktop and mobile apps ask too; anyone can turn it on for
 * their own account in My profile. A browser can be trusted for 30 days.
 */
class TwoFactorService
{
    public const POLICY = 'security.two_factor';
    public const MODES = ['off' => 'Off', 'admins' => 'Admins and HR only', 'everyone' => 'Everyone'];
    public const TRUST_COOKIE = 'sws_2fa_trust';
    public const TRUST_DAYS = 30;
    public const CODE_MINUTES = 10;
    public const MAX_ATTEMPTS = 5;
    public const MAX_SENDS = 4;

    public function __construct(private readonly PolicyConfigurationService $policies, private readonly AuditLogger $audit) {}

    /** @return array{mode: string, apps: bool} */
    public function settings(): array
    {
        $policy = $this->policies->forEmployee(null, self::POLICY, today()->toDateString(), ['mode' => 'off', 'apps' => false]);
        $mode = (string) ($policy['mode'] ?? 'off');

        return ['mode' => isset(self::MODES[$mode]) ? $mode : 'off', 'apps' => (bool) ($policy['apps'] ?? false)];
    }

    /** Admins, HR and anyone who can change company settings. */
    public function isAdmin(User $user): bool
    {
        return $user->hasAnyRole(['Master Admin', 'HR', 'HR Manager']) || $user->can('policies.manage') || $user->can('hr.manage');
    }

    /** Whether this person must enter a code: on the website ('web') or in the desktop/mobile app ('app'). */
    public function requiredFor(User $user, string $channel = 'web'): bool
    {
        $settings = $this->settings();
        $required = (bool) $user->two_factor_enabled || $settings['mode'] === 'everyone' || ($settings['mode'] === 'admins' && $this->isAdmin($user));

        return $channel === 'app' ? $required && $settings['apps'] : $required;
    }

    /** E-mails a new code and returns the challenge id that the code is checked against. */
    public function start(User $user, string $channel, bool $remember = false): string
    {
        $id = Str::random(40);
        $this->send($id, ['user_id' => $user->id, 'channel' => $channel, 'remember' => $remember, 'attempts' => 0, 'sends' => 0]);

        return $id;
    }

    /** Sends a fresh code for the same sign-in (the old code stops working). */
    public function resend(string $id): void
    {
        $challenge = $this->challenge($id) ?? throw ValidationException::withMessages(['code' => 'This sign-in has expired. Sign in again.']);
        if ($challenge['sends'] >= self::MAX_SENDS) throw ValidationException::withMessages(['code' => 'Too many codes were sent. Sign in again in a few minutes.']);
        if (isset($challenge['sent_at']) && now()->timestamp - $challenge['sent_at'] < 30) throw ValidationException::withMessages(['code' => 'Wait half a minute before asking for another code.']);
        $this->send($id, $challenge);
    }

    public function challenge(string $id): ?array
    {
        return $id !== '' ? Cache::get($this->key($id)) : null;
    }

    public function userFor(string $id): ?User
    {
        $challenge = $this->challenge($id);

        return $challenge ? User::find($challenge['user_id']) : null;
    }

    /** The person the code belongs to; a wrong code counts towards the limit, after which the sign-in starts over. */
    public function verify(string $id, string $code): User
    {
        $challenge = $this->challenge($id) ?? throw ValidationException::withMessages(['code' => 'This code has expired. Sign in again to get a new one.']);
        $user = User::find($challenge['user_id']);
        $code = preg_replace('/\D/', '', $code);
        if (! $user || ! $user->is_active) {
            Cache::forget($this->key($id));
            throw ValidationException::withMessages(['code' => 'This account cannot sign in.']);
        }
        if (! hash_equals($challenge['hash'], $this->hash($id, $code))) {
            $challenge['attempts']++;
            $this->audit->record('auth.two_factor_failed', $user, ['channel' => $challenge['channel'], 'attempt' => $challenge['attempts']]);
            if ($challenge['attempts'] >= self::MAX_ATTEMPTS) {
                Cache::forget($this->key($id));
                throw ValidationException::withMessages(['code' => 'Too many wrong codes. Sign in again to get a new code.']);
            }
            Cache::put($this->key($id), $challenge, now()->addMinutes(self::CODE_MINUTES));
            $left = self::MAX_ATTEMPTS - $challenge['attempts'];
            throw ValidationException::withMessages(['code' => "That code is not right. {$left} ".str('try')->plural($left).' left.']);
        }
        Cache::forget($this->key($id));
        $this->audit->record('auth.two_factor_passed', $user, ['channel' => $challenge['channel']]);

        return $user;
    }

    public function remember(string $id): bool
    {
        return (bool) ($this->challenge($id)['remember'] ?? false);
    }

    /** "k*****@gmail.com": enough to recognise the inbox without showing the address. */
    public function maskedEmail(User $user): string
    {
        [$name, $domain] = array_pad(explode('@', (string) $user->email, 2), 2, '');

        return mb_substr($name, 0, 1).str_repeat('*', max(3, mb_strlen($name) - 1)).($domain !== '' ? '@'.$domain : '');
    }

    /** Lets this browser skip the code for 30 days; changing the password ends it. */
    public function trustCookie(User $user): Cookie
    {
        $expires = now()->addDays(self::TRUST_DAYS)->timestamp;

        return cookie(self::TRUST_COOKIE, $user->id.'|'.$expires.'|'.$this->trustSignature($user, $expires), self::TRUST_DAYS * 24 * 60, null, null, null, true, false, 'lax');
    }

    public function isTrusted(Request $request, User $user): bool
    {
        $parts = explode('|', (string) $request->cookie(self::TRUST_COOKIE), 3);
        if (count($parts) !== 3 || (int) $parts[0] !== $user->id || (int) $parts[1] < now()->timestamp) return false;

        return hash_equals($this->trustSignature($user, (int) $parts[1]), $parts[2]);
    }

    private function trustSignature(User $user, int $expires): string
    {
        return hash_hmac('sha256', $user->id.'|'.$expires.'|'.$user->password, (string) config('app.key'));
    }

    private function send(string $id, array $challenge): void
    {
        $user = User::findOrFail($challenge['user_id']);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        // Sent now and not through the queue: if e-mail fails, the person is told instead of waiting for nothing.
        $user->notifyNow(new TwoFactorCode($code, self::CODE_MINUTES));
        $challenge = array_merge($challenge, ['hash' => $this->hash($id, $code), 'attempts' => 0, 'sends' => $challenge['sends'] + 1, 'sent_at' => now()->timestamp]);
        Cache::put($this->key($id), $challenge, now()->addMinutes(self::CODE_MINUTES));
        $this->audit->record('auth.two_factor_sent', $user, ['channel' => $challenge['channel']]);
    }

    private function hash(string $id, string $code): string
    {
        return hash_hmac('sha256', $id.'|'.$code, (string) config('app.key'));
    }

    private function key(string $id): string
    {
        return 'two-factor:'.$id;
    }
}

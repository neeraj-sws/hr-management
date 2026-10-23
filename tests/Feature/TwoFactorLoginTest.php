<?php

namespace Tests\Feature;

use App\Livewire\Account\Profile;
use App\Livewire\System\Settings;
use App\Models\User;
use App\Notifications\TwoFactorCode;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** 2-step sign-in: an e-mailed 6-digit code after the password, on the website and (when turned on) in the apps. */
class TwoFactorLoginTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->employee = User::factory()->create(['email' => 'emp@example.com', 'password' => 'Secret123!']);
        $this->employee->assignRole('Employee');
        $this->admin->forceFill(['password' => bcrypt('Admin123!')])->save();
    }

    private function codeFor(User $user): string
    {
        $code = null;
        Notification::assertSentTo($user, TwoFactorCode::class, function (TwoFactorCode $n) use ($user, &$code) {
            preg_match('/\*\*(\d{6})\*\*/', implode(' ', $n->toMail($user)->introLines), $m);
            $code = $m[1] ?? null;

            return true;
        });

        return (string) $code;
    }

    private function setMode(string $mode, bool $apps = false)
    {
        return Livewire::actingAs($this->admin)->test(Settings::class)->set('tab', 'security')
            ->set('twoFactorMode', $mode)->set('twoFactorApps', $apps)->call('saveSecurity');
    }

    public function test_off_by_default_signs_in_with_the_password_only(): void
    {
        Notification::fake();
        $this->post(route('login.store'), ['email' => 'emp@example.com', 'password' => 'Secret123!'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->employee);
        Notification::assertNothingSent();
    }

    public function test_admins_only_asks_admins_for_the_emailed_code(): void
    {
        Notification::fake();
        $this->setMode('admins')->assertHasNoErrors();
        auth()->logout();

        $this->post(route('login.store'), ['email' => 'emp@example.com', 'password' => 'Secret123!'])->assertRedirect(route('dashboard'));
        auth()->logout();
        $this->flushSession();

        $this->post(route('login.store'), ['email' => 'admin@example.com', 'password' => 'Admin123!'])->assertRedirect(route('login.two-factor'));
        $this->assertGuest();
        $this->get(route('login.two-factor'))->assertOk()->assertSee('Enter your sign-in code')->assertSee('a****@example.com');
        $code = $this->codeFor($this->admin);

        $this->post(route('login.two-factor.verify'), ['code' => '000000' === $code ? '111111' : '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->post(route('login.two-factor.verify'), ['code' => $code, 'trust' => '1'])->assertRedirect(route('dashboard'))->assertCookie(TwoFactorService::TRUST_COOKIE);
        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_a_trusted_browser_skips_the_code_and_a_password_change_ends_the_trust(): void
    {
        Notification::fake();
        $this->setMode('everyone');
        auth()->logout();
        $service = app(TwoFactorService::class);
        $cookie = $service->trustCookie($this->employee);
        $this->withCookie(TwoFactorService::TRUST_COOKIE, $cookie->getValue())
            ->post(route('login.store'), ['email' => 'emp@example.com', 'password' => 'Secret123!'])->assertRedirect(route('dashboard'));
        auth()->logout();
        $this->flushSession();

        $this->employee->forceFill(['password' => bcrypt('Changed123!')])->save();
        $this->withCookie(TwoFactorService::TRUST_COOKIE, $cookie->getValue())
            ->post(route('login.store'), ['email' => 'emp@example.com', 'password' => 'Changed123!'])->assertRedirect(route('login.two-factor'));
    }

    public function test_five_wrong_codes_end_the_sign_in(): void
    {
        Notification::fake();
        $this->setMode('everyone');
        auth()->logout();
        $this->post(route('login.store'), ['email' => 'emp@example.com', 'password' => 'Secret123!']);
        $code = $this->codeFor($this->employee);
        $wrong = $code === '999999' ? '999998' : '999999';
        for ($i = 0; $i < 4; $i++) $this->post(route('login.two-factor.verify'), ['code' => $wrong])->assertSessionHasErrors('code');
        $this->post(route('login.two-factor.verify'), ['code' => $wrong])->assertRedirect(route('login'));
        $this->post(route('login.two-factor.verify'), ['code' => $code])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_employee_can_turn_it_on_for_themselves(): void
    {
        Notification::fake();
        Livewire::actingAs($this->employee)->test(Profile::class)->assertSee('2-step sign-in')->call('toggleTwoFactor');
        $this->assertTrue($this->employee->fresh()->two_factor_enabled);
        auth()->logout();
        $this->post(route('login.store'), ['email' => 'emp@example.com', 'password' => 'Secret123!'])->assertRedirect(route('login.two-factor'));
    }

    public function test_apps_get_a_challenge_then_a_token_when_turned_on_for_apps(): void
    {
        Notification::fake();
        $this->setMode('everyone');
        $body = ['email' => 'emp@example.com', 'password' => 'Secret123!', 'device_name' => 'Phone'];
        $this->postJson('/api/auth/token', $body)->assertOk()->assertJsonStructure(['token']);

        $this->setMode('everyone', true);
        $challenge = $this->postJson('/api/auth/token', $body)->assertStatus(202)->assertJsonPath('otp_required', true)->json('challenge');
        $code = $this->codeFor($this->employee);
        $this->postJson('/api/auth/token', $body + ['challenge' => $challenge, 'otp' => '12'])->assertUnprocessable()->assertJsonValidationErrors('otp');
        $this->postJson('/api/auth/token', $body + ['challenge' => $challenge, 'otp' => $code])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_cannot_turn_on_without_email_and_the_command_turns_it_off(): void
    {
        app(\App\Services\PeopleOperationsService::class)->setPolicy('notifications.email', 'notifications', 'E-mail notifications', ['enabled' => false], null, $this->admin->id);
        $this->setMode('everyone')->assertHasErrors('twoFactorMode');

        app(\App\Services\PeopleOperationsService::class)->setPolicy('notifications.email', 'notifications', 'E-mail notifications', ['enabled' => true], null, $this->admin->id);
        $this->setMode('everyone')->assertHasNoErrors();
        $this->employee->forceFill(['two_factor_enabled' => true])->save();
        $this->artisan('security:two-factor-off')->assertSuccessful();
        $this->assertSame('off', app(TwoFactorService::class)->settings()['mode']);
        $this->assertFalse($this->employee->fresh()->two_factor_enabled);
    }
}

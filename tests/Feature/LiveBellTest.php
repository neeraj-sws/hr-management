<?php

namespace Tests\Feature;

use App\Livewire\Account\Profile;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** Website bell: updates by itself, tells what is new (popup + sound), and the sound can be turned off. */
class LiveBellTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-26 12:00:00');
        $this->user = User::factory()->create(['email' => 'bell@example.com', 'name' => 'Bella Bell']);
        $this->user->assignRole('Employee');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_live_check_returns_count_panel_and_only_new_notifications(): void
    {
        Notifier::send($this->user, 'TaskAssigned', 1, 'Old task for you.', '/work/tasks');
        $since = Carbon::now()->toIso8601String();
        Carbon::setTestNow('2026-10-26 12:00:40');
        Notifier::send($this->user, 'TaskAssigned', 2, 'Fresh task for you.', '/work/tasks');

        $data = $this->actingAs($this->user)->getJson(route('system.notifications.live', ['since' => $since]))->assertOk()->json();
        $this->assertSame(2, $data['count']);
        $this->assertCount(1, $data['new']);
        $this->assertStringContainsString('Fresh task', $data['new'][0]);
        $this->assertStringContainsString('2 unread', $data['panel']);
        $this->assertTrue($data['sound']);

        // Nothing new after the newest one the page has.
        $again = $this->actingAs($this->user)->getJson(route('system.notifications.live', ['since' => $data['since']]))->json();
        $this->assertSame([], $again['new']);
    }

    public function test_page_has_the_live_bell(): void
    {
        $this->actingAs($this->user)->get(route('account.profile'))->assertOk()->assertSee('swsBell(', false)->assertSee('Sound on the website');
    }

    public function test_sound_can_be_turned_off_and_the_app_keeps_that_choice(): void
    {
        Livewire::actingAs($this->user)->test(Profile::class)->set('webSound', false)->call('savePreferences');
        $this->assertFalse($this->user->fresh()->notification_preferences['web_sound']);
        $this->assertFalse($this->actingAs($this->user)->getJson(route('system.notifications.live'))->json('sound'));

        // Saving the other choices from the phone app does not switch the sound back on.
        Sanctum::actingAs($this->user->fresh());
        $this->postJson(route('api.notifications.preferences.save'), ['preferences' => ['tasks' => ['in_app' => true, 'email' => false, 'push' => true]]])->assertOk();
        $this->assertFalse($this->user->fresh()->notification_preferences['web_sound']);
    }

    public function test_guests_cannot_use_the_live_check(): void
    {
        $this->getJson(route('system.notifications.live'))->assertUnauthorized();
    }
}

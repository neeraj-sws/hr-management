<?php

namespace Tests\Feature;

use App\Livewire\Company\Announcements;
use App\Models\User;
use App\Notifications\AnnouncementEmail;
use App\Notifications\ApprovalRequested;
use App\Notifications\Channels\SafeMailChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** "Also send by e-mail" on an announcement: the full text goes to everyone in the audience, once. */
class AnnouncementEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticked_announcements_are_emailed_to_the_audience_and_others_stay_in_the_app(): void
    {
        $this->seed();
        Notification::fake();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $reader = User::factory()->create(['email' => 'reader@example.com']);
        $reader->assignRole('Employee');
        $optedIn = User::factory()->create(['email' => 'optin@example.com', 'notification_preferences' => ['company' => ['email' => true]]]);
        $optedIn->assignRole('Employee');
        $publish = fn (string $title, bool $email) => Livewire::actingAs($admin)->test(Announcements::class)->call('edit')
            ->set('title', $title)->set('body', "Office closed on Friday.\n\nEnjoy the long weekend.")->set('sendEmail', $email)->call('save')->assertHasNoErrors();

        $publish('Diwali holiday', true);
        Notification::assertSentTo($reader, AnnouncementEmail::class, fn (AnnouncementEmail $n, array $channels) => $channels === [SafeMailChannel::class]
            && $n->toMail($reader)->subject === config('app.name', 'SWS HR').' announcement: Diwali holiday'
            && $n->toMail($reader)->introLines === ['Office closed on Friday.', 'Enjoy the long weekend.']);
        Notification::assertSentTo($optedIn, ApprovalRequested::class, fn (ApprovalRequested $n, array $channels) => ! in_array(SafeMailChannel::class, $channels, true));
        Notification::assertSentToTimes($optedIn, AnnouncementEmail::class, 1);
        Notification::assertNotSentTo($admin, AnnouncementEmail::class);

        $publish('Canteen menu', false);
        Notification::assertSentToTimes($reader, AnnouncementEmail::class, 1);
        Notification::assertSentTo($reader, ApprovalRequested::class, fn (ApprovalRequested $n, array $channels) => $channels === ['database']);
        Notification::assertSentTo($optedIn, ApprovalRequested::class, fn (ApprovalRequested $n, array $channels) => in_array(SafeMailChannel::class, $channels, true));
    }
}

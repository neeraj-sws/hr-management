<?php

namespace Tests\Feature;

use App\Livewire\Leave\Requests;
use App\Models\AttendanceEmployee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Leave form: one day has one Date, several days have From and To; a fixed field loses its old error. */
class LeaveFormDaysTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_day_asks_one_date_and_several_days_ask_from_and_to(): void
    {
        $this->seed();
        Carbon::setTestNow('2026-10-24 10:00:00');
        $user = User::factory()->create(['email' => 'lf-1@example.com']);
        $user->assignRole('Employee');
        $e = AttendanceEmployee::create(['employee_code' => 'LF-1', 'name' => 'Person LF-1', 'email' => $user->email, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01']);
        $type = LeaveType::where('is_active', true)->firstOrFail();

        $form = Livewire::actingAs($user)->test(Requests::class)->call('create')
            ->assertSet('dayChoice', 'one')->assertSee('Full day — one day')->assertSeeHtml('<span>Date ')->assertDontSeeHtml('<span>To ');
        // Submitting without a type shows the error; choosing a type clears it at once.
        $form->set('reason', '')->call('submit')->assertHasErrors('leaveTypeId')
            ->set('leaveTypeId', $type->id)->assertHasNoErrors('leaveTypeId');
        $form->set('startsOn', '2026-10-28')->set('reason', 'Family function')->call('submit')->assertHasNoErrors();
        $one = LeaveRequest::where('employee_id', $e->id)->latest('id')->firstOrFail();
        $this->assertSame(['2026-10-28', '2026-10-28', 'full'], [$one->starts_on->toDateString(), $one->ends_on->toDateString(), $one->duration]);

        Livewire::actingAs($user)->test(Requests::class)->call('create')->set('dayChoice', 'several')->assertSeeHtml('<span>From ')->assertSeeHtml('<span>To ')
            ->set('leaveTypeId', $type->id)->set('startsOn', '2026-11-02')->set('endsOn', '2026-11-04')->set('reason', 'Trip')->call('submit')->assertHasNoErrors();
        $several = LeaveRequest::where('employee_id', $e->id)->latest('id')->firstOrFail();
        $this->assertSame(['2026-11-02', '2026-11-04'], [$several->starts_on->toDateString(), $several->ends_on->toDateString()]);

        Livewire::actingAs($user)->test(Requests::class)->call('create')->set('dayChoice', 'half')->assertSet('duration', 'half')
            ->set('leaveTypeId', $type->id)->set('startsOn', '2026-11-10')->set('reason', 'Doctor')->call('submit')->assertHasNoErrors();
        $this->assertSame('half', LeaveRequest::where('employee_id', $e->id)->latest('id')->first()->duration);
        Carbon::setTestNow();
    }
}

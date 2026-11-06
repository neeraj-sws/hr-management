<?php

namespace Tests\Feature;

use App\Livewire\Training\Courses;
use App\Livewire\Training\Fees;
use App\Livewire\Training\MyFees;
use App\Livewire\Training\MyTrainees;
use App\Models\AttendanceEmployee;
use App\Models\TrainingCourse;
use App\Models\TrainingEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Courses taught topic by topic, each by a mentor; two prices (at once / in installments); a course duration. */
class TrainingMentorsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-11-05 12:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function person(string $code, string $name, string $type = 'full_time'): AttendanceEmployee
    {
        $user = User::factory()->create(['email' => strtolower($code).'@example.com', 'name' => $name]);
        $user->assignRole('Employee');

        return AttendanceEmployee::create(['employee_code' => $code, 'name' => $name, 'email' => $user->email, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'employment_type' => $type]);
    }

    /** Web Development: 6 months, 20,000 in installments (usually 3) or 17,000 at once; HTML/CSS → JavaScript → React. */
    private function webDevelopment(AttendanceEmployee $risha, AttendanceEmployee $fahad, AttendanceEmployee $khushboo): TrainingCourse
    {
        Livewire::actingAs($this->admin)->test(Courses::class)
            ->call('edit')->set('title', 'Web Development')->set('durationValue', '6')->set('durationUnit', 'months')
            ->set('fee', '20000')->set('atOnceFee', '17000')->set('defaultInstallments', '3')
            ->call('addTopic')->set('topics.0.title', 'HTML & CSS')->call('addTopicMentor', 0, $risha->id)
            ->call('addTopic')->set('topics.1.title', 'JavaScript')->call('addTopicMentor', 1, $fahad->id)->call('addTopicMentor', 1, $khushboo->id)
            ->call('addTopic')->set('topics.2.title', 'React')->call('addTopicMentor', 2, $khushboo->id)
            ->call('save')->assertHasNoErrors()
            ->assertSee('Web Development')->assertSee('6 months')->assertSee('20,000.00')->assertSee('17,000.00')->assertSee('Fahad / Khushboo');

        return TrainingCourse::where('title', 'Web Development')->firstOrFail();
    }

    public function test_course_has_two_prices_duration_and_topics_with_mentors(): void
    {
        [$risha, $fahad, $khushboo] = [$this->person('M-1', 'Risha'), $this->person('M-2', 'Fahad'), $this->person('M-3', 'Khushboo')];
        $course = $this->webDevelopment($risha, $fahad, $khushboo);

        $this->assertSame([20000.0, 17000.0, 3000.0, 3, 6, 'months', '6 months'], [$course->installmentFee(), $course->atOnceFee(), (float) $course->one_time_discount, $course->default_installments, $course->duration_value, $course->duration_unit, $course->durationText()]);
        $this->assertSame(['HTML & CSS', 'JavaScript', 'React'], $course->topics->pluck('title')->all());
        $this->assertSame(['Fahad', 'Khushboo'], $course->topics[1]->mentors->pluck('name')->all());
        $this->assertSame('2027-04-30', $course->endDateFrom('2026-11-01'));

        // Editing keeps the topics and their mentors; a topic can be moved and removed.
        Livewire::actingAs($this->admin)->test(Courses::class)->call('edit', $course->id)
            ->assertSet('atOnceFee', '17000')->assertSet('durationValue', '6')->assertCount('topics', 3)
            ->call('moveTopic', 2, -1)->call('removeTopic', 2)->call('save')->assertHasNoErrors();
        $this->assertSame(['HTML & CSS', 'React'], $course->fresh()->topics->pluck('title')->all());
    }

    public function test_enrolment_picks_how_to_pay_mentors_per_topic_and_the_mentor_moves_the_trainee_on(): void
    {
        [$risha, $fahad, $khushboo] = [$this->person('M-1', 'Risha'), $this->person('M-2', 'Fahad'), $this->person('M-3', 'Khushboo')];
        $trainee = $this->person('T-1', 'Aman Trainee', 'trainee');
        $course = $this->webDevelopment($risha, $fahad, $khushboo);

        // In installments: the full fee in the usual 3; JavaScript has two mentors, so one is picked for this trainee.
        $page = Livewire::actingAs($this->admin)->test(Fees::class)
            ->call('enroll', null, $trainee->id)->set('startsOn', '2026-11-01')->set('feeCourseId', $course->id)
            // At once (the default): one payment of 17,000.
            ->assertSet('payMode', 'once')->assertSet('installments', '1')->assertSet('totalFee', '20000')->assertSet('discount', '3000')->assertSet('endsOn', '2027-04-30')
            ->assertCount('enrollTopics', 3)->assertSet('enrollTopics.0.mentor_id', (string) $risha->id)->assertSet('enrollTopics.1.mentor_id', '')
            // In installments: the full fee in the usual 3, or any other number.
            ->set('payMode', 'installments')->assertSet('installments', '3')->assertSet('discount', '0')->set('installments', '5')
            ->set('enrollTopics.1.mentor_id', (string) $khushboo->id)
            ->call('saveEnrollment')->assertHasNoErrors();
        $enrollment = TrainingEnrollment::where('employee_id', $trainee->id)->sole();
        $this->assertSame([20000.0, 5], [$enrollment->payable(), (int) $enrollment->installments]);
        $topics = $enrollment->topics()->get();
        $this->assertSame(['active', 'pending', 'pending'], $topics->pluck('status')->all());
        $this->assertSame([$risha->id, $khushboo->id, $khushboo->id], $topics->pluck('mentor_id')->all());
        $page->assertSee('Now:')->assertSee('HTML &amp; CSS', false)->assertSee('0 of 3 topics done');
        // Risha is told she has a new trainee.
        $this->assertTrue($risha->user->notifications()->get()->contains(fn ($n) => str_contains($n->data['message'] ?? '', 'New trainee for you: Aman Trainee for HTML & CSS')));

        // Risha sees the trainee (not the fee) and marks HTML & CSS done; Khushboo gets the trainee for JavaScript.
        Livewire::actingAs($risha->user)->test(MyTrainees::class)->assertSee('Aman Trainee')->assertSee('HTML &amp; CSS', false)->assertDontSee('20,000');
        $this->actingAs($risha->user)->get(route('training.mentoring'))->assertOk();
        // Fahad cannot mark Risha's topic done.
        Livewire::actingAs($fahad->user)->test(MyTrainees::class)->assertDontSee('Aman Trainee')->call('markDone', $topics[0]->id)->assertForbidden();
        Livewire::actingAs($risha->user)->test(MyTrainees::class)->call('markDone', $topics[0]->id)->assertOk();
        $this->assertSame(['completed', 'active', 'pending'], $enrollment->topics()->pluck('status')->all());
        $this->assertTrue($khushboo->user->notifications()->get()->contains(fn ($n) => str_contains($n->data['message'] ?? '', 'New trainee for you: Aman Trainee for JavaScript')));
        $this->assertTrue($trainee->user->notifications()->get()->contains(fn ($n) => str_contains($n->data['message'] ?? '', 'Next in Web Development: JavaScript with Khushboo')));
        Livewire::actingAs($khushboo->user)->test(MyTrainees::class)->assertSee('Aman Trainee')->set('show', 'next')->assertSee('Aman Trainee');

        // The trainee sees each topic with its mentor.
        Livewire::actingAs($trainee->user)->test(MyFees::class)->assertSee('Topics and mentors')->assertSee('Risha')->assertSee('Khushboo')->assertSee('Going on');

        // HR changes the JavaScript mentor to Fahad: Fahad is told.
        Livewire::actingAs($this->admin)->test(Fees::class)->call('enroll', $enrollment->id)
            ->assertSet('enrollTopics.1.status', 'active')->set('enrollTopics.1.mentor_id', (string) $fahad->id)->call('saveEnrollment')->assertHasNoErrors();
        $this->assertSame($fahad->id, $enrollment->topics()->where('status', 'active')->value('mentor_id'));
        $this->assertTrue($fahad->user->notifications()->get()->contains(fn ($n) => str_contains($n->data['message'] ?? '', 'for JavaScript')));
        Livewire::actingAs($this->admin)->test(Fees::class)->set('mentorFilter', (string) $fahad->id)->assertSee('Aman Trainee')
            ->set('mentorFilter', (string) $risha->id)->assertDontSee('Aman Trainee');
    }

    public function test_menu_shows_my_trainees_only_to_mentors_and_hr(): void
    {
        $risha = $this->person('M-1', 'Risha');
        $other = $this->person('M-9', 'Someone');
        $this->webDevelopment($risha, $this->person('M-2', 'Fahad'), $this->person('M-3', 'Khushboo'));

        $this->assertTrue(\App\Support\Navigation::canAccess($risha->user, 'training.mentoring'));
        $this->assertFalse(\App\Support\Navigation::canAccess($other->user, 'training.mentoring'));
        $this->assertTrue(\App\Support\Navigation::canAccess($this->admin, 'training.mentoring'));
    }
}

<?php

namespace Tests\Feature;

use App\Livewire\Performance\Goals;
use App\Livewire\Performance\Reviews;
use App\Models\AttendanceEmployee;
use App\Models\PerformanceFeedback;
use App\Models\PerformanceGoal;
use App\Models\PerformanceReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Goals (KRA / OKR), self-review, 360 feedback and the manager's final review. */
class PerformanceGoalsAndFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private User $managerUser;
    private User $employeeUser;
    private User $peer1;
    private User $peer2;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.goals' => true]); // Goals are hidden in the app for now; these tests switch them on.
        $this->seed();
        Carbon::setTestNow('2026-10-23 10:00:00');
        $make = function (string $code, string $name, array $roles, ?int $managerId = null) {
            $user = User::factory()->create(['name' => $name]);
            $user->assignRole($roles);
            $employee = AttendanceEmployee::create(['employee_code' => $code, 'name' => $name, 'is_active' => true, 'user_id' => $user->id, 'manager_id' => $managerId, 'joined_at' => '2026-01-01']);

            return [$user, $employee];
        };
        [$this->managerUser, $manager] = $make('PM-1', 'Mona Manager', ['Employee', 'Manager']);
        [$this->employeeUser, $this->employee] = $make('PE-1', 'Vaibhav Marketer', ['Employee'], $manager->id);
        [$this->peer1] = $make('PE-2', 'Pooja Peer', ['Employee'], $manager->id);
        [$this->peer2] = $make('PE-3', 'Paras Peer', ['Employee'], $manager->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_manager_sets_goals_employee_proposes_one_and_updates_progress(): void
    {
        Livewire::actingAs($this->managerUser)->test(Goals::class)->assertSet('period', '2026-Q3')->set('tab', 'team')
            ->call('create', $this->employee->id)->assertSet('goal.starts_on', '2026-10-01')->assertSet('goal.ends_on', '2026-12-31')
            ->set('goal.title', 'Qualified leads from social media')->set('goal.target', '20 leads a month')->set('goal.weight', '60')->set('goal.kind', 'okr')
            ->call('save')->assertHasNoErrors()->assertSee('Qualified leads from social media')->assertSee('weights add up to 60%, not 100%');
        $this->assertSame('active', PerformanceGoal::firstOrFail()->status);

        // The employee proposes a goal; it waits for the manager.
        Livewire::actingAs($this->employeeUser)->test(Goals::class)->assertSet('tab', 'mine')
            ->call('create')->set('goal.title', 'Run 2 webinars')->set('goal.weight', '40')->call('save')->assertHasNoErrors()->assertSee('proposed');
        $proposed = PerformanceGoal::where('title', 'Run 2 webinars')->firstOrFail();
        $this->assertSame('proposed', $proposed->status);
        $this->assertTrue($this->managerUser->notifications()->get()->contains(fn ($n) => str_contains($n->data['message'] ?? '', 'proposed a goal')));

        Livewire::actingAs($this->managerUser)->test(Goals::class)->set('tab', 'team')->assertSee('1 to approve')->call('approve', $proposed->id)->assertDontSee('weights add up');

        // Progress with a note, by the employee.
        $goal = PerformanceGoal::where('title', 'Qualified leads from social media')->firstOrFail();
        Livewire::actingAs($this->employeeUser)->test(Goals::class)->call('openProgress', $goal->id)->set('progress', 70)->set('progressNote', '14 leads from Instagram')->call('saveProgress')->assertHasNoErrors()->assertSee('70%');
        $this->assertSame(70, $goal->fresh()->progress);
        $this->assertSame('14 leads from Instagram', $goal->updates()->first()->note);

        // Someone outside the team cannot change it.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Services\PerformanceService::class)->updateProgress($this->peer1, $goal, 10, null);
    }

    public function test_self_review_360_feedback_and_final_review(): void
    {
        $goal = PerformanceGoal::create(['employee_id' => $this->employee->id, 'kind' => 'kra', 'title' => 'Campaign delivery', 'weight' => 100, 'progress' => 80, 'status' => 'active', 'starts_on' => '2026-07-01', 'ends_on' => '2026-09-30']);

        Livewire::actingAs($this->managerUser)->test(Reviews::class)->call('create')
            ->assertSet('periodStart', '2026-07-01')->assertSet('periodEnd', '2026-09-30')
            ->set('employeeId', $this->employee->id)->set('mode', 'cycle')->set('feedbackFrom', [$this->peer1->id, $this->peer2->id])
            ->call('save')->assertHasNoErrors();
        $review = PerformanceReview::firstOrFail();
        $this->assertSame('self_review', $review->status);
        $this->assertSame(['peer', 'peer'], $review->feedback->pluck('relationship')->all());

        // Self-review.
        Livewire::actingAs($this->employeeUser)->test(Reviews::class)->assertSee('Write self-review')->call('openSelf', $review->id)
            ->assertSee('Campaign delivery')->set('selfRating', 4)->set('selfAssessment', 'Delivered all campaigns, two were late because of design.')->call('submitSelf')->assertHasNoErrors();
        $this->assertSame('manager_review', $review->fresh()->status);

        // 360 feedback from both peers.
        foreach ([[$this->peer1, 5, 'Very helpful in launches'], [$this->peer2, 3, 'Good ideas']] as [$peer, $rating, $text]) {
            $request = PerformanceFeedback::where('giver_id', $peer->id)->firstOrFail();
            Livewire::actingAs($peer)->test(Reviews::class)->set('tab', 'feedback')->assertSee('Vaibhav Marketer')->call('openFeedback', $request->id)
                ->set('feedbackRating', $rating)->set('feedbackStrengths', $text)->set('feedbackImprovements', 'Share plans earlier')->call('submitFeedback')->assertHasNoErrors();
        }

        // The manager sees names, scores the goal and shares.
        Livewire::actingAs($this->managerUser)->test(Reviews::class)->set('tab', 'team')->assertSee('Pooja Peer')->assertSee('Delivered all campaigns')
            ->call('openComplete', $review->id)->assertSee('average 4/5 from 2')->set("goalScores.{$goal->id}", '4')->set('rating', 4)->set('strengths', 'Owns campaigns end to end')->call('complete')->assertHasNoErrors();
        $review->refresh();
        $this->assertSame('submitted', $review->status);
        $this->assertSame(4.0, $review->goalScore());

        // The employee sees the feedback together and without names.
        Livewire::actingAs($this->employeeUser)->test(Reviews::class)->assertSee('Feedback from 2 colleagues')->assertSee('Very helpful in launches')->assertDontSee('Pooja Peer')->assertSee('goal score 4/5')
            ->call('openAcknowledge', $review->id)->call('acknowledge');
        $this->assertSame('acknowledged', $review->fresh()->status);
        $this->getJson('/api/my/performance')->assertOk();
    }

    public function test_one_answer_is_not_shown_to_the_employee_and_direct_review_still_works(): void
    {
        Livewire::actingAs($this->managerUser)->test(Reviews::class)->call('create')->set('employeeId', $this->employee->id)->set('feedbackFrom', [$this->peer1->id])->call('save');
        $review = PerformanceReview::firstOrFail();
        app(\App\Services\PerformanceService::class)->giveFeedback($this->peer1, $review->feedback()->first(), 2, 'Quiet in meetings', null);
        Livewire::actingAs($this->managerUser)->test(Reviews::class)->set('tab', 'team')->call('openComplete', $review->id)->set('strengths', 'Reliable')->call('complete')->assertHasNoErrors();
        Livewire::actingAs($this->employeeUser)->test(Reviews::class)->assertDontSee('Quiet in meetings')->assertDontSee('Feedback from');

        // Writing a review straight away: no self-review asked.
        Livewire::actingAs($this->managerUser)->test(Reviews::class)->call('create')->set('employeeId', $this->employee->id)->set('mode', 'direct')->call('save')
            ->assertSet('completeId', PerformanceReview::latest('id')->value('id'))->set('rating', 5)->set('strengths', 'Great quarter')->call('complete')->assertHasNoErrors();
        $this->assertSame([5, 'submitted'], [PerformanceReview::latest('id')->first()->rating, PerformanceReview::latest('id')->first()->status]);
    }

    public function test_goals_are_hidden_when_switched_off(): void
    {
        config(['features.goals' => false]);
        $admin = \App\Models\User::where('email', 'admin@example.com')->firstOrFail();
        $this->actingAs($admin)->get(route('performance.goals'))->assertNotFound();
        $this->actingAs($admin)->get(route('dashboard'))->assertDontSee('Goals (KRA / OKR)');
    }
}

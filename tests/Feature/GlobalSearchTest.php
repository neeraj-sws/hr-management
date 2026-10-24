<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\DailyWorkStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Header search: grouped results, only what the person may see, pages, "did you mean". */
class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $empUser;
    private AttendanceEmployee $risha;
    private AttendanceEmployee $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $boss = AttendanceEmployee::create(['employee_code' => 'GS-M', 'name' => 'Mona Boss', 'is_active' => true, 'joined_at' => '2026-01-01']);
        $this->empUser = User::factory()->create(['name' => 'Risha Gangrade']);
        $this->empUser->assignRole('Employee');
        $this->risha = AttendanceEmployee::create(['employee_code' => 'EMP-07', 'name' => 'Risha Gangrade', 'is_active' => true, 'user_id' => $this->empUser->id, 'manager_id' => $boss->id, 'joined_at' => '2026-01-01']);
        AttendanceEmployee::create(['employee_code' => 'GS-2', 'name' => 'Rishabh Teammate', 'is_active' => true, 'manager_id' => $boss->id, 'joined_at' => '2026-01-01']);
        $this->stranger = AttendanceEmployee::create(['employee_code' => 'GS-9', 'name' => 'Rishi Stranger', 'is_active' => true, 'joined_at' => '2026-01-01']);
        Task::create(['title' => 'Mobile APK bug fixing', 'assignee_id' => $this->risha->id, 'status' => 'in_progress', 'priority' => 'normal']);
        Task::create(['title' => 'Mobile secret for stranger', 'assignee_id' => $this->stranger->id, 'status' => 'todo', 'priority' => 'normal']);
        DailyWorkStatus::create(['employee_id' => $this->stranger->id, 'work_date' => '2026-10-20', 'summary' => 'Mobile stranger status', 'submitted_at' => now()]);
        Project::create(['name' => 'Mobile Hidden Project', 'status' => 'active']);
    }

    private function search(string $q, string $type = 'all'): array
    {
        return $this->getJson('/search/suggest?'.http_build_query(['q' => $q, 'type' => $type]))->assertOk()->json();
    }

    public function test_employee_sees_team_mates_but_not_other_teams(): void
    {
        $this->actingAs($this->empUser);
        $people = collect($this->search('rish')['groups']['people']['items'])->pluck('title');
        $this->assertTrue($people->contains('Risha Gangrade'));
        $this->assertTrue($people->contains('Rishabh Teammate'), 'Same manager = team-mate');
        $this->assertFalse($people->contains('Rishi Stranger'));

        $result = $this->search('mobile');
        $this->assertSame(['Mobile APK bug fixing'], collect($result['groups']['tasks']['items'])->pluck('title')->all());
        $this->assertArrayNotHasKey('status', $result['groups'], "Another team's daily status is never found");
        $this->assertArrayNotHasKey('projects', $result['groups'], 'Not a member of that project');
        $this->assertArrayNotHasKey('notes', $result['groups'], 'Groups with no results are left out');
    }

    public function test_hr_sees_everyone_pages_and_did_you_mean(): void
    {
        $this->actingAs($this->admin);
        $result = $this->search('mobile');
        $this->assertSame(2, $result['groups']['tasks']['total']);
        $this->assertSame(1, $result['groups']['status']['total']);
        $this->assertSame('Mobile Hidden Project', $result['groups']['projects']['items'][0]['title']);
        $this->assertNotNull($this->search('risha')['groups']['people']['items'][0]['status'], 'Live status for HR');

        $pages = collect($this->search('sal')['groups']['pages']['items'] ?? [])->pluck('title');
        $this->assertTrue($pages->contains(fn ($t) => str_contains(strtolower($t), 'sal')));

        $none = $this->search('rishaa');
        $this->assertSame([], $none['groups']);
        $this->assertSame('Risha', $none['did_you_mean']);
        $this->assertSame(1, count($this->search('mobile', 'tasks')['groups']), 'type= returns one group');
    }

    public function test_api_and_full_page(): void
    {
        Sanctum::actingAs($this->empUser, ['*']);
        $this->getJson('/api/search?q=risha')->assertOk()->assertJsonPath('groups.people.items.0.title', 'Risha Gangrade');
        $this->actingAs($this->empUser)->get('/search?q=mobile&type=tasks')->assertOk()->assertSee('<mark>Mobile</mark> APK bug fixing', false)->assertDontSee('secret for stranger');
        $this->actingAs($this->empUser)->get('/dashboard')->assertSee('Search people, tasks, pages');
    }
}

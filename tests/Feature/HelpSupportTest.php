<?php

namespace Tests\Feature;

use App\Livewire\Support\Desk;
use App\Livewire\Support\MyRequests;
use App\Livewire\Support\Reports;
use App\Livewire\Support\Settings;
use App\Livewire\Support\TicketShow;
use App\Models\AttendanceEmployee;
use App\Models\AuditLog;
use App\Models\SupportAttachment;
use App\Models\SupportCategory;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\SupportService;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Help & Support: requests, chat, files, roles / permissions, visibility, history and notifications. */
class HelpSupportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $hr;
    private User $hr2;
    private User $staff;
    private User $other;
    private AttendanceEmployee $employee;
    private AttendanceEmployee $otherEmployee;
    private SupportService $support;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        Carbon::setTestNow('2026-11-03 10:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->hr = User::factory()->create(['name' => 'Hema HR', 'email' => 'hema@company.test']);
        $this->hr->assignRole('HR');
        $this->hr2 = User::factory()->create(['name' => 'Harsh HR', 'email' => 'harsh@company.test']);
        $this->hr2->assignRole('HR');
        $this->staff = User::factory()->create(['name' => 'Riya Sharma', 'email' => 'riya@company.test']);
        $this->staff->assignRole('Employee');
        $this->other = User::factory()->create(['name' => 'Aman Verma', 'email' => 'aman@company.test']);
        $this->other->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'SR-E1', 'name' => 'Riya Sharma', 'is_active' => true, 'user_id' => $this->staff->id]);
        $this->otherEmployee = AttendanceEmployee::create(['employee_code' => 'SR-E2', 'name' => 'Aman Verma', 'is_active' => true, 'user_id' => $this->other->id]);
        $this->support = app(SupportService::class);
    }

    private function category(string $slug): SupportCategory
    {
        return SupportCategory::where('slug', $slug)->firstOrFail();
    }

    private function raise(string $slug = 'attendance', array $files = [], ?User $as = null, ?AttendanceEmployee $employee = null): SupportTicket
    {
        $this->actingAs($as ?? $this->staff);

        return $this->support->create($employee ?? $this->employee, $as ?? $this->staff, ['category_id' => $this->category($slug)->id, 'subject' => 'Check-out not saved', 'description' => 'I checked out at 7 PM but it shows working.'], $files);
    }

    private function notes(User $user): \Illuminate\Support\Collection
    {
        return $user->notifications()->get()->pluck('data.message');
    }

    public function test_permissions_are_seeded_and_the_migration_grants_them_on_existing_installs(): void
    {
        $this->assertTrue(Role::findByName('Master Admin')->hasPermissionTo('support.manage'));
        $this->assertTrue(Role::findByName('Master Admin')->hasPermissionTo('support.payroll'));
        $hr = Role::findByName('HR');
        $this->assertTrue($hr->hasPermissionTo('support.handle'));
        $this->assertFalse($hr->hasPermissionTo('support.manage'));
        $this->assertFalse($hr->hasPermissionTo('support.payroll'), 'HR does not see payroll tickets by default');
        $this->assertFalse(Role::findByName('Employee')->hasPermissionTo('support.handle'));
        $this->assertSame(8, SupportCategory::count());
        $this->assertTrue($this->category('payroll')->is_restricted);

        // An install from before this update: the permissions are missing, the migration adds them (and does not duplicate).
        DB::table('role_has_permissions')->whereIn('permission_id', DB::table('permissions')->where('name', 'like', 'support.%')->pluck('id'))->delete();
        DB::table('permissions')->where('name', 'like', 'support.%')->delete();
        $migration = require database_path('migrations/2026_11_03_000002_create_support_tables.php');
        $grant = new \ReflectionMethod($migration, 'grantPermissions');
        $grant->invoke($migration);
        $grant->invoke($migration);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertSame(3, DB::table('permissions')->where('name', 'like', 'support.%')->count());
        $this->assertTrue($this->admin->fresh()->can('support.manage'));
        $this->assertTrue($this->hr->fresh()->can('support.handle'));
        $this->assertFalse($this->hr->fresh()->can('support.payroll'));
    }

    public function test_menu_and_routes_follow_the_permissions(): void
    {
        $routes = fn (User $u) => collect(Navigation::for($u))->flatMap(fn ($g) => $g['items'])->pluck('route');
        $this->assertTrue($routes($this->staff)->contains('support.my'));
        $this->assertFalse($routes($this->staff)->contains('support.desk'));
        $this->assertTrue($routes($this->hr)->contains('support.desk'));
        $this->assertFalse($routes($this->hr)->contains('support.reports'));
        $this->assertTrue($routes($this->admin)->contains('support.reports'));
        $this->assertTrue($routes($this->admin)->contains('support.settings'));

        $this->actingAs($this->staff);
        $this->get('/support')->assertOk()->assertSee('New request');
        foreach (['/support/desk', '/support/reports', '/support/settings'] as $url) $this->get($url)->assertForbidden();
        $this->actingAs($this->hr);
        $this->get('/support/desk')->assertOk();
        $this->get('/support/reports')->assertForbidden();
        $this->actingAs($this->admin);
        foreach (['/support/desk', '/support/reports', '/support/settings'] as $url) $this->get($url)->assertOk();
    }

    public function test_employee_raises_a_request_with_files_and_support_is_told(): void
    {
        Livewire::actingAs($this->staff)->test(MyRequests::class)
            ->call('openForm')
            ->set('category_id', $this->category('attendance')->id)
            ->set('subject', 'Check-out not saved')
            ->set('description', 'I checked out at 7 PM but it still shows working.')
            ->set('attachments', [$this->fakeImage('screen.jpg'), UploadedFile::fake()->create('proof.pdf', 120, 'application/pdf')])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $ticket = SupportTicket::firstOrFail();
        $this->assertSame(['open', 'medium', $this->employee->id], [$ticket->status, $ticket->priority, $ticket->employee_id]);
        $this->assertSame(2, $ticket->attachments()->count());
        foreach ($ticket->attachments as $a) {
            Storage::disk('local')->assertExists($a->storage_path);
            $this->assertStringStartsWith('support-attachments/'.$ticket->id.'/', $a->storage_path);
        }
        $this->assertSame('created', $ticket->events()->value('type'));
        foreach ([$this->admin, $this->hr, $this->hr2] as $handler) $this->assertCount(1, $this->notes($handler), $handler->name.' is told once');
        $this->assertStringContainsString('Riya Sharma raised a support request '.$ticket->number(), $this->notes($this->admin)->first());
        $this->assertCount(0, $this->notes($this->staff), 'The person who raised it is not notified');
        $this->assertSame('/support/requests/'.$ticket->id, $this->admin->notifications()->first()->data['url']);

        $this->actingAs($this->staff)->get('/support')->assertOk()->assertSee($ticket->number())->assertSee('Check-out not saved');
    }

    public function test_files_must_be_images_or_pdf_up_to_5_mb(): void
    {
        $this->expectException(ValidationException::class);
        $this->raise('attendance', [UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')]);
    }

    public function test_large_files_are_refused(): void
    {
        try {
            $this->raise('attendance', [UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')]);
            $this->fail('A 6 MB file was accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('5 MB', collect($e->errors())->flatten()->join(' '));
        }
        $this->assertSame(0, SupportTicket::count());
    }

    public function test_employees_see_only_their_own_requests_and_files(): void
    {
        $ticket = $this->raise('attendance', [$this->fakeImage('mine.jpg')]);
        $attachment = SupportAttachment::firstOrFail();

        $this->actingAs($this->other);
        $this->get('/support/requests/'.$ticket->id)->assertForbidden();
        $this->get('/support/attachments/'.$attachment->id)->assertForbidden();
        $this->get('/support')->assertOk()->assertDontSee($ticket->number());

        $this->actingAs($this->staff);
        $this->get('/support/requests/'.$ticket->id)->assertOk()->assertSee('I checked out at 7 PM');
        $this->get('/support/attachments/'.$attachment->id)->assertOk();
        $this->actingAs($this->hr)->get('/support/attachments/'.$attachment->id)->assertOk();
    }

    public function test_internal_notes_are_never_shown_to_the_employee(): void
    {
        $ticket = $this->raise();
        $this->actingAs($this->admin);
        $this->support->assign($ticket, $this->admin, $this->hr->id);
        Carbon::setTestNow('2026-11-03 10:05:00');
        $this->support->reply($ticket->refresh(), $this->admin, 'Secret: check the device log first', [$this->fakeImage('internal.jpg')], internal: true);
        $internalFile = SupportAttachment::latest('id')->firstOrFail();

        $this->assertFalse(collect($this->notes($this->staff))->contains(fn ($m) => str_contains($m, 'Secret')), 'Employee is not told about internal notes');
        $this->assertTrue(collect($this->notes($this->hr))->contains(fn ($m) => str_contains($m, 'internal note')), 'Assignee hears about the note');
        $this->assertSame([], $this->support->unreadIds($this->staff, [$ticket->id], false), 'An internal note is not an unread message for the employee');

        $this->actingAs($this->staff)->get('/support/requests/'.$ticket->id)->assertOk()->assertDontSee('Secret: check the device log first')->assertDontSee('Internal note');
        $this->get('/support/attachments/'.$internalFile->id)->assertForbidden();
        Sanctum::actingAs($this->staff);
        $json = $this->getJson('/api/support/tickets/'.$ticket->id)->assertOk()->json('data');
        $this->assertStringNotContainsString('Secret', json_encode($json));

        $this->actingAs($this->hr)->get('/support/requests/'.$ticket->id)->assertOk()->assertSee('Secret: check the device log first')->assertSee('Internal note');
    }

    public function test_assignment_history_and_handler_visibility(): void
    {
        $ticket = $this->raise();
        $this->actingAs($this->admin);
        Livewire::test(TicketShow::class, ['ticket' => $ticket])->set('assignee', (string) $this->hr->id)->call('saveAssignee')->assertHasNoErrors();
        $ticket->refresh();
        $this->assertSame([$this->hr->id, 'assigned'], [(int) $ticket->assigned_to, $ticket->status]);
        $event = $ticket->events()->where('type', 'assigned')->firstOrFail();
        $this->assertSame([$this->admin->id, null, (string) $this->hr->id], [$event->user_id, $event->from_value, $event->to_value]);
        $this->assertTrue($this->notes($this->hr)->contains(fn ($m) => str_contains($m, 'assigned support request '.$ticket->number().' to you')));
        $this->assertTrue(AuditLog::where('action', 'support.assigned')->exists());

        // Reassigned: history keeps both, the previous handler is told.
        Carbon::setTestNow('2026-11-03 11:00:00');
        $this->support->assign($ticket, $this->admin, $this->hr2->id);
        $this->assertSame(2, $ticket->events()->where('type', 'assigned')->count());
        $this->assertTrue($this->notes($this->hr)->contains(fn ($m) => str_contains($m, 'from you to Harsh HR')));
        $this->assertStringContainsString('Assigned: Hema HR → Harsh HR', $this->support->history($ticket)->pluck('text')->join('|'));

        // Hema (a handler) no longer sees it; Harsh does. A handler cannot assign to someone else.
        $this->actingAs($this->hr)->get('/support/requests/'.$ticket->id)->assertForbidden();
        $this->actingAs($this->hr2)->get('/support/requests/'.$ticket->id)->assertOk();
        Livewire::actingAs($this->hr)->test(Desk::class)->assertDontSee($ticket->number());
        Livewire::actingAs($this->hr2)->test(Desk::class)->call('showTab', 'mine')->assertSee($ticket->number());

        $second = $this->raise();
        try {
            $this->support->assign($second, $this->hr, $this->hr2->id);
            $this->fail('A handler assigned a request to someone else');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        Livewire::actingAs($this->hr)->test(TicketShow::class, ['ticket' => $second])->call('takeIt');
        $this->assertSame($this->hr->id, (int) $second->fresh()->assigned_to);
    }

    public function test_payroll_requests_are_hidden_from_hr_without_the_payroll_permission(): void
    {
        $ticket = $this->raise('payroll');
        $this->assertCount(0, $this->notes($this->hr), 'HR is not told about a payroll request');
        $this->assertCount(1, $this->notes($this->admin));
        $this->actingAs($this->hr)->get('/support/requests/'.$ticket->id)->assertForbidden();
        Livewire::actingAs($this->hr)->test(Desk::class)->assertDontSee($ticket->number());
        $this->assertFalse($this->support->handlers($ticket)->contains('id', $this->hr->id), 'HR is not offered as the assignee');
        $this->actingAs($this->admin);
        $this->expectException(ValidationException::class);
        $this->support->assign($ticket, $this->admin, $this->hr->id);
    }

    public function test_payroll_permission_given_later_without_code_changes(): void
    {
        $ticket = $this->raise('payroll');
        $this->hr->givePermissionTo('support.payroll');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($this->hr->fresh())->get('/support/requests/'.$ticket->id)->assertOk();
        $this->assertTrue($this->support->handlers($ticket)->contains('id', $this->hr->id));
    }

    public function test_status_flow_reply_notifications_and_reopen(): void
    {
        $ticket = $this->raise();
        $this->actingAs($this->admin);
        $this->support->assign($ticket, $this->admin, $this->admin->id);
        $before = $this->staff->notifications()->count();

        // A reply with a status change: one notification for the employee, not two.
        Carbon::setTestNow('2026-11-03 10:10:00');
        Livewire::test(TicketShow::class, ['ticket' => $ticket->refresh()])->set('body', 'Which laptop did you use?')->set('replyStatus', 'waiting_for_employee')->call('send')->assertHasNoErrors();
        $ticket->refresh();
        $this->assertSame('waiting_for_employee', $ticket->status);
        $this->assertNotNull($ticket->first_response_at);
        $this->assertSame($before + 1, $this->staff->notifications()->count());
        $this->assertStringContainsString('Status: Waiting for employee', $this->staff->notifications()->first()->data['message']);
        $this->assertSame([$ticket->id], $this->support->unreadIds($this->staff, [$ticket->id], false), 'The reply is unread for the employee');

        // The employee answers: back to In progress, the assignee is told.
        Carbon::setTestNow('2026-11-03 10:20:00');
        $adminBefore = $this->admin->notifications()->count();
        Livewire::actingAs($this->staff)->test(TicketShow::class, ['ticket' => $ticket])->set('body', 'The office laptop.')->call('send')->assertHasNoErrors();
        $this->assertSame([], $this->support->unreadIds($this->staff, [$ticket->id], false), 'Opening the request marks it read');
        $this->assertSame('in_progress', $ticket->fresh()->status);
        $this->assertSame($adminBefore + 1, $this->admin->notifications()->count());

        // Employees cannot set a status or write internal notes.
        try {
            $this->support->reply($ticket->fresh(), $this->staff, 'x', [], internal: true);
            $this->fail('Employee wrote an internal note');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // Resolved (never automatically): told with the reopen date; reopen within the days.
        Carbon::setTestNow('2026-11-03 12:00:00');
        $this->actingAs($this->admin);
        $this->assertTrue($this->support->setStatus($ticket->fresh(), $this->admin, 'resolved'));
        $ticket->refresh();
        $this->assertNotNull($ticket->resolved_at);
        $this->assertStringContainsString('Reopen it by 10 Nov 2026', $this->staff->notifications()->latest('created_at')->first()->data['message']);
        $this->assertSame(['open', 'assigned', 'waiting_for_employee', 'in_progress', 'resolved'], $ticket->events()->whereIn('type', ['created', 'status'])->orderBy('id')->pluck('to_value')->all());

        $this->actingAs($this->staff);
        try {
            $this->support->reply($ticket, $this->staff, 'Still broken');
            $this->fail('Replied on a resolved request');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Reopen', collect($e->errors())->flatten()->join(' '));
        }
        Carbon::setTestNow('2026-11-05 09:00:00');
        Livewire::actingAs($this->staff)->test(TicketShow::class, ['ticket' => $ticket])->call('openReopen')->set('reopenReason', 'It happened again today.')->call('reopen')->assertHasNoErrors();
        $ticket->refresh();
        $this->assertSame(['assigned', 1, null], [$ticket->status, $ticket->reopen_count, $ticket->resolved_at]);
        $this->assertTrue($ticket->events()->where('type', 'reopened')->exists());
        $this->assertTrue($this->notes($this->admin)->contains(fn ($m) => str_contains($m, 'reopened support request')));

        // After the reopen days: no more reopening; closed: no more employee messages.
        $this->actingAs($this->admin);
        $this->support->setStatus($ticket, $this->admin, 'resolved');
        Carbon::setTestNow('2026-11-20 09:00:00');
        $this->assertFalse($this->support->canReopen($ticket->fresh()));
        $this->actingAs($this->staff);
        try {
            $this->support->reopen($ticket->fresh(), $this->staff, 'Again');
            $this->fail('Reopened after the reopen days');
        } catch (ValidationException) {
        }
        $this->actingAs($this->admin);
        $this->support->setStatus($ticket->fresh(), $this->admin, 'closed');
        $this->actingAs($this->staff);
        $this->expectException(ValidationException::class);
        $this->support->reply($ticket->fresh(), $this->staff, 'Hello?');
    }

    public function test_reopen_days_are_set_by_the_admin(): void
    {
        Livewire::actingAs($this->admin)->test(Settings::class)->set('reopenDays', 0)->call('saveReopen')->assertHasNoErrors();
        $this->assertSame(0, $this->support->reopenDays());
        $ticket = $this->raise();
        $this->actingAs($this->admin);
        $this->support->setStatus($ticket, $this->admin, 'resolved');
        $this->assertFalse($this->support->canReopen($ticket->fresh()), '0 days: the employee cannot reopen');
        $this->assertTrue(AuditLog::where('action', 'support.settings_updated')->exists());
    }

    public function test_admin_manages_categories_and_handlers_cannot(): void
    {
        Livewire::actingAs($this->admin)->test(Settings::class)->call('create')->set('name', 'Assets & Laptop')->set('description', 'Laptop, mouse, ID card')->call('save')->assertHasNoErrors();
        $category = SupportCategory::where('name', 'Assets & Laptop')->firstOrFail();
        $this->assertTrue($category->is_active);
        Livewire::actingAs($this->admin)->test(Settings::class)->call('create')->set('name', 'assets & laptop')->call('save')->assertHasErrors('name');
        Livewire::actingAs($this->admin)->test(Settings::class)->call('toggle', $category->id);
        $this->assertFalse($category->fresh()->is_active);
        $this->assertFalse($this->support->categories()->contains('id', $category->id), 'A switched-off category is not offered to employees');

        $this->actingAs($this->hr);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->support->saveCategory(null, ['name' => 'Sneaky'], $this->hr);
    }

    public function test_admin_hides_a_message_with_an_audit_record_and_messages_cannot_be_edited(): void
    {
        $ticket = $this->raise();
        $message = $ticket->messages()->firstOrFail();
        $this->actingAs($this->hr);
        try {
            $this->support->hideMessage($message, $this->hr, 'not allowed');
            $this->fail('A handler hid a message');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
        }
        Livewire::actingAs($this->admin)->test(TicketShow::class, ['ticket' => $ticket])->call('openHide', $message->id)->set('hideReason', 'Shared a password')->call('hide')->assertHasNoErrors();
        $message->refresh();
        $this->assertNotNull($message->hidden_at);
        $this->assertSame('I checked out at 7 PM but it shows working.', $message->body, 'The text is kept');
        $log = AuditLog::where('action', 'support.message_hidden')->firstOrFail();
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame('Shared a password', $log->metadata['reason']);
        $this->assertTrue($ticket->events()->where('type', 'message_hidden')->exists());

        $this->actingAs($this->staff)->get('/support/requests/'.$ticket->id)->assertOk()->assertSee('removed by the support admin')->assertDontSee('I checked out at 7 PM but it shows working.');
        // The component has no edit / delete action for messages.
        $this->assertFalse(method_exists(TicketShow::class, 'deleteMessage') || method_exists(TicketShow::class, 'editMessage'));
    }

    public function test_priority_and_category_changes_are_in_the_history(): void
    {
        $ticket = $this->raise();
        Livewire::actingAs($this->admin)->test(TicketShow::class, ['ticket' => $ticket])
            ->set('priority', 'urgent')->call('savePriority')
            ->set('category', (string) $this->category('technical')->id)->call('saveCategory')->assertHasNoErrors();
        $ticket->refresh();
        $this->assertSame(['urgent', $this->category('technical')->id], [$ticket->priority, $ticket->category_id]);
        $text = $this->support->history($ticket)->pluck('text')->join('|');
        $this->assertStringContainsString('Priority: Medium → Urgent', $text);
        $this->assertStringContainsString('Category: Attendance & Check-in/Check-out → Technical Issues', $text);
        $this->assertFalse($this->notes($this->staff)->contains(fn ($m) => str_contains($m, 'Priority')), 'Priority is internal');
    }

    public function test_desk_tabs_search_filters_and_sorting(): void
    {
        $a = $this->raise();
        Carbon::setTestNow('2026-11-03 10:30:00');
        $b = $this->raise('leave', [], $this->other, $this->otherEmployee);
        $b->update(['subject' => 'Leave balance wrong']);
        $this->actingAs($this->admin);
        $this->support->assign($b, $this->admin, $this->admin->id);
        $this->support->setPriority($b->fresh(), $this->admin, 'high');

        Livewire::actingAs($this->admin)->test(Desk::class)
            ->assertSee($a->number())->assertSee($b->number())
            ->call('showTab', 'new')->assertSee($a->number())->assertDontSee($b->number())
            ->call('showTab', 'mine')->assertSee($b->number())->assertDontSee($a->number())
            ->call('showTab', 'all')->set('search', 'SR-E2')->assertSee($b->number())->assertDontSee($a->number())
            ->set('search', $a->number())->assertSee($a->number())->assertDontSee($b->number())
            ->set('search', '')->set('priority', 'high')->assertSee($b->number())->assertDontSee($a->number())
            ->call('resetFilters')->call('sortBy', 'priority')->assertSet('sort', 'priority')->assertSet('dir', 'desc')->assertSeeInOrder([$b->number(), $a->number()])
            ->call('sortBy', 'priority')->assertSet('dir', 'asc')->assertSeeInOrder([$a->number(), $b->number()]);
    }

    public function test_reports_figures(): void
    {
        $a = $this->raise();
        $b = $this->raise('leave', [], $this->other, $this->otherEmployee);
        $this->raise('payroll');
        $this->actingAs($this->admin);
        $this->support->assign($a, $this->admin, $this->hr->id);
        Carbon::setTestNow('2026-11-03 13:00:00');
        $this->support->setStatus($a->fresh(), $this->admin, 'resolved');

        $report = $this->support->report($this->admin, ['from' => '2026-11-01', 'to' => '2026-11-30']);
        $this->assertSame(3, $report['summary']['total']);
        $this->assertSame(1, $report['summary']['resolved']);
        $this->assertSame(2, $report['summary']['pending']);
        $this->assertEquals(3.0, $report['summary']['avg_hours']);
        $this->assertSame(1, $report['by_handler']->firstWhere('name', 'Hema HR')['total']);
        $this->assertSame(2, $report['by_employee']->firstWhere('name', 'Riya Sharma')['total']);

        // Without support.payroll the payroll request is not counted.
        $this->hr->givePermissionTo('support.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertSame(2, $this->support->report($this->hr->fresh(), ['from' => '2026-11-01', 'to' => '2026-11-30'])['summary']['total']);

        Livewire::actingAs($this->admin)->test(Reports::class)->assertSee('Avg. resolution time')->assertSee('Hema HR')
            ->call('showEmployee', $this->employee->id)->assertSee('All requests of Riya Sharma')->assertSee($a->number());
        $this->assertNotNull($b);
    }

    public function test_mobile_api_for_the_employee(): void
    {
        Sanctum::actingAs($this->staff);
        $categories = $this->getJson('/api/support/categories')->assertOk()->json('data');
        $this->assertCount(8, $categories);
        $created = $this->post('/api/support/tickets', [
            'category_id' => $this->category('technical')->id, 'subject' => 'App crashes', 'description' => 'The app closes when I open Tasks.',
            'attachments' => [$this->fakeImage('crash.jpg')],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->assertSame('open', $created['status']);
        $this->assertCount(1, $created['messages'][0]['attachments']);
        $url = $created['messages'][0]['attachments'][0]['url'];

        $this->postJson('/api/support/tickets', ['subject' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['category_id', 'subject', 'description']);
        $this->assertCount(1, $this->getJson('/api/support/tickets')->assertOk()->json('data'));
        $this->postJson('/api/support/tickets/'.$created['id'].'/messages', ['body' => 'Also on Leave'])->assertCreated()->assertJsonPath('data.messages.1.body', 'Also on Leave');

        // The signed file link works for the owner only.
        auth()->forgetGuards();
        $this->get($url)->assertOk();
        $this->get(preg_replace('/user=\d+/', 'user='.$this->other->id, $url))->assertForbidden();

        Sanctum::actingAs($this->other);
        $this->getJson('/api/support/tickets/'.$created['id'])->assertForbidden();
        $this->postJson('/api/support/tickets/'.$created['id'].'/messages', ['body' => 'hi'])->assertForbidden();
        $this->assertCount(0, $this->getJson('/api/support/tickets')->json('data'));
    }

    public function test_notification_shows_as_help_and_support_and_opens_the_request(): void
    {
        $ticket = $this->raise();
        $n = $this->admin->notifications()->firstOrFail();
        $item = (new \App\Support\NotificationPresenter($this->admin))->present([$n])[0];
        $this->assertSame('support', $item['type']);
        $this->assertSame('Help & Support', $item['tag']);
        $this->assertSame('/support/requests/'.$ticket->id, $item['url']);
    }
}

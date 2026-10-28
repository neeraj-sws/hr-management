<?php

namespace Database\Seeders;

use App\Models\AttendanceActivityEvent;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\CredentialVaultEntry;
use App\Models\DailyWorkStatus;
use App\Models\Department;
use App\Models\EmployeeAsset;
use App\Models\EmployeeDocument;
use App\Models\EmployeeNote;
use App\Models\LeaveType;
use App\Models\PayrollItem;
use App\Models\PerformanceReview;
use App\Models\PolicyRule;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\Team;
use App\Models\User;
use App\Services\ApprovalInbox;
use App\Services\EmployeeService;
use App\Services\HrWorkflowService;
use App\Services\LeaveAccrualService;
use App\Services\PeopleOperationsService;
use App\Services\WorkService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Fake development data only (example.com addresses, invented names).
 * Every account uses SEED_USER_PASSWORD. Business records are created through
 * the same services the application uses, so balances, payroll, approvals,
 * notifications and audit logs are consistent.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (User::where('email', 'hr@example.com')->exists()) {
            $this->command?->info('Demo data already present — skipping.');

            return;
        }
        config(['queue.default' => 'sync']); // deliver notifications immediately while seeding
        mt_srand(2026);
        $password = (string) env('SEED_USER_PASSWORD');
        $admin = User::where('email', env('SEED_USER_EMAIL', 'admin@example.com'))->firstOrFail();
        Auth::setUser($admin);
        $employees = app(EmployeeService::class);
        $workflow = app(HrWorkflowService::class);
        $people = app(PeopleOperationsService::class);
        $work = app(WorkService::class);

        LeaveType::firstOrCreate(['name' => 'Sick Leave'], ['annual_allowance' => 0, 'monthly_accrual' => 0.5, 'carry_forward' => false, 'carry_forward_limit' => 0, 'paid' => true, 'allow_advance' => true, 'is_active' => true]);
        $annual = LeaveType::where('name', 'Annual Leave')->firstOrFail();
        $sick = LeaveType::where('name', 'Sick Leave')->firstOrFail();
        $unpaid = LeaveType::where('name', 'Unpaid Leave')->firstOrFail();
        PolicyRule::firstOrCreate(['rule_key' => 'attendance.rules'], ['category' => 'attendance', 'name' => 'Attendance rules', 'configuration' => ['grace_minutes' => 10, 'half_day_hours' => 4], 'is_active' => true]);

        $departments = collect([['Engineering', 'ENG'], ['Design', 'DES'], ['Operations', 'OPS'], ['Human Resources', 'HR'], ['Finance', 'FIN']])
            ->mapWithKeys(fn ($d) => [$d[1] => Department::firstOrCreate(['code' => $d[1]], ['name' => $d[0], 'is_active' => true])]);

        $joined = today()->subMonths(4)->startOfMonth()->toDateString();
        $make = function (array $data) use ($employees, $admin, $password, $joined) {
            return $employees->create([...$data, 'password' => $password, 'joined_at' => $data['joined_at'] ?? $joined, 'work_start' => '09:30', 'work_end' => '18:30', 'daily_work_hours' => 8], $admin);
        };

        // Admin gets an employee profile so the self-service pages can be demonstrated.
        $adminProfile = $workflow->createEmployee(['employee_code' => 'SWS-000', 'name' => $admin->name, 'email' => $admin->email, 'designation' => 'Managing Director', 'department_id' => $departments['OPS']->id, 'is_active' => true, 'user_id' => $admin->id, 'joined_at' => $joined, 'work_start' => '09:30', 'work_end' => '18:30', 'daily_work_hours' => 8, 'monthly_salary' => 150000], $admin->id);
        $hr = $make(['employee_code' => 'SWS-001', 'name' => 'Meera Kapoor', 'email' => 'hr@example.com', 'designation' => 'HR Manager', 'department_id' => $departments['HR']->id, 'manager_id' => $adminProfile->id, 'monthly_salary' => 70000]);
        $hr->user->syncRoles(['HR', 'Employee']);
        $accounts = $make(['employee_code' => 'SWS-002', 'name' => 'Vikram Shah', 'email' => 'accounts@example.com', 'designation' => 'Accounts Lead', 'department_id' => $departments['FIN']->id, 'manager_id' => $adminProfile->id, 'monthly_salary' => 65000]);
        $accounts->user->syncRoles(['Accounts', 'Employee']);
        $mgr1 = $make(['employee_code' => 'SWS-010', 'name' => 'Rohan Mehta', 'email' => 'manager1@example.com', 'designation' => 'Engineering Manager', 'department_id' => $departments['ENG']->id, 'manager_id' => $adminProfile->id, 'monthly_salary' => 90000, 'is_manager' => true]);
        $mgr2 = $make(['employee_code' => 'SWS-011', 'name' => 'Priya Nair', 'email' => 'manager2@example.com', 'designation' => 'Design Lead', 'department_id' => $departments['DES']->id, 'manager_id' => $adminProfile->id, 'monthly_salary' => 85000, 'is_manager' => true]);
        $staff = collect([
            ['SWS-101', 'Arjun Verma', 'employee1@example.com', 'Senior Developer', 'ENG', $mgr1, 60000],
            ['SWS-102', 'Neha Joshi', 'employee2@example.com', 'Developer', 'ENG', $mgr1, 48000],
            ['SWS-103', 'Karan Malhotra', 'employee3@example.com', 'QA Engineer', 'ENG', $mgr1, 42000],
            ['SWS-104', 'Sana Qureshi', 'employee4@example.com', 'UI Designer', 'DES', $mgr2, 45000],
            ['SWS-105', 'Dev Patel', 'employee5@example.com', 'Support Associate', 'OPS', $mgr2, 32000],
        ])->map(fn ($e) => $make(['employee_code' => $e[0], 'name' => $e[1], 'email' => $e[2], 'designation' => $e[3], 'department_id' => $departments[$e[4]]->id, 'manager_id' => $e[5]->id, 'monthly_salary' => $e[6]]));
        [$arjun, $neha, $karan, $sana, $dev] = $staff->all();

        $platform = Team::create(['name' => 'Platform Team', 'description' => 'Core product engineering', 'department_id' => $departments['ENG']->id, 'manager_employee_id' => $mgr1->id, 'is_active' => true]);
        $platform->members()->sync([$mgr1->id, $arjun->id, $neha->id, $karan->id]);
        $design = Team::create(['name' => 'Design & Support', 'description' => 'Product design and customer support', 'department_id' => $departments['DES']->id, 'manager_employee_id' => $mgr2->id, 'is_active' => true]);
        $design->members()->sync([$mgr2->id, $sana->id, $dev->id]);
        $departments['ENG']->update(['manager_id' => $mgr1->id]);
        $departments['DES']->update(['manager_id' => $mgr2->id]);

        $everyone = collect([$adminProfile, $hr, $accounts, $mgr1, $mgr2])->merge($staff);
        app(LeaveAccrualService::class)->accrueThrough((int) today()->year, (int) today()->month, $admin->id);

        // Leave history first so attendance can respect it.
        $lastMonth = today()->subMonthNoOverflow();
        $leaveDate = fn (int $day) => Carbon::create($lastMonth->year, $lastMonth->month, 1)->addWeekdays($day);
        Auth::setUser($arjun->user);
        $approvedLeave = $workflow->requestLeave($arjun, ['leave_type_id' => $annual->id, 'starts_on' => $leaveDate(5), 'ends_on' => $leaveDate(6), 'reason' => 'Family function']);
        Auth::setUser($mgr1->user);
        $workflow->decideLeave($approvedLeave, $mgr1->user_id, true, 'Enjoy!');
        Auth::setUser($sana->user);
        $sickLeave = $workflow->requestLeave($sana, ['leave_type_id' => $sick->id, 'starts_on' => $leaveDate(10), 'ends_on' => $leaveDate(10), 'reason' => 'Fever']);
        Auth::setUser($mgr2->user);
        $workflow->decideLeave($sickLeave, $mgr2->user_id, true, 'Doctor note received');
        Auth::setUser($dev->user);
        $unpaidLeave = $workflow->requestLeave($dev, ['leave_type_id' => $unpaid->id, 'starts_on' => $leaveDate(12), 'ends_on' => $leaveDate(12), 'reason' => 'Personal errand']);
        Auth::setUser($mgr2->user);
        $workflow->decideLeave($unpaidLeave, $mgr2->user_id, true, 'Personal work, informed in advance');
        Auth::setUser($karan->user);
        $rejected = $workflow->requestLeave($karan, ['leave_type_id' => $annual->id, 'starts_on' => today()->addWeekdays(3), 'ends_on' => today()->addWeekdays(4), 'reason' => 'Short trip']);
        Auth::setUser($mgr1->user);
        $workflow->decideLeave($rejected, $mgr1->user_id, false, 'Release week — please pick another date.');
        Auth::setUser($neha->user);
        $workflow->requestLeave($neha, ['leave_type_id' => $annual->id, 'starts_on' => today()->addWeekdays(8), 'ends_on' => today()->addWeekdays(10), 'reason' => 'Vacation']);

        // Attendance: previous ~2 months of workdays with realistic variation; today is live.
        $start = today()->subMonthsNoOverflow(2)->startOfMonth();
        foreach ($everyone as $employee) {
            $leaveDays = $employee->leaveRequests()->where('status', 'approved')->get()->flatMap(fn ($l) => collect(Carbon::parse($l->starts_on)->daysUntil(Carbon::parse($l->ends_on)->addDay()))->map->toDateString())->all();
            for ($day = $start->copy(); $day->lt(today()); $day->addDay()) {
                if ($day->isWeekend() || in_array($day->toDateString(), $leaveDays, true)) continue;
                $roll = mt_rand(1, 100);
                if ($roll <= 4 && ! in_array($employee->id, [$adminProfile->id], true)) continue; // unexplained absence
                $late = $roll <= 18 ? mt_rand(15, 55) : mt_rand(-20, 8);
                $checkIn = $day->copy()->setTime(9, 30)->addMinutes($late);
                $worked = $roll >= 97 ? mt_rand(180, 220) : mt_rand(470, 560); // a few half days
                $break = mt_rand(30, 60);
                $idle = mt_rand(0, 25);
                $session = AttendanceSession::create(['employee_id' => $employee->id, 'work_date' => $day->toDateString(), 'check_in' => $checkIn, 'check_out' => $checkIn->copy()->addMinutes($worked + $break + $idle), 'active_seconds' => $worked * 60, 'idle_seconds' => $idle * 60, 'break_seconds' => $break * 60, 'status' => 'completed', 'last_sync_at' => $checkIn->copy()->addMinutes($worked + $break + $idle)]);
                AttendanceActivityEvent::insert([
                    ['attendance_session_id' => $session->id, 'event_type' => 'check_in', 'event_time' => $checkIn, 'duration_seconds' => 0, 'metadata' => null, 'created_at' => now(), 'updated_at' => now()],
                    ['attendance_session_id' => $session->id, 'event_type' => 'break_start', 'event_time' => $checkIn->copy()->addHours(4), 'duration_seconds' => 0, 'metadata' => null, 'created_at' => now(), 'updated_at' => now()],
                    ['attendance_session_id' => $session->id, 'event_type' => 'break_end', 'event_time' => $checkIn->copy()->addHours(4)->addMinutes($break), 'duration_seconds' => $break * 60, 'metadata' => null, 'created_at' => now(), 'updated_at' => now()],
                    ['attendance_session_id' => $session->id, 'event_type' => 'check_out', 'event_time' => $session->check_out, 'duration_seconds' => 0, 'metadata' => null, 'created_at' => now(), 'updated_at' => now()],
                ]);
            }
        }
        if (today()->isWeekday()) {
            foreach ([[$mgr1, -15, 'working'], [$arjun, 5, 'working'], [$neha, 32, 'working'], [$sana, -5, 'on_break'], [$hr, 0, 'working'], [$accounts, 12, 'working']] as [$employee, $offset, $status]) {
                $checkIn = today()->setTime(9, 30)->addMinutes($offset);
                if ($checkIn->gt(now())) $checkIn = now()->subMinutes(30);
                $session = AttendanceSession::create(['employee_id' => $employee->id, 'work_date' => today()->toDateString(), 'check_in' => $checkIn, 'status' => $status, 'last_sync_at' => now()]);
                AttendanceActivityEvent::create(['attendance_session_id' => $session->id, 'event_type' => 'check_in', 'event_time' => $checkIn, 'duration_seconds' => 0]);
                if ($status === 'on_break') AttendanceActivityEvent::create(['attendance_session_id' => $session->id, 'event_type' => 'break_start', 'event_time' => now()->subMinutes(10)->max($checkIn), 'duration_seconds' => 0]);
            }
        }

        // Projects, tasks and time logs.
        Auth::setUser($mgr1->user);
        $portal = $work->saveProject(null, ['name' => 'Customer Portal v2', 'description' => 'Self-service portal rebuild', 'manager_id' => $mgr1->id, 'starts_on' => $start->toDateString(), 'ends_on' => today()->addMonth()->toDateString(), 'status' => 'active'], $mgr1->user);
        $api = $work->saveProject(null, ['name' => 'Mobile API', 'description' => 'REST API for the mobile app', 'manager_id' => $mgr1->id, 'starts_on' => $start->toDateString(), 'status' => 'active'], $mgr1->user);
        Auth::setUser($mgr2->user);
        $brand = $work->saveProject(null, ['name' => 'Brand Refresh', 'description' => 'New design system and marketing site', 'manager_id' => $mgr2->id, 'starts_on' => $start->toDateString(), 'status' => 'active'], $mgr2->user);
        $taskSpecs = [
            [$mgr1, $portal, $arjun, 'Build login & SSO screens', 'high', 16, -20, 'completed', -21],
            [$mgr1, $portal, $arjun, 'Account settings page', 'normal', 10, -6, 'completed', -3],
            [$mgr1, $portal, $neha, 'Invoice download feature', 'high', 12, -3, 'in_progress', null],
            [$mgr1, $portal, $karan, 'Regression test plan', 'normal', 8, 2, 'todo', null],
            [$mgr1, $api, $neha, 'Leave endpoints', 'urgent', 6, -12, 'completed', -13],
            [$mgr1, $api, $arjun, 'Offline sync conflict handling', 'urgent', 14, 5, 'in_progress', null],
            [$mgr1, $api, $karan, 'API contract tests', 'normal', 10, -1, 'todo', null],
            [$mgr2, $brand, $sana, 'Design tokens & colour palette', 'high', 12, -15, 'completed', -16],
            [$mgr2, $brand, $sana, 'Marketing site mockups', 'normal', 20, 7, 'in_progress', null],
            [$mgr2, $brand, $dev, 'Update help-centre articles', 'low', 6, -4, 'todo', null],
            [$mgr2, null, $dev, 'Weekly support ticket review', 'normal', 3, 1, 'todo', null],
        ];
        foreach ($taskSpecs as [$creator, $project, $assignee, $title, $priority, $estimate, $due, $status, $completed]) {
            Auth::setUser($creator->user);
            $task = $work->saveTask(null, ['title' => $title, 'description' => null, 'project_id' => $project?->id, 'assignee_id' => $assignee->id, 'priority' => $priority, 'status' => 'todo', 'estimated_hours' => $estimate, 'due_on' => today()->addDays($due)->toDateString()], $creator->user);
            $logged = 0;
            for ($i = 0; $i < ($status === 'todo' ? 0 : mt_rand(2, 4)); $i++) {
                $begin = today()->subDays(mt_rand(1, 25))->setTime(mt_rand(10, 15), 0);
                $seconds = mt_rand(3600, 3 * 3600);
                TaskTimeEntry::create(['task_id' => $task->id, 'employee_id' => $assignee->id, 'started_at' => $begin, 'ended_at' => $begin->copy()->addSeconds($seconds), 'duration_seconds' => $seconds, 'status' => 'stopped']);
                $logged += $seconds;
            }
            $task->update(['status' => $status, 'started_at' => $status === 'todo' ? null : today()->subDays(20), 'completed_at' => $completed !== null ? today()->addDays($completed)->setTime(17, 0) : null]);
        }
        // A live timer for today's demo.
        if (today()->isWeekday()) {
            $running = Task::where('assignee_id', $arjun->id)->where('status', 'in_progress')->first();
            if ($running) TaskTimeEntry::create(['task_id' => $running->id, 'employee_id' => $arjun->id, 'started_at' => now()->subMinutes(25), 'status' => 'running']);
        }

        foreach ($staff as $i => $employee) {
            foreach ([3, 2, 1] as $daysAgo) {
                $date = today()->subWeekdays($daysAgo);
                DailyWorkStatus::create(['employee_id' => $employee->id, 'work_date' => $date->toDateString(), 'summary' => ['Worked on assigned tasks and reviewed pull requests.', 'Completed testing and fixed review comments.', 'Pairing session and documentation updates.'][($i + $daysAgo) % 3], 'submitted_by' => $employee->user_id, 'server_version' => 1]);
            }
        }

        // Overtime.
        $otDay = today()->subWeekdays(6)->toDateString();
        Auth::setUser($neha->user);
        $ot1 = $people->requestOvertime($neha, $lastMonth->copy()->startOfMonth()->addWeekdays(8)->toDateString(), 120, 'Production hotfix');
        Auth::setUser($mgr1->user);
        $people->decideOvertime($ot1, $mgr1->user_id, true, 120, 'Thanks for staying late');
        Auth::setUser($arjun->user);
        $people->requestOvertime($arjun, $otDay, 90, 'Release preparation');
        Auth::setUser($sana->user);
        $ot3 = $people->requestOvertime($sana, $otDay, 60, 'Client presentation');
        Auth::setUser($mgr2->user);
        $people->decideOvertime($ot3, $mgr2->user_id, false, null, 'Not pre-approved');

        // Salary advance (disbursed before last month's payroll so recovery applies), adjustments and penalties.
        Auth::setUser($karan->user);
        $advance = $people->requestAdvance($karan, 12000, 4000, 'Medical expenses', $karan->user_id);
        Auth::setUser($hr->user);
        $people->decideFinancialRequest(\App\Models\SalaryAdvance::class, $advance->id, $hr->user_id, true);
        Auth::setUser($accounts->user);
        $people->disburseAdvance($advance, 12000, $accounts->user_id);
        Auth::setUser($dev->user);
        $people->requestAdvance($dev, 5000, 2500, 'Rent deposit', $dev->user_id);
        Auth::setUser($hr->user);
        $bonus = $people->requestAdjustment($arjun, 5000, 'earning', $lastMonth->copy()->endOfMonth()->toDateString(), 'Spot award', $hr->user_id);
        Auth::setUser($accounts->user);
        $people->decideFinancialRequest(\App\Models\SalaryAdjustment::class, $bonus->id, $accounts->user_id, true);
        Auth::setUser($sana->user);
        $people->requestAdjustment($sana, 1800, 'earning', today()->toDateString(), 'Travel reimbursement', $sana->user_id);
        Auth::setUser($hr->user);
        $penalty = $people->requestPenalty($dev, 500, 'Repeated late arrival (manual)', $hr->user_id);
        $penalty->update(['effective_on' => $lastMonth->copy()->endOfMonth()->subDays(3)->toDateString()]);
        Auth::setUser($accounts->user);
        $people->decideFinancialRequest(\App\Models\EmployeePenalty::class, $penalty->id, $accounts->user_id, true);
        Auth::setUser($hr->user);
        $people->requestPenalty($neha, 250, 'Missed daily status updates', $hr->user_id);

        // Last month's payroll: Accounts calculates, HR approves, Accounts locks and pays (one partial payment).
        Auth::setUser($accounts->user);
        $run = $workflow->calculatePayroll($lastMonth->copy()->startOfMonth()->toDateString(), $lastMonth->copy()->endOfMonth()->toDateString(), $accounts->user_id);
        Auth::setUser($hr->user);
        $inbox = app(ApprovalInbox::class);
        $approval = \App\Models\ApprovalRequest::where('approvable_type', \App\Models\PayrollRun::class)->where('approvable_id', $run->id)->where('status', 'pending')->firstOrFail();
        $inbox->decide($hr->user, $approval, true);
        Auth::setUser($accounts->user);
        $workflow->lockPayroll($run, $accounts->user_id);
        foreach (PayrollItem::where('payroll_run_id', $run->id)->get() as $item) {
            if ($item->employee_id === $dev->id) {
                $workflow->pay($item, ['amount' => round((float) $item->net_amount / 2, 2), 'paid_on' => today(), 'reference' => 'NEFT-DEMO-'.$item->id.'-A'], $accounts->user_id);
            } elseif ($item->employee_id !== $karan->id) {
                $workflow->pay($item, ['amount' => $item->net_amount, 'paid_on' => today(), 'reference' => 'NEFT-DEMO-'.$item->id], $accounts->user_id);
            }
        }

        // Notes, documents, assets, reviews, lifecycle, vault.
        EmployeeNote::create(['author_id' => $mgr1->user_id, 'employee_id' => $arjun->id, 'visibility' => 'manager', 'title' => 'Quarterly check-in', 'body' => 'Great progress on the portal. Next goal: mentor Neha on testing.']);
        EmployeeNote::create(['author_id' => $mgr1->user_id, 'team_id' => $platform->id, 'visibility' => 'team', 'title' => 'Release checklist', 'body' => "1. Freeze on Thursday\n2. QA sign-off\n3. Deploy Friday morning"]);
        EmployeeNote::create(['author_id' => $arjun->user_id, 'visibility' => 'personal', 'title' => 'Ideas', 'body' => 'Try caching the dashboard queries.']);
        foreach ($staff->take(3) as $employee) {
            $path = "employee-documents/{$employee->id}/demo-offer-letter.txt";
            Storage::disk('local')->put($path, "DEMO DOCUMENT — fake offer letter for {$employee->name}. Not a real document.");
            EmployeeDocument::create(['employee_id' => $employee->id, 'uploaded_by' => $hr->user_id, 'category' => 'employment', 'original_name' => 'offer-letter.txt', 'storage_path' => $path, 'expires_on' => null]);
        }
        $idPath = "employee-documents/{$dev->id}/demo-id.txt";
        Storage::disk('local')->put($idPath, 'DEMO DOCUMENT — fake ID proof.');
        EmployeeDocument::create(['employee_id' => $dev->id, 'uploaded_by' => $hr->user_id, 'category' => 'identity', 'original_name' => 'id-proof.txt', 'storage_path' => $idPath, 'expires_on' => today()->addDays(20)->toDateString()]);
        foreach ($staff as $i => $employee) {
            EmployeeAsset::create(['employee_id' => $employee->id, 'asset_tag' => sprintf('LAP-%04d', 100 + $i), 'name' => 'Laptop', 'status' => 'assigned', 'assigned_on' => $joined]);
        }
        EmployeeAsset::create(['employee_id' => $arjun->id, 'asset_tag' => 'MON-0201', 'name' => '27" Monitor', 'status' => 'assigned', 'assigned_on' => $joined]);
        foreach ([[$mgr1, $arjun, 5, 'Consistently delivers high-quality work ahead of schedule.'], [$mgr1, $neha, 4, 'Strong ownership of the API work.'], [$mgr2, $sana, 4, 'Excellent design system foundations.']] as [$reviewer, $employee, $rating, $strengths]) {
            $metrics = (array) $work->productivity(collect([$employee]), $lastMonth->copy()->startOfMonth()->toDateString(), $lastMonth->copy()->endOfMonth()->toDateString())->first();
            unset($metrics['employee']);
            PerformanceReview::create(['employee_id' => $employee->id, 'reviewer_id' => $reviewer->user_id, 'period_starts_on' => $lastMonth->copy()->startOfMonth(), 'period_ends_on' => $lastMonth->copy()->endOfMonth(), 'rating' => $rating, 'strengths' => $strengths, 'improvements' => 'Keep documenting decisions for the team.', 'metrics' => $metrics, 'status' => 'submitted']);
        }
        Auth::setUser($hr->user);
        $onboarding = $people->openLifecycle($dev, 'onboarding', $joined, 'Joined support team', $hr->user_id);
        $onboarding->clearances()->limit(2)->get()->each(fn ($item) => $item->update(['status' => 'cleared', 'cleared_by' => $hr->user_id, 'cleared_at' => now()]));
        CredentialVaultEntry::create(['created_by' => $admin->id, 'category' => 'database', 'label' => 'Staging database (demo)', 'username' => 'demo_user', 'secret_value' => 'demo-only-not-a-real-secret', 'url' => 'https://staging.example.com', 'visibility' => 'hr']);
        CredentialVaultEntry::create(['created_by' => $mgr1->user_id, 'team_id' => $platform->id, 'category' => 'service', 'label' => 'Error tracker API key (demo)', 'username' => null, 'secret_value' => 'demo-token-0000', 'url' => 'https://errors.example.com', 'visibility' => 'team']);

        Auth::forgetUser();
        $this->command?->info('Demo data seeded: admin, hr, accounts, manager1-2, employee1-5 @example.com (password = SEED_USER_PASSWORD).');
    }
}

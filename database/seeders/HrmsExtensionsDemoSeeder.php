<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\Candidate;
use App\Models\Department;
use App\Models\JobOpening;
use App\Models\OfficeLocation;
use App\Models\Shift;
use App\Models\TrainingAssignment;
use App\Models\TrainingCourse;
use App\Models\User;
use App\Services\AttendanceCorrectionService;
use App\Services\CompanyService;
use App\Services\ExpenseService;
use App\Services\PeopleOperationsService;
use App\Services\PolicyConfigurationService;
use App\Services\RecruitmentService;
use App\Services\ShiftService;
use App\Services\TrainingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Fake demo data for corrections, shifts, office location, expenses,
 * announcements, celebrations, holidays, recruitment and training.
 * Runs after DemoDataSeeder (needs its @example.com users) and only once.
 */
class HrmsExtensionsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $hr = User::where('email', 'hr@example.com')->first();
        if (! $hr || Shift::exists()) {
            $this->command?->info('HRMS extension demo data skipped (no demo users, or already seeded).');

            return;
        }
        config(['queue.default' => 'sync']);
        Auth::setUser($hr);
        $employee = fn (string $email) => AttendanceEmployee::whereHas('user', fn ($q) => $q->where('email', $email))->first();
        [$mgr1, $mgr2, $e1, $e2, $e3, $e4] = array_map($employee, ['manager1@example.com', 'manager2@example.com', 'employee1@example.com', 'employee2@example.com', 'employee3@example.com', 'employee4@example.com']);
        $hrProfile = $employee('hr@example.com');
        $active = AttendanceEmployee::where('is_active', true)->pluck('id')->all();

        // Shifts & roster
        $general = Shift::create(['name' => 'General', 'start_time' => '09:30', 'end_time' => '18:30', 'is_active' => true]);
        $early = Shift::create(['name' => 'Early', 'start_time' => '07:00', 'end_time' => '16:00', 'grace_minutes' => 10, 'is_active' => true]);
        Shift::create(['name' => 'Night', 'start_time' => '21:00', 'end_time' => '06:00', 'grace_minutes' => 15, 'is_active' => true]);
        app(ShiftService::class)->assign($active, $general, today()->startOfMonth()->subMonths(4)->toDateString(), null, $hr->id);
        if ($e4) app(ShiftService::class)->assign([$e4->id], $early, today()->startOfWeek()->addWeek()->toDateString(), null, $hr->id);

        // Office location (check-in location is optional until HR turns it on)
        OfficeLocation::create(['name' => 'Head Office (demo)', 'latitude' => 28.6139, 'longitude' => 77.2090, 'radius_meters' => 300, 'is_active' => true]);

        // Holidays with names (fixed-date public holidays only)
        $calendar = app(PolicyConfigurationService::class)->forEmployee(null, 'calendar.working_days', today()->toDateString(), ['days' => [1, 2, 3, 4, 5], 'holidays' => []]);
        $year = today()->year;
        $named = ["{$year}-10-02" => 'Gandhi Jayanti', "{$year}-12-25" => 'Christmas', ($year + 1).'-01-01' => "New Year's Day", ($year + 1).'-01-26' => 'Republic Day'];
        $holidays = collect($calendar['holidays'] ?? [])->merge(array_keys($named))->unique()->sort()->values()->all();
        app(PeopleOperationsService::class)->setPolicy('calendar.working_days', 'calendar', 'Working calendar', ['days' => $calendar['days'] ?? [1, 2, 3, 4, 5], 'holidays' => $holidays, 'holiday_names' => ($calendar['holiday_names'] ?? []) + $named], null, $hr->id, today()->toDateString());

        // Birthdays and an anniversary close to today (invented dates)
        $mgr1?->update(['date_of_birth' => today()->subYears(38)->toDateString()]);
        $e2?->update(['date_of_birth' => today()->addDays(2)->subYears(27)->toDateString()]);
        $e3?->update(['date_of_birth' => today()->addDays(12)->subYears(30)->toDateString()]);
        $e4?->update(['date_of_birth' => today()->addDays(20)->subYears(26)->toDateString(), 'share_birthday' => false]);
        $hrProfile?->update(['joined_at' => today()->addDays(3)->subYears(3)->toDateString()]);

        // Announcements
        $company = app(CompanyService::class);
        $company->saveAnnouncement(['title' => 'New: expense claims, training and attendance corrections', 'body' => "You can now claim work expenses with a receipt photo, see your training courses, and ask for an attendance correction if you forgot to check out.\n\nAll of these are in the menu and in the mobile app.", 'audience' => 'all', 'is_pinned' => true], $hr);
        $eng = Department::where('code', 'ENG')->first();
        if ($eng) $company->saveAnnouncement(['title' => 'Engineering: code freeze on Friday', 'body' => 'Please merge your pull requests by Thursday 6 PM. Release goes out on Monday.', 'audience' => 'department', 'department_id' => $eng->id, 'expires_on' => today()->addDays(10)->toDateString()], $mgr1?->user ?? $hr);

        // Expense claims
        $expenses = app(ExpenseService::class);
        if ($e1) $expenses->submit($e1, ['category' => 'Travel', 'spent_on' => today()->subDays(3)->toDateString(), 'amount' => 850, 'description' => 'Cab to client office for the sprint demo']);
        if ($e2 && $mgr1) {
            $claim = $expenses->submit($e2, ['category' => 'Internet & phone', 'spent_on' => today()->subDays(9)->toDateString(), 'amount' => 1200, 'description' => 'Monthly broadband while working from home']);
            $expenses->decide($claim, $mgr1->user_id, true, null);
        }

        // Attendance correction: forgot to check out
        if ($e3) {
            $session = AttendanceSession::where('employee_id', $e3->id)->whereDate('work_date', '<', today())->latest('work_date')->first();
            if ($session) {
                try {
                    app(AttendanceCorrectionService::class)->request($e3, $session->work_date->toDateString(), null, '19:15', 'Stayed late for the release and forgot to check out.');
                } catch (\Illuminate\Validation\ValidationException) {
                    // A correction may not be possible on this demo day; skip it.
                }
            }
        }

        // Recruitment
        $recruitment = app(RecruitmentService::class);
        $dev = JobOpening::create(['title' => 'Senior Laravel Developer', 'department_id' => $eng?->id, 'location' => 'Office / hybrid', 'employment_type' => 'full_time', 'openings' => 2, 'description' => "Build and maintain our HR platform.\n\n• 4+ years with PHP/Laravel\n• Comfortable with MySQL and REST APIs", 'status' => 'open', 'hiring_manager_id' => $mgr1?->user_id, 'closes_on' => today()->addDays(30), 'created_by' => $hr->id]);
        $support = JobOpening::create(['title' => 'Customer Support Associate', 'department_id' => Department::where('code', 'OPS')->value('id'), 'location' => 'Office', 'employment_type' => 'full_time', 'openings' => 1, 'description' => 'Help customers by email and phone.', 'status' => 'open', 'hiring_manager_id' => $mgr2?->user_id, 'created_by' => $hr->id]);
        $aditi = $recruitment->addCandidate(['job_opening_id' => $dev->id, 'name' => 'Aditi Rao', 'email' => 'aditi.rao@example.com', 'phone' => '+91 90000 00001', 'source' => 'job_portal'], null, $hr->id);
        if ($mgr1) $recruitment->scheduleInterview($aditi, ['scheduled_at' => today()->addDay()->setTime(11, 0), 'duration_minutes' => 45, 'mode' => 'video', 'location' => 'https://meet.example.com/demo-interview', 'interviewer_id' => $mgr1->user_id]);
        $recruitment->moveStage($recruitment->addCandidate(['job_opening_id' => $dev->id, 'name' => 'Rahul Sen', 'email' => 'rahul.sen@example.com', 'source' => 'referral', 'notes' => 'Referred by Arjun.'], null, $hr->id), 'screening');
        $farah = $recruitment->addCandidate(['job_opening_id' => $dev->id, 'name' => 'Farah Khan', 'email' => 'farah.khan@example.com', 'source' => 'website'], null, $hr->id);
        if ($mgr1) {
            $done = $farah->interviews()->create(['scheduled_at' => today()->subDays(2)->setTime(15, 0), 'duration_minutes' => 60, 'mode' => 'in_person', 'location' => 'Meeting room 2', 'interviewer_id' => $mgr1->user_id, 'status' => 'scheduled']);
            $recruitment->submitFeedback($done, $mgr1->user, ['rating' => 4, 'recommendation' => 'hire', 'feedback' => 'Strong Laravel and SQL fundamentals; clear communicator.']);
        }
        $recruitment->moveStage($farah, 'offer');
        $recruitment->addCandidate(['job_opening_id' => $support->id, 'name' => 'Vivek Jain', 'email' => 'vivek.jain@example.com', 'source' => 'walk_in'], null, $hr->id);
        $recruitment->moveStage($recruitment->addCandidate(['job_opening_id' => $support->id, 'name' => 'Pooja Iyer', 'email' => 'pooja.iyer@example.com', 'source' => 'agency'], null, $hr->id), 'rejected');

        // Training
        $training = app(TrainingService::class);
        $security = TrainingCourse::create(['title' => 'Information security basics', 'description' => 'Passwords, phishing, safe handling of customer data.', 'category' => 'Compliance', 'provider' => 'Internal', 'duration_hours' => 2, 'material_url' => 'https://example.com/training/security-basics', 'is_mandatory' => true, 'is_active' => true, 'created_by' => $hr->id]);
        $review = TrainingCourse::create(['title' => 'Code review best practices', 'description' => 'How to give and receive useful code reviews.', 'category' => 'Technical', 'provider' => 'Engineering', 'duration_hours' => 1.5, 'material_url' => 'https://example.com/training/code-review', 'is_active' => true, 'created_by' => $hr->id]);
        TrainingCourse::create(['title' => 'Customer communication', 'description' => 'Writing clear, friendly replies to customers.', 'category' => 'Soft skills', 'provider' => 'Internal', 'duration_hours' => 1, 'is_active' => true, 'created_by' => $hr->id]);
        $training->assign($security, $active, today()->addDays(14)->toDateString(), $hr->id);
        if ($mgr1) $training->assign($review, AttendanceEmployee::where('manager_id', $mgr1->id)->pluck('id')->all(), today()->addDays(30)->toDateString(), $mgr1->user_id);
        if ($e1) $training->complete(TrainingAssignment::where('training_course_id', $security->id)->where('employee_id', $e1->id)->firstOrFail(), $e1, 'Completed the quiz with 9/10.');
        if ($e2) $training->start(TrainingAssignment::where('training_course_id', $security->id)->where('employee_id', $e2->id)->firstOrFail(), $e2);

        Auth::forgetUser();
        $this->command?->info('HRMS extension demo data seeded (shifts, office, holidays, birthdays, announcements, expenses, correction, recruitment, training).');
    }
}

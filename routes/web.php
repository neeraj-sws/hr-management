<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\EmployeeDocumentController;
use App\Http\Controllers\HrFileController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PayslipController;
use App\Http\Controllers\ReportExportController;
use App\Livewire;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : redirect()->route('login'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:6,1')->name('login.store');
    Route::get('/forgot-password', [\App\Http\Controllers\Auth\PasswordResetController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [\App\Http\Controllers\Auth\PasswordResetController::class, 'store'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [\App\Http\Controllers\Auth\PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [\App\Http\Controllers\Auth\PasswordResetController::class, 'update'])->middleware('throttle:5,1')->name('password.update');
});

Route::get('/files/documents/{document}', [HrFileController::class, 'signedDocument'])->middleware(['signed', 'throttle:30,1'])->name('files.document');

Route::middleware(['auth', 'account.active'])->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', Livewire\Hr\Dashboard::class)->name('dashboard');
    Route::get('/search', Livewire\Search::class)->name('search');
    Route::get('/profile', Livewire\Account\Profile::class)->name('account.profile');

    // People
    Route::middleware('access:employees.manage,hr.manage')->group(function () {
        Route::get('/people/employees', Livewire\People\Employees::class)->name('people.employees');
        Route::get('/people/departments', Livewire\People\Departments::class)->name('people.departments');
        Route::get('/people/managers', Livewire\People\Managers::class)->name('people.managers');
    });
    Route::get('/people/employees/{employee}', Livewire\People\EmployeeProfile::class)->name('people.employees.show');
    Route::get('/people/teams', Livewire\People\Teams::class)->middleware('access:teams.manage,hr.manage,profile')->name('people.teams');

    // Attendance
    Route::get('/attendance/my', Livewire\Attendance\MyAttendance::class)->middleware('access:profile')->name('attendance.my');
    Route::get('/attendance/team', Livewire\Attendance\Dashboard::class)->middleware('access:attendance.view,hr.manage,employees.manage')->name('attendance.dashboard');
    Route::get('/attendance/records', Livewire\Attendance\Records::class)->middleware('access:hr.manage,employees.manage')->name('attendance.records');
    Route::get('/attendance/{session}/photo/{kind}', [HrFileController::class, 'attendancePhoto'])->name('attendance.photo');
    Route::post('/attendance/records/import', [ImportController::class, 'attendance'])->middleware('access:hr.manage,employees.manage')->name('attendance.records.import');
    Route::post('/attendance/records/import/for-employee', [ImportController::class, 'attendanceFor'])->middleware('access:hr.manage,employees.manage')->name('attendance.records.import-for');
    Route::get('/attendance/corrections', Livewire\Attendance\Corrections::class)->middleware('access:profile,attendance.correct,approvals.manage')->name('attendance.corrections');
    Route::get('/attendance/conflicts', Livewire\Attendance\Conflicts::class)->middleware('access:attendance.correct,leave.manage,hr.manage')->name('attendance.conflicts');
    Route::get('/attendance/devices', Livewire\Attendance\Devices::class)->middleware('access:employees.manage,hr.manage')->name('attendance.devices');
    Route::get('/attendance/shifts', Livewire\Attendance\Shifts::class)->middleware('access:shifts.manage,hr.manage')->name('attendance.shifts');

    // Work
    Route::get('/work/projects', Livewire\Work\Projects::class)->middleware('access:projects.manage,hr.manage')->name('work.projects');
    Route::get('/work/my-projects', Livewire\Work\Projects::class)->middleware('access:profile')->name('work.my-projects');
    Route::get('/work/tasks', Livewire\Work\Tasks::class)->middleware('access:tasks.manage,hr.manage,manager')->name('work.tasks');
    Route::get('/work/my-tasks', Livewire\Work\Tasks::class)->middleware('access:profile')->name('work.my-tasks');
    Route::get('/work/timer', Livewire\Work\Timer::class)->middleware('access:profile')->name('work.timer');
    Route::get('/work/daily-status', Livewire\Work\DailyStatus::class)->middleware('access:profile,attendance.view,hr.manage')->name('work.daily-status');

    // Leave & time
    Route::get('/leave/requests', Livewire\Leave\Requests::class)->middleware('access:profile,leave.manage,leave.approve')->name('leave.requests');
    Route::get('/leave/balances', Livewire\Leave\Balances::class)->middleware('access:profile,leave.manage')->name('leave.balances');
    Route::get('/leave/overtime', Livewire\Leave\Overtime::class)->middleware('access:profile,overtime.manage')->name('leave.overtime');

    // Expenses
    Route::get('/expenses', Livewire\Expenses\Claims::class)->middleware('access:profile,expenses.manage,approvals.manage')->name('expenses.index');
    Route::get('/expenses/{claim}/receipt', [HrFileController::class, 'receipt'])->name('expenses.receipt');

    // Payroll
    Route::get('/payroll/salary', Livewire\Payroll\Salary::class)->middleware('access:profile,payroll.manage,payroll.approve,hr.manage')->name('payroll.salary');
    Route::post('/payroll/salary/import', [ImportController::class, 'salary'])->middleware('access:payroll.manage,hr.manage')->name('payroll.salary.import');
    Route::get('/payroll/legacy-salary', Livewire\Payroll\LegacySalary::class)->middleware('access:payroll.manage,hr.manage')->name('payroll.legacy');
    Route::post('/payroll/legacy-salary/import', [ImportController::class, 'legacySalary'])->middleware('access:payroll.manage,hr.manage')->name('payroll.legacy.import');
    Route::get('/imports/{type}/template', [ImportController::class, 'template'])->middleware('access:payroll.manage,hr.manage,employees.manage')->name('imports.template');
    Route::get('/payroll/runs', Livewire\Payroll\Runs::class)->middleware('access:payroll.manage,payroll.approve')->name('payroll.runs');
    Route::get('/payroll/approvals', Livewire\Payroll\Runs::class)->middleware('access:payroll.approve')->name('payroll.approvals');
    Route::get('/payroll/payslips', Livewire\Payroll\Payslips::class)->middleware('access:profile,payroll.manage,payroll.approve')->name('payroll.payslips');
    Route::get('/payroll/payslips/{payslip}', [PayslipController::class, 'show'])->name('hr.payslips.show');
    Route::get('/payroll/advances', Livewire\Payroll\Advances::class)->middleware('access:profile,salary.advance,salary.adjust')->name('payroll.advances');
    Route::get('/payroll/payments', Livewire\Payroll\Payments::class)->middleware('access:payroll.manage,payroll.approve')->name('payroll.payments');

    // Performance
    Route::get('/performance/reviews', Livewire\Performance\Reviews::class)->middleware('access:profile,performance.review,hr.manage')->name('performance.reviews');
    Route::get('/performance/productivity', Livewire\Performance\Productivity::class)->middleware('access:reports.view,hr.manage')->name('performance.productivity');

    // Compliance
    Route::get('/compliance', Livewire\Compliance\Policies::class)->middleware('access:policies.manage,penalties.manage')->name('compliance.policies');
    Route::get('/compliance/penalties', Livewire\Compliance\Penalties::class)->middleware('access:profile,penalties.manage')->name('compliance.penalties');

    // Company
    Route::get('/company/announcements', Livewire\Company\Announcements::class)->middleware('access:any')->name('company.announcements');
    Route::get('/company/holidays', Livewire\Company\Holidays::class)->middleware('access:any')->name('company.holidays');
    Route::get('/company/celebrations', Livewire\Company\Celebrations::class)->middleware('access:any')->name('company.celebrations');
    Route::get('/company/org-chart', Livewire\Company\OrgChart::class)->middleware('access:any')->name('company.org-chart');

    // Recruitment
    Route::get('/recruitment/jobs', Livewire\Recruitment\Jobs::class)->middleware('access:recruitment.manage')->name('recruitment.jobs');
    Route::get('/recruitment/candidates', Livewire\Recruitment\Candidates::class)->middleware('access:recruitment.manage')->name('recruitment.candidates');
    Route::get('/recruitment/interviews', Livewire\Recruitment\Interviews::class)->middleware('access:recruitment.manage,interviewer')->name('recruitment.interviews');
    Route::get('/recruitment/candidates/{candidate}/resume', [HrFileController::class, 'resume'])->name('recruitment.resume');

    // Monitoring (optional screen capture)
    Route::get('/monitoring/policy', Livewire\Monitoring\Policy::class)->middleware('access:profile,monitoring.view,monitoring.manage')->name('monitoring.policy');
    Route::get('/monitoring/screenshots', Livewire\Monitoring\Screenshots::class)->middleware('access:profile,monitoring.view,approvals.manage')->name('monitoring.screenshots');
    Route::get('/monitoring/screenshots/{screenshot}/image', [HrFileController::class, 'screenshot'])->name('monitoring.screenshot');

    // Training
    Route::get('/training/my', Livewire\Training\MyTraining::class)->middleware('access:profile')->name('training.my');
    Route::get('/training/courses', Livewire\Training\Courses::class)->middleware('access:training.manage,training.assign')->name('training.courses');

    // Approvals
    Route::middleware('access:approver')->group(function () {
        Route::get('/approvals', Livewire\Approvals\Center::class)->name('approvals.center');
        Route::get('/approvals/pending', Livewire\Approvals\Center::class)->name('approvals.pending');
        Route::get('/approvals/history', Livewire\Approvals\Center::class)->name('approvals.history');
    });

    // Notes
    Route::get('/notes', Livewire\Notes\Notes::class)->name('notes.my');
    Route::get('/notes/team', Livewire\Notes\Notes::class)->middleware('access:profile,notes.manage,hr.manage')->name('notes.team');

    // Employee lifecycle
    Route::get('/lifecycle/documents', Livewire\Lifecycle\Documents::class)->middleware('access:profile,lifecycle.manage')->name('lifecycle.documents');
    Route::get('/lifecycle/documents/{document}/download', [EmployeeDocumentController::class, 'download'])->name('hr.documents.download');
    Route::get('/lifecycle/assets', Livewire\Lifecycle\Assets::class)->middleware('access:profile,lifecycle.manage')->name('lifecycle.assets');
    Route::get('/lifecycle/onboarding', Livewire\Lifecycle\Cases::class)->middleware('access:lifecycle.manage')->name('lifecycle.onboarding');
    Route::get('/lifecycle/offboarding', Livewire\Lifecycle\Cases::class)->middleware('access:lifecycle.manage')->name('lifecycle.offboarding');
    Route::get('/lifecycle/settlements', Livewire\Lifecycle\Settlements::class)->middleware('access:lifecycle.manage,payroll.manage,payroll.approve')->name('lifecycle.settlements');

    // Reports
    Route::get('/reports/exports', Livewire\Reports\ExportCenter::class)->middleware('access:reports.view,hr.manage,payroll.manage,payroll.approve,audit.view')->name('reports.exports');
    Route::get('/reports/{type}', Livewire\Reports\Report::class)->whereIn('type', array_keys(\App\Services\ReportService::TYPES))->name('reports.show');
    Route::get('/reports/{type}/export', ReportExportController::class)->name('hr.reports.export');

    // Security
    Route::get('/security/vault', Livewire\Security\Vault::class)->middleware('access:credentials.manage,credentials.view')->name('security.vault');
    Route::get('/security/audit', Livewire\Security\AuditLogs::class)->middleware('access:audit.view')->name('security.audit');

    // System
    Route::get('/notifications', Livewire\System\Notifications::class)->name('system.notifications');
    Route::get('/notifications/{id}/open', [NotificationController::class, 'open'])->name('system.notifications.open');
    Route::get('/system/roles', Livewire\System\Roles::class)->middleware('access:roles.manage')->name('system.roles');
    Route::get('/system/settings', Livewire\System\Settings::class)->middleware('access:policies.manage')->name('system.settings');
});

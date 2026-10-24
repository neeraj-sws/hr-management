<?php

use App\Http\Controllers\Api\ApprovalController;
use App\Http\Controllers\Api\AttendanceActionController;
use App\Http\Controllers\Api\AttendanceDeviceController;
use App\Http\Controllers\Api\AttendanceSyncController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\CorrectionController;
use App\Http\Controllers\Api\DailyStatusController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OfflineActionSyncController;
use App\Http\Controllers\Api\OvertimeController;
use App\Http\Controllers\Api\PayslipController;
use App\Http\Controllers\Api\ScreenCaptureController;
use App\Http\Controllers\Api\SelfServiceController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\TrainingController;
use Illuminate\Support\Facades\Route;

/*
| JSON API for the mobile app, desktop agent and integrations.
| 401 = missing/invalid token, 403 = not permitted, 422 = validation, 2xx = success.
*/

Route::post('/auth/token', [AuthController::class, 'token'])->middleware('throttle:6,1')->name('api.auth.token');

Route::middleware(['auth:sanctum', 'account.active', 'throttle:120,1'])->group(function () {
    Route::post('/auth/revoke', [AuthController::class, 'revoke'])->name('api.auth.revoke');
    Route::get('/me', [MeController::class, 'show'])->name('api.me');
    Route::get('/me/photo', [MeController::class, 'photo'])->name('api.me.photo');

    Route::get('/employees', [EmployeeController::class, 'index'])->name('api.employees.index');
    Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->name('api.employees.show');
    Route::get('/teams', [TeamController::class, 'index'])->name('api.teams.index');
    Route::post('/teams', [TeamController::class, 'store'])->middleware('can:teams.manage')->name('api.teams.store');

    // Attendance: user tokens (mobile/web) use the action endpoints; device agents use /attendance/sync.
    Route::get('/attendance', [AttendanceSyncController::class, 'index'])->name('attendance.index');
    Route::post('/attendance/sync', [AttendanceSyncController::class, 'sync'])->name('attendance.sync');
    Route::get('/attendance/today', [AttendanceActionController::class, 'today'])->name('api.attendance.today');
    Route::get('/attendance/history', [AttendanceActionController::class, 'history'])->name('api.attendance.history');
    Route::post('/attendance/check-in', [AttendanceActionController::class, 'checkIn'])->name('api.attendance.check-in');
    Route::post('/attendance/break/start', [AttendanceActionController::class, 'startBreak'])->name('api.attendance.break-start');
    Route::post('/attendance/break/end', [AttendanceActionController::class, 'endBreak'])->name('api.attendance.break-end');
    Route::post('/attendance/check-out', [AttendanceActionController::class, 'checkOut'])->name('api.attendance.check-out');
    Route::post('/attendance/auto-check-out', [AttendanceActionController::class, 'autoCheckOut'])->name('api.attendance.auto-check-out');
    Route::post('/attendance/heartbeat', [AttendanceActionController::class, 'heartbeat'])->name('api.attendance.heartbeat');
    Route::post('/attendance/away', [AttendanceActionController::class, 'away'])->name('api.attendance.away');
    Route::get('/attendance/corrections', [CorrectionController::class, 'index'])->name('api.corrections.index');
    Route::post('/attendance/corrections', [CorrectionController::class, 'store'])->name('api.corrections.store');
    Route::post('/attendance/corrections/{correction}/cancel', [CorrectionController::class, 'cancel'])->name('api.corrections.cancel');

    Route::get('/expenses', [ExpenseController::class, 'index'])->name('api.expenses.index');
    Route::post('/expenses', [ExpenseController::class, 'store'])->name('api.expenses.store');
    Route::post('/expenses/{claim}/cancel', [ExpenseController::class, 'cancel'])->name('api.expenses.cancel');

    Route::get('/my/documents', [SelfServiceController::class, 'documents'])->name('api.my.documents');
    Route::post('/my/documents', [SelfServiceController::class, 'uploadDocument'])->name('api.my.documents.upload');
    Route::delete('/my/documents/{document}', [SelfServiceController::class, 'deleteDocument'])->name('api.my.documents.delete');
    Route::get('/my/assets', [SelfServiceController::class, 'assets'])->name('api.my.assets');
    Route::get('/my/performance', [SelfServiceController::class, 'performance'])->name('api.my.performance');
    Route::get('/my/salary', [SelfServiceController::class, 'salary'])->name('api.my.salary');
    Route::get('/team/today', [SelfServiceController::class, 'team'])->name('api.team.today');

    Route::get('/daily-status', [DailyStatusController::class, 'index'])->name('api.daily-status.index');
    Route::post('/daily-status', [DailyStatusController::class, 'store'])->name('api.daily-status.store');

    Route::get('/announcements', [CompanyController::class, 'announcements'])->name('api.announcements');
    Route::get('/celebrations', [CompanyController::class, 'celebrations'])->name('api.celebrations');
    Route::get('/holidays', [CompanyController::class, 'holidays'])->name('api.holidays');

    Route::post('/monitoring/acknowledge', [ScreenCaptureController::class, 'acknowledge'])->name('api.monitoring.acknowledge');
    Route::post('/monitoring/app-usage', [\App\Http\Controllers\Api\AppUsageController::class, 'store'])->name('api.monitoring.app-usage');
    Route::post('/monitoring/screenshots', [ScreenCaptureController::class, 'store'])->name('api.monitoring.screenshots');

    Route::get('/training', [TrainingController::class, 'index'])->name('api.training.index');
    Route::post('/training/{assignment}/start', [TrainingController::class, 'start'])->name('api.training.start');
    Route::post('/training/{assignment}/complete', [TrainingController::class, 'complete'])->name('api.training.complete');

    Route::get('/tasks', [TaskController::class, 'index'])->name('api.tasks.index');
    Route::post('/tasks', [TaskController::class, 'store'])->name('api.tasks.store');
    Route::patch('/tasks/{task}', [TaskController::class, 'update'])->name('api.tasks.update');
    Route::post('/tasks/{task}/start', [TaskController::class, 'start'])->name('api.tasks.start');
    Route::post('/tasks/{task}/pause', [TaskController::class, 'pause'])->name('api.tasks.pause');
    Route::post('/tasks/{task}/complete', [TaskController::class, 'complete'])->name('api.tasks.complete');
    Route::get('/tasks/{task}/comments', [TaskController::class, 'comments'])->name('api.tasks.comments');
    Route::post('/tasks/{task}/comments', [TaskController::class, 'comment'])->name('api.tasks.comment');

    Route::get('/leave/types', [LeaveController::class, 'types'])->name('api.leave.types');
    Route::get('/leave/balances', [LeaveController::class, 'balances'])->name('api.leave.balances');
    Route::get('/leave/requests', [LeaveController::class, 'index'])->name('api.leave.index');
    Route::post('/leave/requests', [LeaveController::class, 'store'])->name('api.leave.store');
    Route::post('/leave/requests/{leave}/cancel', [LeaveController::class, 'cancel'])->name('api.leave.cancel');

    Route::get('/overtime', [OvertimeController::class, 'index'])->name('api.overtime.index');
    Route::post('/overtime', [OvertimeController::class, 'store'])->name('api.overtime.store');

    Route::get('/approvals', [ApprovalController::class, 'index'])->name('api.approvals.index');
    Route::post('/approvals/{approval}/decision', [ApprovalController::class, 'decide'])->name('api.approvals.decide');

    Route::get('/payslips', [PayslipController::class, 'index'])->name('api.payslips.index');
    Route::get('/payslips/{payslip}', [PayslipController::class, 'show'])->name('api.payslips.show');

    Route::get('/desktop/latest', [\App\Http\Controllers\Api\DesktopReleaseController::class, 'latest'])->name('api.desktop.latest');
    Route::post('/push-tokens', [\App\Http\Controllers\Api\PushTokenController::class, 'store'])->name('api.push-tokens.store');
    Route::post('/push-tokens/remove', [\App\Http\Controllers\Api\PushTokenController::class, 'destroy'])->name('api.push-tokens.destroy');
    Route::get('/search', \App\Http\Controllers\SearchController::class)->middleware('throttle:120,1')->name('api.search');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('api.notifications.index');
    Route::get('/notifications/preferences', [NotificationController::class, 'preferences'])->name('api.notifications.preferences');
    Route::post('/notifications/preferences', [NotificationController::class, 'savePreferences'])->name('api.notifications.preferences.save');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('api.notifications.read-all');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->name('api.notifications.read');

    // Offline queue (idempotent by action_id; version conflicts return 409 with server state).
    Route::post('/sync/actions', [OfflineActionSyncController::class, 'sync'])->name('api.sync.actions');
    Route::get('/sync/status', [OfflineActionSyncController::class, 'status'])->name('api.sync.status');
});

Route::middleware(['auth:sanctum', 'account.active', 'throttle:30,1'])->group(function () {
    Route::post('/attendance/device/register', [AttendanceDeviceController::class, 'register'])->name('attendance.device.register');
    Route::delete('/attendance/device/{deviceId}', [AttendanceDeviceController::class, 'revoke'])->name('attendance.device.revoke');
});

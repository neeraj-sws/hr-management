<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AttendanceEmployee extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $fillable = [
        'employee_code',
        'name',
        'email',
        'designation',
        'phone',
        'photo_path',
        'monthly_salary',
        'daily_work_hours',
        'work_start',
        'work_end',
        'is_active',
        'user_id',
        'department_id',
        'manager_id',
        'joined_at',
        'date_of_birth',
        'share_birthday',
        'employment_type',
        'probation_ends_on',
        'confirmed_on',
        'notice_period_days',
        'resigned_on',
        'exit_date',
        'exit_type',
        'exit_reason',
    ];

    protected $casts = [
        'monthly_salary' => 'decimal:2',
        'daily_work_hours' => 'decimal:2',
        'is_active' => 'boolean',
        'joined_at' => 'date',
        'date_of_birth' => 'date',
        'share_birthday' => 'boolean',
        'probation_ends_on' => 'date',
        'confirmed_on' => 'date',
        'resigned_on' => 'date',
        'exit_date' => 'date',
    ];

    public function devices(): HasMany
    {
        return $this->hasMany(AttendanceDevice::class, 'employee_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(AttendanceSession::class, 'employee_id');
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function manager(): BelongsTo { return $this->belongsTo(self::class, 'manager_id'); }
    public function directReports(): HasMany { return $this->hasMany(self::class, 'manager_id'); }
    public function tasks(): HasMany { return $this->hasMany(Task::class, 'assignee_id'); }
    public function leaveRequests(): HasMany { return $this->hasMany(LeaveRequest::class, 'employee_id'); }
    public function salaryProfile() { return $this->hasOne(SalaryProfile::class, 'employee_id')->latestOfMany(); }
    public function salaryProfiles(): HasMany { return $this->hasMany(SalaryProfile::class, 'employee_id'); }
    public function overtimeRequests(): HasMany { return $this->hasMany(OvertimeRequest::class, 'employee_id'); }
    public function leaveBalances(): HasMany { return $this->hasMany(LeaveBalance::class, 'employee_id'); }
    public function assets(): HasMany { return $this->hasMany(EmployeeAsset::class, 'employee_id'); }
    public function documents(): HasMany { return $this->hasMany(EmployeeDocument::class, 'employee_id'); }

    /** Link to the employee's photo (changes when the photo changes, so browsers refresh it), or null. */
    public function photoUrl(): ?string
    {
        return $this->photo_path ? route('people.photo', ['employee' => $this->id, 'v' => substr(md5($this->photo_path), 0, 8)]) : null;
    }

    /** "RG" for Risha Gangrade. */
    public function initials(): string
    {
        return collect(explode(' ', (string) $this->name))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->join('') ?: 'SW';
    }
    public function payslips(): HasMany { return $this->hasMany(Payslip::class, 'employee_id'); }
    public function reviews(): HasMany { return $this->hasMany(PerformanceReview::class, 'employee_id'); }
    public function shiftAssignments(): HasMany { return $this->hasMany(EmployeeShift::class, 'employee_id'); }
    public function expenseClaims(): HasMany { return $this->hasMany(ExpenseClaim::class, 'employee_id'); }
    public function trainingAssignments(): HasMany { return $this->hasMany(TrainingAssignment::class, 'employee_id'); }
    public function teams(): BelongsToMany { return $this->belongsToMany(Team::class, 'employee_team', 'employee_id', 'team_id')->withTimestamps(); }
}

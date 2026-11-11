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
        'company_id',
        'manager_id',
        'joined_at',
        'date_of_birth',
        'share_birthday',
        'address',
        'emergency_contact_name',
        'emergency_contact_relation',
        'emergency_contact_phone',
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

    /** A login limited to one company sees only that company's employees (see CompanyAccess). */
    protected static function booted(): void
    {
        static::addGlobalScope('company', function (\Illuminate\Database\Eloquent\Builder $query) {
            if ($companyId = \App\Support\CompanyAccess::limitedCompanyId()) \App\Support\CompanyAccess::whereCompany($query, $companyId, $query->getModel()->qualifyColumn('company_id'));
        });
        static::creating(function (self $employee) {
            if (empty($employee->employee_code)) $employee->employee_code = static::nextCode($employee->company_id);
        });
    }

    /** Generate the next EMP-XX code, incrementing from the current max. */
    public static function nextCode(?int $companyId): string
    {
        $existing = static::withoutGlobalScopes()->pluck('employee_code')
            ->map(fn ($c) => preg_match('/^EMP-(\d+)$/', $c, $m) ? (int) $m[1] : 0)->max() ?? 0;
        return 'EMP-' . str_pad($existing + 1, 2, '0', STR_PAD_LEFT);
    }

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
    /** Empty = the main company. */
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    /** The company this employee's payroll belongs to (null = the main company). */
    public function payrollCompanyId(): ?int { return Company::payrollKey($this->company_id); }
    public function manager(): BelongsTo { return $this->belongsTo(self::class, 'manager_id'); }
    public function directReports(): HasMany { return $this->hasMany(self::class, 'manager_id'); }
    public function tasks(): HasMany { return $this->hasMany(Task::class, 'assignee_id'); }
    public function leaveRequests(): HasMany { return $this->hasMany(LeaveRequest::class, 'employee_id'); }
    public function trainingEnrollments(): HasMany { return $this->hasMany(TrainingEnrollment::class, 'employee_id'); }
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

    /** People who are paid a salary: everyone except trainees (they pay a course fee instead). */
    /** Employees paid in this company's payroll (null = the main company, which also has everyone with no company). */
    public function scopeInPayrollCompany($query, ?int $companyId)
    {
        if ($companyId) return $query->where('company_id', $companyId);
        $main = Company::where('is_main', true)->pluck('id');

        return $query->where(fn ($q) => $q->whereNull('company_id')->orWhereIn('company_id', $main));
    }

    public function scopeOnPayroll($query)
    {
        return $query->where(fn ($q) => $q->whereNull('employment_type')->orWhereNotIn('employment_type', [\App\Services\EmployeeService::TRAINEE, \App\Services\EmployeeService::OWNER]));
    }

    /** The company whose rules are being changed (Settings, picked by the Super Admin); everyone when none is picked. */
    public function scopeOfRulesCompany($query)
    {
        $companyId = \App\Support\CompanyAccess::rulesCompanyId();

        return $companyId ? $query->inPayrollCompany(Company::payrollKey($companyId)) : $query;
    }

    /** Everyone but trainees (People > Employees lists them, the owner included; trainees have their own page). */
    public function scopeNotTrainee($query)
    {
        return $query->where(fn ($q) => $q->whereNull('employment_type')->orWhere('employment_type', '!=', \App\Services\EmployeeService::TRAINEE));
    }

    /** Everyone who works here: all but a company owner (no attendance alerts, reminders or leave accrual for him). */
    public function scopeWorksHere($query)
    {
        return $query->where(fn ($q) => $q->whereNull('employment_type')->orWhere('employment_type', '!=', \App\Services\EmployeeService::OWNER));
    }

    public function isOwner(): bool
    {
        return $this->employment_type === \App\Services\EmployeeService::OWNER;
    }

    public function isTrainee(): bool
    {
        return $this->employment_type === \App\Services\EmployeeService::TRAINEE;
    }

    /** Employee-number order: EMP-01, EMP-02 … EMP-10 (not EMP-1, EMP-10, EMP-2). */
    public function scopeInCodeOrder($query)
    {
        return $query->reorder()->orderByRaw('LENGTH(employee_code)')->orderBy('employee_code');
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One company's payroll for a period (company_id null = the main company). */
class PayrollRun extends Model
{
    protected $fillable = ['company_id', 'period_starts_on', 'period_ends_on', 'status', 'created_by', 'locked_by', 'locked_at', 'needs_recalculation'];
    protected $casts = ['period_starts_on' => 'date', 'period_ends_on' => 'date', 'locked_at' => 'datetime', 'needs_recalculation' => 'boolean'];
    /** A login limited to one company sees only that company's payroll. */
    protected static function booted(): void
    {
        static::addGlobalScope('company', function (\Illuminate\Database\Eloquent\Builder $query) {
            if ($companyId = \App\Support\CompanyAccess::limitedCompanyId()) $query->where(fn ($q) => $q->forCompany(Company::payrollKey($companyId)));
        });
    }

    public function items(): HasMany { return $this->hasMany(PayrollItem::class); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }

    /** The company's name as shown on the payroll page. */
    public function companyName(): string
    {
        return $this->company?->name ?? (Company::main()?->name ?? 'Main company');
    }

    public function scopeForCompany($query, ?int $companyId)
    {
        return $companyId ? $query->where('company_id', $companyId) : $query->whereNull('company_id');
    }

    /**
     * Runs that close a day for this employee: a run with the employee in it, or a locked run of the employee's own
     * company (a locked payroll of another company does not freeze this employee's days).
     */
    public function scopeClosingFor($query, int $employeeId)
    {
        $company = Company::payrollKey(AttendanceEmployee::whereKey($employeeId)->value('company_id'));

        return $query->where(fn ($q) => $q->whereHas('items', fn ($i) => $i->where('employee_id', $employeeId))
            ->orWhere(fn ($q) => $q->where('status', 'locked')->forCompany($company)));
    }
}

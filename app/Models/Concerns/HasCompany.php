<?php

namespace App\Models\Concerns;

use App\Models\AttendanceEmployee;
use App\Models\Company;
use App\Support\CompanyAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * A list item a company may have of its own (department, team, leave type, shift, office, letter template) or that
 * belongs to one company (project, client, job opening). Empty company_id means "shared by all companies" for the
 * first kind and "the main company" for the second (see companyNullIsShared()).
 *
 * A login limited to one company sees the shared items and its own company's, never another company's; what it
 * adds is its company's; it cannot change or delete a shared item (the Super Admin's).
 */
trait HasCompany
{
    public static function bootHasCompany(): void
    {
        static::addGlobalScope('company', function (Builder $query) {
            $companyId = CompanyAccess::limitedCompanyId();
            if (! $companyId) return;
            $model = $query->getModel();
            $column = $model->qualifyColumn('company_id');
            $model->companyNullIsShared()
                ? $query->where(fn ($q) => $q->whereNull($column)->orWhere($column, $companyId))
                : CompanyAccess::whereCompany($query, $companyId, $column);
        });
        static::creating(function ($model) {
            if ($model->company_id === null && ($companyId = CompanyAccess::limitedCompanyId())) $model->company_id = $companyId;
        });
        $guard = function ($model) {
            if (CompanyAccess::limitedCompanyId() && $model->getOriginal('company_id') === null && $model->companyNullIsShared()) {
                abort(403, 'This is shared by all companies: only the Super Admin changes it. Add your own instead.');
            }
        };
        static::updating($guard);
        static::deleting($guard);
    }

    /** True: no company = shared by all companies. False: no company = the main company. */
    public function companyNullIsShared(): bool
    {
        return true;
    }

    /** False for a shared item when the login is limited to one company (only the Super Admin changes those). */
    public function changeable(): bool
    {
        return ! (CompanyAccess::limitedCompanyId() && $this->company_id === null && $this->companyNullIsShared());
    }

    public function company(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Items this company may use: its own and the shared ones (null company = no limit). */
    public function scopeForCompany(Builder $query, ?int $companyId): Builder
    {
        if (! $companyId) return $query;

        return $query->where(fn ($q) => $q->whereNull($this->qualifyColumn('company_id'))->orWhere($this->qualifyColumn('company_id'), $companyId));
    }

    /** Items an employee may use: their company's and the shared ones (no company on the employee = the main company). */
    public function scopeForEmployeeCompany(Builder $query, ?AttendanceEmployee $employee): Builder
    {
        return $employee ? $query->forCompany($employee->company_id ?? Company::main()?->id) : $query;
    }
}

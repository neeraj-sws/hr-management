<?php

namespace App\Models\Concerns;

use App\Models\AttendanceEmployee;
use App\Support\CompanyAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * A record that belongs to an employee (leave, attendance, payroll item, payslip, fine …): a login limited to one
 * company sees only the records of that company's employees. Logins of all companies see everything, as before.
 */
trait BelongsToEmployeeCompany
{
    public static function bootBelongsToEmployeeCompany(): void
    {
        static::addGlobalScope('company', function (Builder $query) {
            if (! CompanyAccess::limitedCompanyId()) return;
            $model = $query->getModel();
            // The employee query carries the company limit itself.
            $query->whereIn($model->qualifyColumn($model->companyEmployeeColumn()), AttendanceEmployee::query()->select('id'));
        });
    }

    /** The column holding the employee the record belongs to. */
    public function companyEmployeeColumn(): string
    {
        return 'employee_id';
    }
}

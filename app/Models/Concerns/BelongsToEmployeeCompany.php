<?php

namespace App\Models\Concerns;

use App\Models\AttendanceEmployee;
use App\Support\CompanyAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * A record that belongs to an employee (leave, attendance, payroll item, payslip, fine …), directly or through its
 * parent record (a salary payment through its payroll item): a login limited to one company sees only the records
 * of that company's employees. Logins of all companies see everything, as before.
 */
trait BelongsToEmployeeCompany
{
    public static function bootBelongsToEmployeeCompany(): void
    {
        static::addGlobalScope('company', function (Builder $query) {
            if (! CompanyAccess::limitedCompanyId()) return;
            $model = $query->getModel();
            [$column, $parent] = $model->companyOwner();
            // The parent query (the employee, or a record of one) carries the company limit itself.
            $query->whereIn($model->qualifyColumn($column), $parent::query()->select((new $parent)->getQualifiedKeyName()));
        });
    }

    /** @return array{0: string, 1: class-string} the column holding the owner, and the owner's model */
    public function companyOwner(): array
    {
        return ['employee_id', AttendanceEmployee::class];
    }
}

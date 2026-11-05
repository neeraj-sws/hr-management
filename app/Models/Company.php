<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company whose employees are paid by this HRMS (e.g. SWS, ITWeb). Payroll is calculated, approved and locked per
 * company. An employee or payroll run with no company belongs to the main company.
 */
class Company extends Model
{
    protected $fillable = ['name', 'is_main'];

    protected $casts = ['is_main' => 'boolean'];

    /** A login limited to one company does not see the other companies at all, not even their names. */
    protected static function booted(): void
    {
        static::addGlobalScope('company', function (\Illuminate\Database\Eloquent\Builder $query) {
            if ($companyId = \App\Support\CompanyAccess::limitedCompanyId()) $query->whereKey($companyId);
        });
    }

    public function employees(): HasMany
    {
        return $this->hasMany(AttendanceEmployee::class);
    }

    public static function main(): ?self
    {
        return static::withoutGlobalScopes()->where('is_main', true)->orderBy('id')->first();
    }

    /** The company id as payroll stores it: the main company is null (so runs made before companies stay its own). */
    public static function payrollKey(?int $companyId): ?int
    {
        if (! $companyId) return null;

        return static::withoutGlobalScopes()->whereKey($companyId)->value('is_main') ? null : $companyId;
    }
}

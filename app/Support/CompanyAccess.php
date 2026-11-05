<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Logins limited to one company (users.company_id, e.g. the ITWeb owner and the HR / Accounts / Manager logins he
 * makes) see that company's employees and their records only; everyone else (company empty, e.g. the Super Admin)
 * sees every company, as before. The limit is applied by global scopes on the employee and on each record that
 * belongs to an employee, so every page, report, export and API answer follows it without its own filter.
 */
class CompanyAccess
{
    /** Company the signed-in login is limited to; null = all companies (or nobody signed in: console, queue, login). */
    public static function limitedCompanyId(): ?int
    {
        // Only a login already resolved: resolving one here would query employees again (and loop).
        if (! Auth::hasUser()) return null;
        $user = Auth::user();

        return $user instanceof User && $user->company_id ? (int) $user->company_id : null;
    }

    public static function isLimited(?User $user): bool
    {
        return $user !== null && $user->company_id !== null;
    }

    /** Keeps rows of this company: the main company also owns rows with no company (everyone before companies). */
    public static function whereCompany(Builder $query, int $companyId, string $column): Builder
    {
        $isMain = (bool) Company::withoutGlobalScopes()->whereKey($companyId)->value('is_main');

        return $isMain
            ? $query->where(fn ($q) => $q->whereNull($column)->orWhere($column, $companyId))
            : $query->where($column, $companyId);
    }
}

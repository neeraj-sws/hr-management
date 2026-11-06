<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PolicyRule extends Model
{
    protected $fillable = ['company_id','rule_key','category','name','configuration','is_active','effective_from','effective_until','updated_by'];
    protected $casts = ['configuration'=>'array','is_active'=>'boolean','effective_from'=>'date','effective_until'=>'date'];

    /** company_id empty = the rule for all companies; set = that company's own rule. A company owner never sees another company's. */
    protected static function booted(): void
    {
        static::addGlobalScope('company', function (\Illuminate\Database\Eloquent\Builder $query) {
            if ($companyId = \App\Support\CompanyAccess::limitedCompanyId()) $query->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId));
        });
    }
}

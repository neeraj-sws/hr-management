<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EmployeeClearanceItem extends Model
{
    use Concerns\BelongsToEmployeeCompany;

    /** A login limited to one company sees this only when its EmployeeLifecycleCase is of that company. */
    public function companyOwner(): array { return ['lifecycle_case_id', EmployeeLifecycleCase::class]; }

    protected $fillable = ['lifecycle_case_id','item','status','cleared_by','cleared_at','notes'];
    protected $casts = ['cleared_at'=>'datetime'];
    public function lifecycleCase(){return $this->belongsTo(EmployeeLifecycleCase::class,'lifecycle_case_id');}
}

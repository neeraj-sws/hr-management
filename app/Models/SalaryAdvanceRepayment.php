<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SalaryAdvanceRepayment extends Model
{
    use Concerns\BelongsToEmployeeCompany;

    /** A login limited to one company sees this only when its SalaryAdvance is of that company. */
    public function companyOwner(): array { return ['salary_advance_id', SalaryAdvance::class]; }

    protected $fillable=['salary_advance_id','payroll_item_id','amount'];
    protected $casts=['amount'=>'decimal:2'];
    public function advance(){return $this->belongsTo(SalaryAdvance::class,'salary_advance_id');}
    public function payrollItem(){return $this->belongsTo(PayrollItem::class);}
}

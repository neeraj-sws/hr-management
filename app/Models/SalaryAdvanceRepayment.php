<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SalaryAdvanceRepayment extends Model
{
    protected $fillable=['salary_advance_id','payroll_item_id','amount'];
    protected $casts=['amount'=>'decimal:2'];
    public function advance(){return $this->belongsTo(SalaryAdvance::class,'salary_advance_id');}
    public function payrollItem(){return $this->belongsTo(PayrollItem::class);}
}

<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EmployeeAsset extends Model
{
    protected $fillable = ['employee_id','asset_tag','name','status','assigned_on','returned_on','condition_notes','resolution_note','recovery_adjustment_id'];
    protected $casts = ['assigned_on'=>'date','returned_on'=>'date'];
    public function employee(){return $this->belongsTo(AttendanceEmployee::class);}
    /** Assignment and return history, newest first (kept when the asset is returned or re-issued). */
    public function events(){return $this->hasMany(EmployeeAssetEvent::class)->latest('happened_on')->latest('id');}

    /** Adds a history step; the asset row itself only holds the current state. */
    public function recordEvent(string $action, ?int $employeeId, ?string $notes = null): EmployeeAssetEvent
    {
        return $this->events()->create(['employee_id' => $employeeId, 'action' => $action, 'happened_on' => today(), 'notes' => $notes ?: null, 'recorded_by' => auth()->id()]);
    }
}

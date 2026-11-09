<?php
namespace App\Models;
use App\Support\CompanyAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
class EmployeeAsset extends Model
{
    protected $fillable = ['company_id','employee_id','asset_tag','name','status','assigned_on','returned_on','condition_notes','resolution_note','recovery_adjustment_id'];
    protected $casts = ['assigned_on'=>'date','returned_on'=>'date'];

    protected static function booted(): void
    {
        static::addGlobalScope('company', function (Builder $query) {
            if (! $id = CompanyAccess::limitedCompanyId()) return;
            $query->where($query->getModel()->qualifyColumn('company_id'), $id);
        });
    }

    public function company() { return $this->belongsTo(Company::class); }
    public function employee() { return $this->belongsTo(AttendanceEmployee::class); }
    /** Assignment and return history, newest first (kept when the asset is returned or re-issued). */
    public function events() { return $this->hasMany(EmployeeAssetEvent::class)->latest('happened_on')->latest('id'); }

    /** Adds a history step; the asset row itself only holds the current state. */
    public function recordEvent(string $action, ?int $employeeId, ?string $notes = null): EmployeeAssetEvent
    {
        return $this->events()->create(['employee_id' => $employeeId, 'action' => $action, 'happened_on' => today(), 'notes' => $notes ?: null, 'recorded_by' => auth()->id()]);
    }
}

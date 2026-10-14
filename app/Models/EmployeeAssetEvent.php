<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One step in an asset's life: issued to someone, returned, lost, written off… Never deleted. */
class EmployeeAssetEvent extends Model
{
    protected $fillable = ['employee_asset_id', 'employee_id', 'action', 'happened_on', 'notes', 'recorded_by'];
    protected $casts = ['happened_on' => 'date'];

    public function asset() { return $this->belongsTo(EmployeeAsset::class, 'employee_asset_id'); }
    public function employee() { return $this->belongsTo(AttendanceEmployee::class); }
    public function recorder() { return $this->belongsTo(User::class, 'recorded_by'); }
}

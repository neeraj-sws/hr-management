<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceSession extends Model
{
    protected $fillable = [
        'employee_id',
        'device_id',
        'work_date',
        'check_in',
        'check_out',
        'active_seconds',
        'idle_seconds',
        'break_seconds',
        'status',
        'last_sync_at',
        'server_version',
        'client_payload_hash',
        'check_in_latitude',
        'check_in_longitude',
        'check_in_office_id',
        'check_in_distance_meters',
        'check_out_latitude',
        'check_out_longitude',
        'check_out_office_id',
        'check_out_distance_meters',
        'screen_active_seconds',
        'screen_idle_seconds',
        'screen_reported_at',
        'closed_automatically',
    ];

    protected $casts = [
        'work_date' => 'date',
        'check_in' => 'datetime',
        'check_out' => 'datetime',
        'last_sync_at' => 'datetime',
        'screen_reported_at' => 'datetime',
        'closed_automatically' => 'boolean',
        'server_version' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(AttendanceEmployee::class, 'employee_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'device_id');
    }

    public function checkInOffice(): BelongsTo { return $this->belongsTo(OfficeLocation::class, 'check_in_office_id'); }
    public function checkOutOffice(): BelongsTo { return $this->belongsTo(OfficeLocation::class, 'check_out_office_id'); }

    public function events(): HasMany
    {
        return $this->hasMany(AttendanceActivityEvent::class);
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'=>$this->id,
            'employee'=>['id'=>$this->employee?->id,'employee_code'=>$this->employee?->employee_code,'name'=>$this->employee?->name],
            'device_id'=>$this->device?->device_id,
            'work_date'=>$this->work_date?->toDateString(),
            'check_in'=>$this->check_in?->toIso8601String(),
            'check_out'=>$this->check_out?->toIso8601String(),
            'active_seconds'=>(int)$this->active_seconds,
            'idle_seconds'=>(int)$this->idle_seconds,
            'break_seconds'=>(int)$this->break_seconds,
            'screen_active_seconds'=>(int)$this->screen_active_seconds,
            'screen_idle_seconds'=>(int)$this->screen_idle_seconds,
            'screen_meeting_seconds'=>(int)$this->screen_meeting_seconds,
            'status'=>$this->status,
            'server_version'=>(int)$this->server_version,
            'events'=>$this->whenLoaded('events'),
        ];
    }
}

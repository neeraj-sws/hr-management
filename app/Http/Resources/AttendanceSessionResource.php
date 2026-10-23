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
            // Same figures as the website: away time answered as working is meeting time, as a break is not idle.
            ...(fn (array $t) => [
                'screen_active_seconds' => $t['active'],
                'screen_idle_seconds' => $t['idle'],
                'screen_meeting_seconds' => $t['meeting'],
                // Time the desktop app missed but an approved correction confirmed as working.
                'screen_corrected_seconds' => $t['corrected'],
                // Working time the desktop app did not see (closed, updating, opened after check-in).
                'screen_untracked_seconds' => $t['untracked'],
            ])($this->resource->screenTime()),
            // Worked time (in-out less break less idle, up to now while working) and idle, exactly as the website shows them.
            'worked_seconds' => app(\App\Services\AttendanceService::class)->workedSeconds($this->resource),
            'idle_counted_seconds' => $this->resource->idleSeconds(),
            'status'=>$this->status,
            'server_version'=>(int)$this->server_version,
            'events'=>$this->whenLoaded('events'),
        ];
    }
}

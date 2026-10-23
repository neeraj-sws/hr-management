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
            // Same figures as the website, as of this moment (AttendanceService::liveFigures): away time answered
            // as working is meeting time, as a break is not idle; 'live' says what keeps counting each second.
            ...(fn (array $f) => [
                'screen_active_seconds' => $f['screen']['active'],
                'screen_idle_seconds' => $f['screen']['idle'],
                'screen_meeting_seconds' => $f['screen']['meeting'],
                // Time the desktop app missed but an approved correction confirmed as working.
                'screen_corrected_seconds' => $f['screen']['corrected'],
                // Working time the desktop app did not see (closed, updating, opened after check-in).
                'screen_untracked_seconds' => $f['screen']['untracked'],
                'worked_seconds' => $f['worked'],
                'idle_counted_seconds' => $f['idle'],
                'live' => ['running' => $f['running'], 'state' => $f['state'], 'tick_for' => $f['tick_for'], 'in_out_seconds' => $f['in_out'], 'break_seconds' => $f['break'], 'idle_deducted' => $f['idle_deducted']],
            ])(app(\App\Services\AttendanceService::class)->liveFigures($this->resource)),
            'status'=>$this->status,
            'server_version'=>(int)$this->server_version,
            'events'=>$this->whenLoaded('events'),
        ];
    }
}

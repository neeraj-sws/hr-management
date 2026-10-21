<?php

namespace App\Livewire\Attendance;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceDevice;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Computers registered for attendance (desktop agent): who they belong to,
 * computer name, Windows user, app version, last seen, and access. Revoking
 * deletes the device token at once; allowing it again lets the computer
 * register again (it gets a fresh token at its next sign-in).
 */
class Devices extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $search = '';
    #[Url] public string $status = '';

    public function updated($property): void
    {
        if (in_array($property, ['search', 'status'], true)) $this->resetPage();
    }

    private function authorizeManage(): void
    {
        abort_unless($this->user()->canAny(['employees.manage', 'hr.manage']), 403);
    }

    public function revoke(int $id, AuditLogger $audit): void
    {
        $this->authorizeManage();
        $device = AttendanceDevice::with('employee')->findOrFail($id);
        DB::transaction(function () use ($device) {
            if ($device->personal_access_token_id) PersonalAccessToken::whereKey($device->personal_access_token_id)->delete();
            // A device left behind by a deleted employee cannot be updated (its employee link is broken), so it is removed.
            if (! $device->employee) $device->delete();
            else $device->forceFill(['personal_access_token_id' => null, 'is_active' => false])->save();
        });
        $audit->record('attendance.device_revoked', $device, ['employee_id' => $device->employee_id, 'computer_name' => $device->computer_name, 'windows_user' => $device->windows_user]);
        $this->toast('Access revoked for '.($device->computer_name ?: $device->device_id).'. It can no longer send attendance.');
    }

    public function activate(int $id, AuditLogger $audit): void
    {
        $this->authorizeManage();
        $device = AttendanceDevice::with('employee')->findOrFail($id);
        abort_if(! $device->employee?->is_active, 422, 'The employee for this device is inactive.');
        $device->forceFill(['is_active' => true])->save();
        $audit->record('attendance.device_activated', $device, ['employee_id' => $device->employee_id, 'computer_name' => $device->computer_name]);
        $this->toast('Device allowed again. Sign in on that computer once so it gets a new access token.');
    }

    public function render()
    {
        $this->authorizeManage();
        $term = trim($this->search);
        $devices = AttendanceDevice::with('employee')
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('computer_name', 'like', "%{$term}%")->orWhere('windows_user', 'like', "%{$term}%")->orWhere('device_id', 'like', "%{$term}%")
                ->orWhereHas('employee', fn ($e) => $e->where('name', 'like', "%{$term}%")->orWhere('employee_code', 'like', "%{$term}%"))))
            ->when($this->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($this->status === 'revoked', fn ($q) => $q->where('is_active', false))
            ->when($this->status === 'stale', fn ($q) => $q->where('is_active', true)->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subDays(3))))
            ->orderByDesc('last_seen_at')->orderByDesc('id')
            ->paginate(20);

        return $this->page('livewire.attendance.devices', [
            'devices' => $devices,
            'counts' => ['all' => AttendanceDevice::count(), 'active' => AttendanceDevice::where('is_active', true)->count(), 'online' => AttendanceDevice::where('is_active', true)->where('last_seen_at', '>=', now()->subMinutes(10))->count()],
        ], 'Devices');
    }
}

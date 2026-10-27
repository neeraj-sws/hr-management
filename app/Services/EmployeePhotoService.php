<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Saves and removes employee photos (private disk; served only to signed-in users). */
class EmployeePhotoService
{
    public const RULES = ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** The employee themself, or HR / employee managers / administrators. */
    public static function canChange(User $user, AttendanceEmployee $employee): bool
    {
        return $employee->user_id === $user->id || $user->canAny(['employees.manage', 'hr.manage']) || \App\Support\Access::isAdmin($user);
    }

    public function save(AttendanceEmployee $employee, UploadedFile $file, User $actor): void
    {
        abort_unless(self::canChange($actor, $employee), 403);
        $old = $employee->photo_path;
        $path = $file->store('employee-photos', 'local');
        $employee->update(['photo_path' => $path]);
        if ($old && $old !== $path) Storage::disk('local')->delete($old);
        $this->audit->record('employee.photo_changed', $employee, ['by' => $actor->name]);
    }

    public function remove(AttendanceEmployee $employee, User $actor): void
    {
        abort_unless(self::canChange($actor, $employee), 403);
        if ($employee->photo_path) Storage::disk('local')->delete($employee->photo_path);
        $employee->update(['photo_path' => null]);
        $this->audit->record('employee.photo_removed', $employee, ['by' => $actor->name]);
    }
}

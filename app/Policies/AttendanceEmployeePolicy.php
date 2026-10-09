<?php
namespace App\Policies;
use App\Models\AttendanceEmployee;
use App\Models\User;
class AttendanceEmployeePolicy
{
    public function view(User $user,AttendanceEmployee $employee):bool{return $user->can('hr.manage')||$user->can('employees.manage')||$employee->user_id===$user->id||$employee->manager?->user_id===$user->id;}
    public function update(User $user,AttendanceEmployee $employee):bool{return $user->can('hr.manage')||$user->can('employees.manage');}
    public function delete(User $user,AttendanceEmployee $employee):bool{return $user->can('hr.manage');}
}

<?php
namespace App\Policies;
use App\Models\AttendanceEmployee;
use App\Models\OvertimeRequest;
use App\Models\User;
class OvertimeRequestPolicy
{
    public function view(User $user,OvertimeRequest $request):bool{return $user->can('hr.manage')||$request->employee?->user_id===$user->id||$request->employee?->manager?->user_id===$user->id;}
    public function approve(User $user,OvertimeRequest $request):bool
    {
        if(!$user->can('overtime.manage'))return false;
        if($user->can('hr.manage')||$user->can('employees.manage'))return true;
        $employee=AttendanceEmployee::where('user_id',$user->id)->first();
        return $employee&&$request->employee?->manager_id===$employee->id;
    }
}

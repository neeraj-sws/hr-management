<?php
namespace App\Policies;
use App\Models\AttendanceEmployee;
use App\Models\EmployeeNote;
use App\Models\User;
class EmployeeNotePolicy
{
    public function view(User $user,EmployeeNote $note):bool
    {
        if($user->can('hr.manage')||$note->author_id===$user->id)return true;
        $employee=AttendanceEmployee::where('user_id',$user->id)->first();
        return ($note->visibility==='employee'&&$note->employee?->user_id===$user->id)
            ||($note->visibility==='manager'&&$employee&&$note->employee?->manager_id===$employee->id)
            ||($note->visibility==='team'&&$employee&&$note->team&&$employee->teams()->whereKey($note->team_id)->exists());
    }
    public function update(User $user,EmployeeNote $note):bool{return $note->author_id===$user->id||$user->can('hr.manage');}
    public function delete(User $user,EmployeeNote $note):bool{return $this->update($user,$note);}
}

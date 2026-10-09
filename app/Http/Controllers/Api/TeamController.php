<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeamResource;
use App\Models\AttendanceEmployee;
use App\Models\Team;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function index(Request $request)
    {
        $employee=AttendanceEmployee::where('user_id',$request->user()->id)->first();
        $query=Team::with(['department','manager','members'])->where('is_active',true);
        if(!$request->user()->can('teams.manage')&&!$request->user()->can('hr.manage')){
            abort_unless($employee,403);
            $query->whereHas('members',fn($q)=>$q->whereKey($employee->id));
        }
        return TeamResource::collection($query->orderBy('name')->paginate(50));
    }

    public function store(Request $request,AuditLogger $audit)
    {
        abort_unless($request->user()->can('teams.manage'),403);
        $data=$request->validate(['name'=>['required','string','max:255','unique:teams,name'],'description'=>['nullable','string'],'department_id'=>['nullable','exists:departments,id'],'manager_employee_id'=>['nullable','exists:attendance_employees,id'],'employee_ids'=>['array'],'employee_ids.*'=>['integer','exists:attendance_employees,id']]);
        $team=Team::create(['name'=>$data['name'],'description'=>$data['description']??null,'department_id'=>$data['department_id']??null,'manager_employee_id'=>$data['manager_employee_id']??null,'is_active'=>true]);
        app(\App\Services\EmployeeService::class)->syncTeamMembers($team,$data['employee_ids']??[],$request->user());$audit->record('team.created',$team,['members'=>$team->members()->count()]);
        return (new TeamResource($team->load(['department','manager','members'])))->response()->setStatusCode(201);
    }
}

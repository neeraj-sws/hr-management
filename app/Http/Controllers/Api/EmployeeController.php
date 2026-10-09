<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Models\AttendanceEmployee;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $user=$request->user();
        abort_unless($user->can('employees.manage')||$user->can('hr.manage')||$user->can('leave.approve'),403);
        $query=AttendanceEmployee::with(['department','manager'])->where('is_active',true);
        if(!$user->can('employees.manage')&&!$user->can('hr.manage')){
            $profile=AttendanceEmployee::where('user_id',$user->id)->firstOrFail();
            $query->where(fn($q)=>$q->whereKey($profile->id)->orWhere('manager_id',$profile->id));
        }
        return EmployeeResource::collection($query->orderBy('name')->paginate(50));
    }

    public function show(Request $request, AttendanceEmployee $employee)
    {
        abort_unless($request->user()->can('view',$employee),403);
        return new EmployeeResource($employee->load(['department','manager']));
    }
}

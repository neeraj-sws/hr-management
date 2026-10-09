<?php
namespace App\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class EmployeeResource extends JsonResource
{
    public function toArray(Request $request):array{return ['id'=>$this->id,'employee_code'=>$this->employee_code,'name'=>$this->name,'email'=>$this->email,'is_active'=>$this->is_active,'department'=>$this->department?['id'=>$this->department->id,'name'=>$this->department->name]:null,'manager'=>$this->manager?['id'=>$this->manager->id,'name'=>$this->manager->name]:null,'joined_at'=>$this->joined_at?->toDateString()];}
}

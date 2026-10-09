<?php
namespace App\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class TeamResource extends JsonResource
{
    public function toArray(Request $request):array{return ['id'=>$this->id,'name'=>$this->name,'description'=>$this->description,'department'=>$this->department?->name,'manager'=>$this->manager?->name,'members'=>$this->members->map(fn($employee)=>['id'=>$employee->id,'name'=>$employee->name])];}
}

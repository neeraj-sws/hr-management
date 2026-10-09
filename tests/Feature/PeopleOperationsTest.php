<?php

namespace Tests\Feature;

use App\Livewire\Lifecycle\Cases;
use App\Livewire\Lifecycle\Settlements;
use App\Livewire\Notes\Notes;
use App\Livewire\Security\Vault;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\AttendanceDevice;
use App\Models\ApprovalRequest;
use App\Models\CredentialVaultEntry;
use App\Models\EmployeePenalty;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\PolicyRule;
use App\Models\SalaryAdvance;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\HrWorkflowService;
use App\Services\PeopleOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PeopleOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_people_center_renders_and_vault_secrets_are_encrypted(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->actingAs($admin)->get('/security/vault')->assertOk()->assertSee('Credential Vault');
        $livewire=Livewire::test(Vault::class)->call('create')->set('vaultCategory','login')->set('label','test')->set('username','user')->set('secret','clear-secret')->set('visibility','private')->call('save')->assertHasNoErrors();
        $livewire->assertDontSee('clear-secret');
        $entry=CredentialVaultEntry::where('label','test')->firstOrFail();
        $stored = $entry->getRawOriginal('secret_value');
        $this->assertNotSame('clear-secret', $stored);
        $this->assertSame('clear-secret', $entry->fresh()->secret_value);
        $livewire->call('reveal',$entry->id)->assertSee('clear-secret')->assertHasNoErrors();
        $this->assertDatabaseHas('audit_logs',['action'=>'credential.accessed','subject_id'=>$entry->id]);
        $livewire->call('delete',$entry->id)->assertHasNoErrors();
        $this->assertDatabaseMissing('credential_vault_entries',['id'=>$entry->id]);
    }

    public function test_employee_vault_visibility_is_limited_to_explicit_employee_and_team_records(): void
    {
        $this->seed();
        $admin=User::where('email','admin@example.com')->firstOrFail();
        $employeeUser=User::factory()->create();$employeeUser->assignRole('Employee');
        $employee=AttendanceEmployee::create(['employee_code'=>'VAULT-EMP','name'=>'Vault Employee','is_active'=>true,'user_id'=>$employeeUser->id]);
        $team=\App\Models\Team::create(['name'=>'Vault Team','is_active'=>true]);$team->members()->attach($employee->id);
        $owned=CredentialVaultEntry::create(['created_by'=>$admin->id,'owner_employee_id'=>$employee->id,'category'=>'login','label'=>'Employee portal','username'=>'employee','secret_value'=>'employee-secret','visibility'=>'employee']);
        $shared=CredentialVaultEntry::create(['created_by'=>$admin->id,'team_id'=>$team->id,'category'=>'service','label'=>'Team service','secret_value'=>'team-secret','visibility'=>'team']);
        $hr=CredentialVaultEntry::create(['created_by'=>$admin->id,'category'=>'database','label'=>'HR database','secret_value'=>'hr-only-secret','visibility'=>'hr']);
        $this->actingAs($employeeUser);
        $component=Livewire::test(Vault::class)->assertSee('Employee portal')->assertSee('Team service')->assertDontSee('HR database');
        $component->call('reveal',$owned->id)->assertSee('employee-secret')->assertHasNoErrors();
        $component->call('reveal',$shared->id)->assertSee('team-secret')->assertHasNoErrors();
        $component->call('reveal',$hr->id)->assertStatus(403);
        $this->assertDatabaseHas('audit_logs',['action'=>'credential.access_denied','subject_id'=>$hr->id]);
        // A private secret is only for its creator: not even HR or vault managers may reveal it.
        $private=CredentialVaultEntry::create(['created_by'=>$employeeUser->id,'category'=>'login','label'=>'My private','secret_value'=>'mine-only','visibility'=>'private']);
        $hrUser=User::factory()->create();$hrUser->assignRole('HR');
        $this->assertFalse($hrUser->can('view',$private));
        $this->assertFalse($admin->can('view',$private));
        $this->assertTrue($employeeUser->can('view',$private));
    }

    public function test_monthly_leave_accrual_is_idempotent_and_pending_leave_cannot_overbook(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $employeeUser = User::factory()->create();
        $employee = AttendanceEmployee::create(['employee_code'=>'ACCRUAL-1','name'=>'Accrual Employee','is_active'=>true,'user_id'=>$employeeUser->id]);
        $team=\App\Models\Team::create(['name'=>'Accrual Team','is_active'=>true]);
        $team->members()->attach($employee->id);
        $this->actingAs($employeeUser)->get('/notes')->assertOk()->assertSee('My Notes');
        $type = LeaveType::create(['name'=>'Accrued PTO','annual_allowance'=>0,'monthly_accrual'=>1.5,'carry_forward'=>true,'carry_forward_limit'=>3,'allow_advance'=>false,'paid'=>true,'is_active'=>true]);
        $balance = LeaveBalance::create(['employee_id'=>$employee->id,'leave_type_id'=>$type->id,'year'=>2026,'entitled_days'=>0]);
        $people = app(PeopleOperationsService::class);
        $people->accrueLeave(2026, 4, $admin->id);
        $people->accrueLeave(2026, 4, $admin->id);
        $this->assertSame('6.000', $balance->fresh()->accrued_days);

        $workflow = app(HrWorkflowService::class);
        $first = $workflow->requestLeave($employee, ['leave_type_id'=>$type->id,'starts_on'=>now()->parse('2026-05-04'),'ends_on'=>now()->parse('2026-05-07'),'reason'=>'first']);
        $this->assertSame('pending', $first->status);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $workflow->requestLeave($employee, ['leave_type_id'=>$type->id,'starts_on'=>now()->parse('2026-05-11'),'ends_on'=>now()->parse('2026-05-14'),'reason'=>'over balance']);
    }

    public function test_payroll_uses_approved_ot_penalties_adjustments_and_disbursed_advance_recovery(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $employee = AttendanceEmployee::create(['employee_code'=>'PAY-1','name'=>'Payroll Employee','is_active'=>true]);
        SalaryProfile::create(['employee_id'=>$employee->id,'monthly_salary'=>22000,'effective_from'=>'2026-10-01','configured_by'=>$admin->id,'working_days_per_month'=>22,'standard_daily_hours'=>8]);
        SalaryProfile::create(['employee_id'=>$employee->id,'monthly_salary'=>50000,'effective_from'=>'2026-11-01','configured_by'=>$admin->id,'working_days_per_month'=>20,'standard_daily_hours'=>7.5]);
        OvertimeRequest::create(['employee_id'=>$employee->id,'work_date'=>'2026-10-05','requested_minutes'=>120,'approved_minutes'=>120,'reason'=>'release','status'=>'approved']);
        EmployeePenalty::create(['employee_id'=>$employee->id,'effective_on'=>'2026-10-05','amount'=>100,'reason'=>'policy penalty','status'=>'approved']);
        \App\Models\SalaryAdjustment::create(['employee_id'=>$employee->id,'effective_on'=>'2026-10-05','amount'=>200,'kind'=>'earning','reason'=>'bonus','status'=>'approved']);
        $advance=SalaryAdvance::create(['employee_id'=>$employee->id,'amount'=>1000,'recovered_amount'=>0,'disbursed_amount'=>1000,'installment_amount'=>250,'requested_on'=>'2026-10-01','disbursed_on'=>'2026-10-02','status'=>'disbursed','reason'=>'advance']);
        $run=app(HrWorkflowService::class)->calculatePayroll('2026-10-01','2026-10-31',$admin->id);
        $item=PayrollItem::where('payroll_run_id',$run->id)->where('employee_id',$employee->id)->firstOrFail();
        $this->assertSame(250.0,(float)$item->recovery_amount);
        $this->assertSame(375.0,(float)$item->overtime_amount);
        $this->assertSame(100.0,(float)$item->penalties_amount);
        $this->assertSame(200.0,(float)$item->adjustments_amount);
        $this->assertSame(225.0,(float)$item->net_amount);
        $this->assertSame(250.0,(float)$advance->fresh()->recovered_amount);
        $this->assertSame(1000.0,(float)$item->calculation_breakdown['advances_disbursed']);
        $workflow=app(HrWorkflowService::class);
        $workflow->rejectPayroll($run,$admin->id,'Verify salary basis');
        $this->assertSame('2026-10-01',$run->fresh()->period_starts_on->toDateString());
        $this->assertDatabaseHas('approval_requests',['approvable_type'=>PayrollRun::class,'approvable_id'=>$run->id,'status'=>'rejected','comment'=>'Verify salary basis']);
        $workflow->calculatePayroll('2026-10-01','2026-10-31',$admin->id);
        $this->assertSame(2,ApprovalRequest::where('approvable_type',PayrollRun::class)->where('approvable_id',$run->id)->count());
        $workflow->approvePayroll($run,$admin->id);
        $workflow->lockPayroll($run,$admin->id);
        $this->assertSame('locked',$run->fresh()->status);
    }

    public function test_compliance_assessment_creates_an_idempotent_audited_penalty_approval(): void
    {
        $this->seed();
        $admin=User::where('email','admin@example.com')->firstOrFail();
        $employee=AttendanceEmployee::create(['employee_code'=>'LATE-1','name'=>'Late Employee','is_active'=>true,'work_start'=>'09:00:00']);
        AttendanceSession::create(['employee_id'=>$employee->id,'work_date'=>'2026-10-05','check_in'=>'2026-10-05 09:20:00','status'=>'working']);
        PolicyRule::where('rule_key','attendance.late_penalty')->update(['configuration'=>['amount_per_minute'=>2,'grace_minutes'=>5,'max_amount'=>500]]);
        $this->assertSame(2.0,(float)PolicyRule::where('rule_key','attendance.late_penalty')->first()->configuration['amount_per_minute']);
        $this->assertSame(1,AttendanceSession::whereDate('work_date','2026-10-05')->whereNotNull('check_in')->count());
        $service=app(PeopleOperationsService::class);
        $this->assertSame(1,$service->assessCompliance('2026-10-05','2026-10-05',$admin->id));
        $this->assertSame(0,$service->assessCompliance('2026-10-05','2026-10-05',$admin->id));
        $penalty=EmployeePenalty::firstOrFail();
        $this->assertSame('30.00',$penalty->amount);
        $this->assertSame('pending',$penalty->status);
        $this->assertDatabaseHas('approval_requests',['approvable_type'=>EmployeePenalty::class,'approvable_id'=>$penalty->id,'status'=>'pending']);
        $this->assertDatabaseHas('audit_logs',['action'=>'compliance.late_penalty_assessed','subject_id'=>$penalty->id]);
    }

    public function test_overtime_request_requires_manager_decision_and_records_approval_history(): void
    {
        $this->seed();
        $managerUser=User::factory()->create();$managerUser->assignRole('Manager');
        $manager=AttendanceEmployee::create(['employee_code'=>'OT-MGR','name'=>'OT Manager','is_active'=>true,'user_id'=>$managerUser->id]);
        $employee=AttendanceEmployee::create(['employee_code'=>'OT-EMP','name'=>'OT Employee','is_active'=>true,'manager_id'=>$manager->id]);
        $service=app(PeopleOperationsService::class);
        $request=$service->requestOvertime($employee,'2026-10-05',90,'Urgent release work');
        $this->assertSame($managerUser->id,ApprovalRequest::where('approvable_id',$request->id)->where('approvable_type',OvertimeRequest::class)->value('approver_id'));
        $approved=$service->decideOvertime($request,$managerUser->id,true,75,'Reviewed');
        $this->assertSame('approved',$approved->status);
        $this->assertSame(75,$approved->approved_minutes);
        $this->assertDatabaseHas('approval_requests',['approvable_type'=>OvertimeRequest::class,'approvable_id'=>$request->id,'status'=>'approved','comment'=>'Reviewed']);
        $this->assertDatabaseHas('notifications',['notifiable_type'=>User::class,'notifiable_id'=>$managerUser->id]);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->decideOvertime($approved,$managerUser->id,false);
    }

    public function test_notes_respect_personal_manager_and_team_visibility_and_are_audited(): void
    {
        $this->seed();
        $managerUser=User::factory()->create();$managerUser->assignRole('Manager');
        $manager=AttendanceEmployee::create(['employee_code'=>'NOTE-MGR','name'=>'Notes Manager','is_active'=>true,'user_id'=>$managerUser->id]);
        $employeeUser=User::factory()->create();
        $employee=AttendanceEmployee::create(['employee_code'=>'NOTE-EMP','name'=>'Notes Employee','is_active'=>true,'user_id'=>$employeeUser->id,'manager_id'=>$manager->id]);
        $team=\App\Models\Team::create(['name'=>'Notes Team','is_active'=>true]);$team->members()->attach($employee->id);

        $this->actingAs($managerUser);
        Livewire::test(Notes::class)->call('create')->set('visibility','manager')->set('employeeId',$employee->id)->set('title','Coaching')->set('body','Private manager feedback')->call('save')->assertHasNoErrors();
        Livewire::test(Notes::class)->call('create')->set('visibility','personal')->set('title','Manager private')->set('body','Only visible to author')->call('save')->assertHasNoErrors();
        $this->actingAs($employeeUser);
        Livewire::test(Notes::class)->assertSee('Private manager feedback')->assertDontSee('Only visible to author');
        Livewire::test(Notes::class)->call('create')->set('visibility','team')->set('teamId',$team->id)->set('title','Team update')->set('body','Shared with team members')->call('save')->assertHasNoErrors();
        $teamNote=\App\Models\EmployeeNote::where('title','Team update')->firstOrFail();
        Livewire::test(Notes::class)->call('edit',$teamNote->id)->set('body','Updated team note')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('audit_logs',['action'=>'note.updated','subject_id'=>$teamNote->id]);
        $outsider=User::factory()->create();
        $this->actingAs($outsider);
        Livewire::test(Notes::class)->assertDontSee('Updated team note')->assertDontSee('Private manager feedback')->assertDontSee('Only visible to author');
        Livewire::test(Notes::class)->call('delete',$teamNote->id)->assertStatus(403);
    }

    public function test_lifecycle_offboarding_requires_clearance_and_approved_final_settlement(): void
    {
        $this->seed();
        $admin=User::where('email','admin@example.com')->firstOrFail();$this->actingAs($admin);
        $employeeUser=User::factory()->create(['email'=>'leaving@example.com','password'=>'LeavingPass123!']);
        $employee=AttendanceEmployee::create(['employee_code'=>'EXIT-1','name'=>'Leaving Employee','is_active'=>true,'user_id'=>$employeeUser->id]);
        $userToken=$employeeUser->createToken('offboarding-user-token');
        $deviceToken=$employee->createToken('offboarding-device-token',['attendance:sync']);
        $device=AttendanceDevice::create(['employee_id'=>$employee->id,'device_id'=>'EXIT-DEVICE','is_active'=>true,'personal_access_token_id'=>$deviceToken->accessToken->id]);
        $case=app(PeopleOperationsService::class)->openLifecycle($employee,'offboarding','2026-10-31','End of contract',$admin->id);
        \App\Models\EmployeeAsset::create(['employee_id'=>$employee->id,'asset_tag'=>'EXIT-LAP','name'=>'Laptop','status'=>'assigned','assigned_on'=>'2026-01-01']);
        $center=Livewire::test(Cases::class)->set('case',$case->id);
        $center->call('close')->assertHasErrors('clearance');
        Livewire::test(Settlements::class)->set('case',$case->id)->call('prepare')->assertHasErrors('settlement');
        foreach($case->clearances as $item)$center->call('clearItem',$item->id)->assertHasNoErrors();
        Livewire::test(Settlements::class)->set('case',$case->id)->call('prepare')->assertHasErrors('settlement'); // laptop still with the employee
        $center->call('close')->assertHasErrors('clearance'); // asset not yet returned
        $asset=\App\Models\EmployeeAsset::where('asset_tag','EXIT-LAP')->firstOrFail();
        $center->call('returnAsset',$asset->id)->assertHasNoErrors();
        Livewire::test(Settlements::class)->set('case',$case->id)->call('prepare')->assertHasNoErrors();
        $settlement=$case->settlement()->firstOrFail();
        $center->call('close')->assertHasErrors('settlement'); // settlement still pending approval
        Livewire::test(Settlements::class)->set('case',$case->id)->call('approve')->assertHasNoErrors();
        $center->call('close')->assertHasNoErrors();
        $this->assertSame('closed',$case->fresh()->status);
        $this->assertFalse($employee->fresh()->is_active);
        $this->assertFalse($employeeUser->fresh()->is_active);
        $this->assertDatabaseMissing('personal_access_tokens',['id'=>$userToken->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens',['id'=>$deviceToken->accessToken->id]);
        $this->assertFalse($device->fresh()->is_active);
        \Illuminate\Support\Facades\Auth::logout();
        $this->post('/login',['email'=>'leaving@example.com','password'=>'LeavingPass123!'])->assertSessionHasErrors('email');
        $this->assertDatabaseHas('audit_logs',['action'=>'employee.final_settlement_approved','subject_id'=>$settlement->id]);
    }
}

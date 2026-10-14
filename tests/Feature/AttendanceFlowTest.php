<?php

namespace Tests\Feature;

use App\Livewire\Attendance\Dashboard;
use App\Models\AttendanceActivityEvent;
use App\Models\AttendanceDevice;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Livewire\Livewire;
use Tests\TestCase;

class AttendanceFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_attendance_sync_is_idempotent_and_listable(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::firstOrCreate(['name'=>'attendance.view','guard_name'=>'web']));
        $user->givePermissionTo(Permission::firstOrCreate(['name'=>'employees.manage','guard_name'=>'web']));
        $adminToken = $user->createToken('attendance-admin')->plainTextToken;
        $employee = AttendanceEmployee::create([
            'employee_code' => 'EMP-1001',
            'name' => 'Test Employee',
            'is_active' => true,
        ]);
        $payload = $this->validPayload($employee->employee_code);
        $deviceToken = $this->registerDevice($adminToken, $employee->employee_code, $payload['device_id']);
        Auth::forgetGuards();

        $syncResponse = $this->withToken($deviceToken)->postJson('/api/attendance/sync', $payload);
        $syncResponse->assertOk()
            ->assertJsonPath('success', true);

        $this->withToken($deviceToken)->postJson('/api/attendance/sync', $payload)
            ->assertOk();

        $this->assertSame(1, AttendanceSession::count());
        $this->assertSame(1, AttendanceActivityEvent::count());

        Auth::forgetGuards();
        $this->withToken($adminToken)->getJson('/api/attendance?work_date='.$payload['session']['work_date'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee.employee_code', $employee->employee_code);
    }

    public function test_sync_rejects_invalid_payload_and_requires_authentication(): void
    {
        $this->postJson('/api/attendance/sync', [])->assertUnauthorized();

        $user = User::factory()->create();
        $user->givePermissionTo(Permission::firstOrCreate(['name'=>'employees.manage','guard_name'=>'web']));
        $adminToken = $user->createToken('attendance-admin')->plainTextToken;
        $employee = AttendanceEmployee::create([
            'employee_code' => 'EMP-INVALID-TEST',
            'name' => 'Invalid Payload Employee',
            'is_active' => true,
        ]);
        $token = $this->registerDevice($adminToken, $employee->employee_code, 'INVALID-DEVICE');

        $this->withToken($token)->postJson('/api/attendance/sync', [])->assertUnprocessable();
        $this->withToken($adminToken)->getJson('/api/attendance')->assertOk();
    }

    public function test_offline_sync_receipts_are_idempotent_and_stale_versions_conflict(): void
    {
        $admin=User::factory()->create();
        $admin->givePermissionTo(Permission::firstOrCreate(['name'=>'attendance.view','guard_name'=>'web']));
        $admin->givePermissionTo(Permission::firstOrCreate(['name'=>'employees.manage','guard_name'=>'web']));
        $adminToken=$admin->createToken('attendance-admin')->plainTextToken;
        $employee=AttendanceEmployee::create(['employee_code'=>'EMP-OFFLINE','name'=>'Offline Employee','is_active'=>true]);
        $payload=$this->validPayload($employee->employee_code);
        $deviceToken=$this->registerDevice($adminToken,$employee->employee_code,$payload['device_id']);
        Auth::forgetGuards();

        $payload['sync_id']='offline-batch-001';
        $payload['base_version']=0;
        $this->withToken($deviceToken)->postJson('/api/attendance/sync',$payload)->assertOk()->assertJsonPath('server_version',1)->assertJsonPath('replayed',false);
        $this->withToken($deviceToken)->postJson('/api/attendance/sync',$payload)->assertOk()->assertJsonPath('replayed',true);
        $changed=$payload;$changed['session']['active_seconds']=999;
        $this->withToken($deviceToken)->postJson('/api/attendance/sync',$changed)->assertStatus(409);

        $next=$payload;$next['sync_id']='offline-batch-002';$next['base_version']=1;$next['session']['active_seconds']=50;
        $this->withToken($deviceToken)->postJson('/api/attendance/sync',$next)->assertOk()->assertJsonPath('server_version',2);
        $stale=$next;$stale['sync_id']='offline-batch-003';$stale['base_version']=1;$stale['session']['active_seconds']=60;
        $this->withToken($deviceToken)->postJson('/api/attendance/sync',$stale)->assertStatus(409)->assertJsonPath('conflict',true);
        $this->assertSame(1,AttendanceSession::count());
        $this->assertSame(1,AttendanceActivityEvent::count());
        Auth::forgetGuards();
        $this->withToken($adminToken)->getJson('/api/attendance')->assertOk()->assertJsonPath('data.0.employee.employee_code',$employee->employee_code)->assertJsonMissingPath('data.0.employee.monthly_salary');
    }

    public function test_device_tokens_are_bound_revocable_and_registration_requires_authentication(): void
    {
        $employee = AttendanceEmployee::create([
            'employee_code' => 'EMP-SECURE',
            'name' => 'Secure Employee',
            'is_active' => true,
        ]);

        $this->postJson('/api/attendance/device/register', [])->assertUnauthorized();

        $unprivileged = User::factory()->create();
        $unprivilegedToken = $unprivileged->createToken('unprivileged')->plainTextToken;
        $this->withToken($unprivilegedToken)->postJson('/api/attendance/device/register', ['employee_code' => 'EMP-SECURE', 'device_id' => 'UNAUTHORIZED-DEVICE'])->assertForbidden();
        $this->withToken($unprivilegedToken)->deleteJson('/api/attendance/device/SECURE-DEVICE')->assertForbidden();
        Auth::forgetGuards();

        $admin = User::factory()->create();
        $admin->givePermissionTo(Permission::firstOrCreate(['name'=>'employees.manage','guard_name'=>'web']));
        $adminToken = $admin->createToken('attendance-admin')->plainTextToken;
        $employeeToken = $this->registerDevice($adminToken, $employee->employee_code, 'SECURE-DEVICE');
        Auth::forgetGuards();

        $rotatedToken = $this->registerDevice($adminToken, $employee->employee_code, 'SECURE-DEVICE');
        Auth::forgetGuards();
        $this->withToken($employeeToken)->postJson('/api/attendance/sync', $this->validPayload($employee->employee_code))
            ->assertUnauthorized();
        Auth::forgetGuards();
        $employeeToken = $rotatedToken;

        $mismatchResponse = $this->withToken($employeeToken)->postJson('/api/attendance/sync', $this->validPayload('SOMEONE-ELSE'));
        $mismatchResponse->assertForbidden()
            ->assertJsonPath('message', 'Employee authorization mismatch.');

        $validPayload = $this->validPayload($employee->employee_code);
        $validPayload['device_id'] = 'UNKNOWN-DEVICE';
        $this->withToken($employeeToken)->postJson('/api/attendance/sync', $validPayload)->assertForbidden();
        $this->withToken($employeeToken)->getJson('/api/attendance')->assertOk()->assertJsonCount(0,'data');

        $validPayload['device_id'] = 'SECURE-DEVICE';
        $this->withToken($employeeToken)->postJson('/api/attendance/sync', $validPayload)
            ->assertOk();

        Auth::forgetGuards();
        $this->withToken($adminToken)->deleteJson('/api/attendance/device/SECURE-DEVICE')
            ->assertOk();

        Auth::forgetGuards();
        $this->withToken($employeeToken)->postJson('/api/attendance/sync', $validPayload)
            ->assertUnauthorized();
    }

    public function test_spatie_role_permissions_are_available_and_enforced(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::firstOrCreate(['name'=>'attendance.view','guard_name'=>'web']));
        $permission = Permission::firstOrCreate(['name' => 'employees.manage', 'guard_name' => 'web']);
        $role = Role::create(['name' => 'Attendance Manager', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        $this->actingAs($user)->get('/attendance/records')->assertForbidden();
        $this->actingAs($user)->get('/attendance/team')->assertOk();

        $user->assignRole($role);
        $this->actingAs($user->fresh())->get('/attendance/records')->assertOk();
    }

    public function test_login_and_authenticated_dashboard_render(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'attendance.view', 'guard_name' => 'web']));
        $employee = AttendanceEmployee::create([
            'employee_code' => 'EMP-DASH',
            'name' => 'Dashboard Employee',
            'is_active' => true,
            'user_id' => $user->id,
        ]);
        $device = AttendanceDevice::create([
            'employee_id' => $employee->id,
            'device_id' => 'DASH-DEVICE',
            'is_active' => true,
        ]);
        AttendanceSession::create([
            'employee_id' => $employee->id,
            'device_id' => $device->id,
            'work_date' => now()->toDateString(),
            'check_in' => now(),
            'active_seconds' => 60,
            'status' => 'working',
        ]);

        $this->get('/login')->assertOk();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('SWS HR')
            ->assertSee('Checked in today')
            ->assertSee('Checked out today')
            ->assertSee('On lunch / break now')
            ->assertSee('Dashboard');
        $this->get('/attendance/team')
            ->assertOk()
            ->assertSeeLivewire(Dashboard::class)
            ->assertSee('Main navigation')
            ->assertSee('Dashboard Employee')
            ->assertSee('EMP-DASH');

        Livewire::test(Dashboard::class)
            ->assertSee('Team Attendance')
            ->assertSee('Dashboard Employee')
            ->set('search', 'nobody-matches')
            ->assertSee('No employees match these filters')
            ->set('search', '')
            ->assertSee('Dashboard Employee');

        $this->post('/logout')->assertRedirect(route('login'));
    }

    public function test_employee_attendance_page_and_sidebar_are_scoped_to_their_own_profile(): void
    {
        $employeeUser = User::factory()->create(['name' => 'Scoped Employee']);
        $own = AttendanceEmployee::create([
            'employee_code' => 'EMP-OWN', 'name' => 'Own Attendance', 'user_id' => $employeeUser->id, 'is_active' => true,
        ]);
        $other = AttendanceEmployee::create([
            'employee_code' => 'EMP-OTHER', 'name' => 'Other Attendance', 'is_active' => true,
        ]);
        foreach ([$own, $other] as $employee) {
            AttendanceSession::create([
                'employee_id' => $employee->id,
                'work_date' => today()->toDateString(),
                'check_in' => now(),
                'status' => 'working',
            ]);
        }

        $this->actingAs($employeeUser)->get('/attendance/team')->assertForbidden();
        $this->actingAs($employeeUser)
            ->get('/attendance/my')
            ->assertOk()
            ->assertSee('My Attendance')
            ->assertSee('Worked today')
            ->assertDontSee('Other Attendance')
            ->assertDontSee('people/employees');
        $token = $employeeUser->createToken('scoped')->plainTextToken;
        $this->withToken($token)->getJson('/api/attendance')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee.employee_code', 'EMP-OWN');
    }

    private function validPayload(string $employeeCode): array
    {
        return [
            'employee_id' => $employeeCode,
            'device_id' => 'TEST-DEVICE-1',
            'session' => [
                'work_date' => now()->toDateString(),
                'check_in' => now()->toDateTimeString(),
                'active_seconds' => 21,
                'idle_seconds' => 3,
                'break_seconds' => 0,
                'status' => 'working',
            ],
            'events' => [[
                'event_type' => 'activity',
                'event_time' => now()->toDateTimeString(),
                'duration_seconds' => 4,
                'metadata' => ['source' => 'feature-test'],
            ]],
        ];
    }

    private function registerDevice(string $adminToken, string $employeeCode, string $deviceId): string
    {
        $response = $this->withToken($adminToken)->postJson('/api/attendance/device/register', [
            'employee_code' => $employeeCode,
            'device_id' => $deviceId,
            'computer_name' => 'Feature Test PC',
            'app_version' => 'test',
        ])->assertCreated();

        return $response->json('token');
    }
}

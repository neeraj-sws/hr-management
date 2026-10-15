<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceActivityEvent;
use App\Models\AttendanceDevice;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\User;
use App\Http\Resources\AttendanceSessionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

class AttendanceSyncController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor=$request->user();
        abort_unless($actor instanceof User || $actor instanceof AttendanceEmployee, 403, 'An authenticated SWS account is required.');

        $query = AttendanceSession::query()->with(['employee', 'device', 'events']);

        if ($actor instanceof AttendanceEmployee) {
            abort_unless($actor->tokenCan('attendance:read') || $actor->tokenCan('attendance:sync'),403,'This device token cannot read attendance.');
            $query->where('employee_id',$actor->id);
        } elseif ($actor->canAny(['hr.manage','employees.manage'])) {
            // HR may read across the organisation; user-token abilities mirror the user's permissions.
        } elseif ($actor->employeeProfile && $actor->canAny(['attendance.view','reports.view'])) {
            $visibleIds=$actor->employeeProfile->directReports()->pluck('id')->push($actor->employeeProfile->id);
            $query->whereIn('employee_id',$visibleIds);
        } else {
            abort_unless($actor->employeeProfile,403,'Attendance read permission is required.');
            $query->where('employee_id',$actor->employeeProfile->id);
        }

        if ($request->filled('work_date')) {
            $validated = $request->validate(['work_date' => ['date']]);
            $query->whereDate('work_date', $validated['work_date']);
        }

        if ($request->filled('employee_id')) {
            $query->whereHas('employee', fn ($employee) => $employee->where('employee_code', $request->query('employee_id')));
        }

        $sessions=$query->latest('check_in')->get();
        return response()->json([
            'success' => true,
            'data' => AttendanceSessionResource::collection($sessions)->resolve(),
        ]);
    }

    public function sync(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => ['required', 'string', 'max:100'],
            'device_id' => ['required', 'string', 'max:100'],
            'session' => ['required', 'array'],
            'session.work_date' => ['required', 'date'],
            'session.check_in' => ['required', 'date'],
            'session.check_out' => ['nullable', 'date'],
            'session.active_seconds' => ['integer', 'min:0'],
            'session.idle_seconds' => ['integer', 'min:0'],
            'session.break_seconds' => ['integer', 'min:0'],
            'session.status' => ['nullable', 'string', 'max:30'],
            'events' => ['array'],
            'sync_id' => ['nullable','string','max:100'],
            'base_version' => ['nullable','integer','min:0'],
            'events.*.event_type' => ['required', 'string', 'max:50'],
            'events.*.event_time' => ['required', 'date'],
            'events.*.duration_seconds' => ['integer', 'min:0'],
            'events.*.metadata' => ['nullable'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid attendance payload.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $events = $data['events'] ?? [];
        usort($events, fn ($a,$b) => [$a['event_time'],$a['event_type']] <=> [$b['event_time'],$b['event_type']]);
        $payloadHash = hash('sha256', json_encode(['session'=>$data['session'],'events'=>$events,'base_version'=>$data['base_version']??null], JSON_THROW_ON_ERROR));
        $user = $request->user();

        if (! $user instanceof AttendanceEmployee || ! $user->tokenCan('attendance:sync')) {
            return response()->json([
                'success' => false,
                'message' => 'An attendance device token is required.',
            ], 403);
        }

        $accessToken = $user->currentAccessToken();

        if (! $accessToken instanceof PersonalAccessToken || $user->employee_code !== $data['employee_id']) {
            return response()->json([
                'success' => false,
                'message' => 'Employee authorization mismatch.',
            ], 403);
        }

        $employee = AttendanceEmployee::query()
            ->whereKey($user->getKey())
            ->where('is_active', true)
            ->first();

        if (! $employee) {
            return response()->json(['success' => false, 'message' => 'Employee is inactive.'], 403);
        }

        $device = AttendanceDevice::query()
            ->where('device_id', $data['device_id'])
            ->where('employee_id', $employee->id)
            ->where('personal_access_token_id', $accessToken->getKey())
            ->where('is_active', true)
            ->first();

        if (! $device) {
            return response()->json([
                'success' => false,
                'message' => 'Device is not registered for this employee and token.',
            ], 403);
        }

        $result = DB::transaction(function () use ($data, $employee, $device, $payloadHash) {
            // Serializing writes per employee protects the daily unique session key.
            $employee = AttendanceEmployee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $device = AttendanceDevice::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $device->forceFill(['last_seen_at' => now()])->save();

            if (!empty($data['sync_id'])) {
                $receipt=DB::table('attendance_sync_receipts')->where('attendance_device_id',$device->id)->where('sync_id',$data['sync_id'])->first();
                if($receipt){
                    if(!hash_equals($receipt->payload_hash,$payloadHash))return ['conflict'=>true,'message'=>'This sync ID was already used with a different payload.','session'=>AttendanceSession::find($receipt->attendance_session_id)];
                    return ['replay'=>true,'session'=>AttendanceSession::with('employee','device','events')->findOrFail($receipt->attendance_session_id)];
                }
            }

            $payload = $data['session'];

            $session = AttendanceSession::query()
                ->where('employee_id', $employee->id)
                ->whereDate('work_date', $payload['work_date'])
                ->lockForUpdate()
                ->first();
            $isNew=!$session;
            // An administrator's check-out is final for the day: a device cannot move or reopen it.
            if(!$isNew && $session->forced_checkout_at){
                return ['conflict'=>true,'message'=>'An administrator checked you out at '.$session->check_out?->format('h:i A').' ('.$session->forcedReasonLabel().'). Ask for an attendance correction if this is wrong.','session'=>$session->load('employee','device','events')];
            }
            $session ??= new AttendanceSession([
                'employee_id' => $employee->id,
                'work_date' => $payload['work_date'],
            ]);
            $currentVersion=(int)($session->server_version??0);
            if(array_key_exists('base_version',$data) && (int)$data['base_version']!==$currentVersion){
                return ['conflict'=>true,'message'=>'Attendance changed on the server since this device last synced.','session'=>$session->load('employee','device','events')];
            }
            if(!$isNew && !array_key_exists('base_version',$data)){
                // Legacy clients get monotonic aggregation so a stale offline value cannot roll server counters backward.
                $payload['check_in']=min($session->check_in->toDateTimeString(),$payload['check_in']);
                if($session->check_out||!empty($payload['check_out']))$payload['check_out']=max((string)$session->check_out,(string)($payload['check_out']??$session->check_out));
                $payload['active_seconds']=max((int)$session->active_seconds,(int)($payload['active_seconds']??0));
                $payload['idle_seconds']=max((int)$session->idle_seconds,(int)($payload['idle_seconds']??0));
                $payload['break_seconds']=max((int)$session->break_seconds,(int)($payload['break_seconds']??0));
                if($session->check_out)$payload['status']='completed';
            }
            $session->fill([
                'device_id' => $device->id,
                'check_in' => $payload['check_in'],
                'check_out' => $payload['check_out'] ?? null,
                'active_seconds' => $payload['active_seconds'] ?? 0,
                'idle_seconds' => $payload['idle_seconds'] ?? 0,
                'break_seconds' => $payload['break_seconds'] ?? 0,
                'status' => $payload['status'] ?? 'working',
                'last_sync_at' => now(),
                'server_version' => $currentVersion+1,
                'client_payload_hash' => $payloadHash,
                // The address the desktop app synced from: kept from the first sync of the day, and at check-out.
                'check_in_ip' => $session->check_in_ip ?? request()->ip(),
                'check_out_ip' => $session->check_out_ip ?? (! empty($payload['check_out']) ? request()->ip() : null),
            ])->save();

            foreach ($data['events'] ?? [] as $event) {
                AttendanceActivityEvent::firstOrCreate(
                    [
                        'attendance_session_id' => $session->id,
                        'event_type' => $event['event_type'],
                        'event_time' => $event['event_time'],
                    ],
                    [
                        'duration_seconds' => $event['duration_seconds'] ?? 0,
                        'metadata' => is_array($event['metadata'] ?? null)
                            ? $event['metadata']
                            : null,
                    ]
                );
            }

            if(!empty($data['sync_id']))DB::table('attendance_sync_receipts')->insert(['attendance_device_id'=>$device->id,'sync_id'=>$data['sync_id'],'payload_hash'=>$payloadHash,'attendance_session_id'=>$session->id,'created_at'=>now()]);
            return ['session'=>$session->load('employee','device','events'),'replay'=>false];
        });

        if(!empty($result['conflict']))return response()->json(['success'=>false,'conflict'=>true,'message'=>$result['message'],'server'=>new \App\Http\Resources\AttendanceSessionResource($result['session'])],409);
        $synced=$result['session'];

        return response()->json([
            'success' => true,
            'message' => ($result['replay']??false)?'Attendance sync replay acknowledged.':'Attendance synced successfully.',
            'attendance_id' => $synced->id,
            'server_version' => (int)$synced->server_version,
            'replayed' => (bool)($result['replay']??false),
            'data' => new \App\Http\Resources\AttendanceSessionResource($synced),
        ]);
    }
}

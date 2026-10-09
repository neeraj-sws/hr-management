<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceEmployee;
use App\Models\DailyWorkStatus;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\HrWorkflowService;
use Illuminate\Support\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class OfflineActionSyncController extends Controller
{
    private const ATTENDANCE_ACTIONS = ['attendance.check_in', 'attendance.break_start', 'attendance.break_end', 'attendance.check_out'];

    public function sync(Request $request, HrWorkflowService $workflow, AttendanceService $attendance): JsonResponse
    {
        $rawActions = $request->input('actions', []);
        $validator = Validator::make($request->all(), ['actions' => ['required', 'array', 'min:1', 'max:50'], 'actions.*.action_id' => ['required', 'string', 'max:100'], 'actions.*.type' => ['required', 'in:attendance.session,attendance.check_in,attendance.break_start,attendance.break_end,attendance.check_out,daily_status.submit,task.start,task.pause,task.resume,task.complete'], 'actions.*.payload.occurred_at' => ['nullable', 'date'], 'actions.*.entity_id' => ['nullable', 'integer'], 'actions.*.base_version' => ['nullable', 'integer', 'min:0'], 'actions.*.payload' => ['nullable', 'array'], 'actions.*.payload.work_date' => ['nullable', 'date'], 'actions.*.payload.summary' => ['nullable', 'string', 'max:5000']]);
        if ($validator->fails()) return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        foreach ($validator->validated()['actions'] as $index => $action) {
            if (str_starts_with($action['type'], 'task.') && (!isset($action['base_version']) || empty($action['entity_id']))) return response()->json(['success' => false, 'errors' => ["actions.{$index}" => ['Task actions require entity_id and the last server_version.']]], 422);
            if (in_array($action['type'], self::ATTENDANCE_ACTIONS, true)) {
                $at = isset($action['payload']['occurred_at']) ? Carbon::parse($action['payload']['occurred_at']) : null;
                if (! $at || $at->gt(now()->addMinutes(5)) || $at->lt(now()->subHours(48))) return response()->json(['success' => false, 'errors' => ["actions.{$index}.payload.occurred_at" => ['Attendance actions require occurred_at within the last 48 hours.']]], 422);
            }
            if ($action['type'] === 'daily_status.submit' && (!isset($action['base_version']) || empty($action['payload']['work_date']) || empty($action['payload']['summary']))) return response()->json(['success' => false, 'errors' => ["actions.{$index}" => ['Daily status actions require work_date, summary and the last server_version.']]], 422);
        }
        $actor = $request->user();
        abort_unless($actor instanceof User || $actor instanceof AttendanceEmployee, 403);
        $employee = $actor instanceof AttendanceEmployee ? $actor : $actor->employeeProfile;
        if (!$employee || !$employee->is_active) return response()->json(['success' => false, 'message' => 'An active employee profile is required for these actions.'], 403);

        $results = [];
        foreach ($validator->validated()['actions'] as $index => $action) {
            $hash = hash('sha256', json_encode($this->canonical($rawActions[$index] ?? $action), JSON_THROW_ON_ERROR));
            try {
                $result = DB::transaction(function () use ($actor, $employee, $action, $hash, $request, $workflow, $attendance) {
                    $lockModel = $actor instanceof User ? User::query() : AttendanceEmployee::query();
                    $lockModel->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
                    $receipt = DB::table('offline_action_receipts')->where('actor_type', $actor::class)->where('actor_id', $actor->getKey())->where('action_id', $action['action_id'])->first();
                    if ($receipt) {
                        if (!hash_equals($receipt->payload_hash, $hash)) return ['http' => 409, 'data' => ['success' => false, 'conflict' => true, 'message' => 'The action ID was already used with a different payload.']];
                        return ['http' => 200, 'data' => array_merge(json_decode($receipt->response_data, true), ['replayed' => true])];
                    }
                    if ($action['type'] === 'attendance.session') {
                        abort_unless($actor instanceof AttendanceEmployee && $actor->tokenCan('attendance:sync'), 403, 'Attendance session sync requires the registered device token.');
                        $payload = $action['payload'] ?? [];
                        $syncPayload = array_merge($payload, ['sync_id' => $action['action_id']]);
                        if (isset($action['base_version'])) $syncPayload['base_version'] = $action['base_version'];
                        $request->replace($syncPayload);
                        $response = app(AttendanceSyncController::class)->sync($request);
                        $body = $response->getData(true);
                        if ($response->getStatusCode() === 409) return ['http' => 409, 'data' => $body + ['conflict' => true]];
                        if (!$response->isSuccessful()) return ['http' => $response->getStatusCode(), 'data' => $body];
                    } else {
                        abort_unless($actor instanceof User && $actor->employeeProfile?->id === $employee->id, 403, 'A signed-in employee account is required for work actions.');
                    }
                    if (in_array($action['type'], self::ATTENDANCE_ACTIONS, true)) {
                        $at = Carbon::parse($action['payload']['occurred_at']);
                        $source = in_array($action['payload']['source'] ?? null, ['mobile', 'desktop', 'web'], true) ? $action['payload']['source'] : 'mobile';
                        $location = isset($action['payload']['latitude'], $action['payload']['longitude']) ? array_intersect_key($action['payload'], array_flip(['latitude', 'longitude', 'accuracy'])) : null;
                        $session = match ($action['type']) {
                            'attendance.check_in' => $attendance->checkIn($employee, $source, $at, $location),
                            'attendance.break_start' => $attendance->startBreak($employee, $source, $at),
                            'attendance.break_end' => $attendance->endBreak($employee, $source, $at),
                            'attendance.check_out' => $attendance->checkOut($employee, $source, $at, $location),
                        };
                        $body = ['success' => true, 'action_id' => $action['action_id'], 'attendance_id' => $session->id, 'session_status' => $session->status, 'server_version' => (int) $session->server_version];
                    } elseif ($action['type'] === 'daily_status.submit') {
                        $status = DailyWorkStatus::where('employee_id', $employee->id)->whereDate('work_date', $action['payload']['work_date'])->lockForUpdate()->first();
                        $currentVersion = (int) ($status?->server_version ?? 0);
                        if ((int) $action['base_version'] !== $currentVersion) return ['http' => 409, 'data' => ['success' => false, 'conflict' => true, 'server_version' => $currentVersion, 'server' => $status ? ['work_date' => $status->work_date->toDateString(), 'summary' => $status->summary, 'server_version' => $currentVersion] : null, 'message' => 'Daily status changed on the server. Refresh before retrying.']];
                        $extra = ['plan' => $action['payload']['plan'] ?? null, 'blockers' => $action['payload']['blockers'] ?? null, 'notes' => $action['payload']['notes'] ?? null, 'submitted_at' => isset($action['payload']['occurred_at']) ? Carbon::parse($action['payload']['occurred_at']) : now()];
                        if ($status) $status->update([...$extra, 'summary' => $action['payload']['summary'], 'submitted_by' => $actor instanceof User ? $actor->id : $employee->user_id, 'server_version' => $currentVersion + 1]);
                        else $status = DailyWorkStatus::create([...$extra, 'employee_id' => $employee->id, 'work_date' => $action['payload']['work_date'], 'summary' => $action['payload']['summary'], 'submitted_by' => $actor instanceof User ? $actor->id : $employee->user_id, 'server_version' => 1]);
                        $body = ['success' => true, 'action_id' => $action['action_id'], 'work_date' => $status->work_date->toDateString(), 'summary' => $status->summary, 'server_version' => (int) $status->server_version];
                    } elseif (str_starts_with($action['type'], 'task.')) {
                        $task = Task::whereKey($action['entity_id'] ?? 0)->lockForUpdate()->firstOrFail();
                        if ($task->assignee_id !== $employee->id) abort(403, 'Only the assigned employee can sync task actions.');
                        if ((int) $action['base_version'] !== (int) $task->server_version) return ['http' => 409, 'data' => ['success' => false, 'conflict' => true, 'server_version' => (int) $task->server_version, 'server' => ['task_id' => $task->id, 'title' => $task->title, 'status' => $task->status, 'server_version' => (int) $task->server_version, 'updated_at' => $task->updated_at?->toISOString()], 'message' => 'Task changed on the server. Refresh before retrying.']];
                        if ($action['type'] === 'task.start' || $action['type'] === 'task.resume') {
                            $entry = $workflow->startTask($employee, $task);
                        } else {
                            $entry = TaskTimeEntry::where('task_id', $task->id)->where('employee_id', $employee->id)->where('status', 'running')->lockForUpdate()->firstOrFail();
                            $entry = $workflow->stopTaskTimer($employee, $entry, $action['type'] === 'task.complete');
                        }
                        $task->refresh();
                        $data = ['success' => true, 'action_id' => $action['action_id'], 'task_id' => $task->id, 'task_status' => $task->status, 'server_version' => (int) $task->server_version, 'time_entry_id' => $entry->id];
                        $body = $data;
                    }
                    DB::table('offline_action_receipts')->insert(['actor_type' => $actor::class, 'actor_id' => $actor->getKey(), 'action_id' => $action['action_id'], 'payload_hash' => $hash, 'status' => 'processed', 'response_data' => json_encode($body, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                    return ['http' => 200, 'data' => $body + ['replayed' => false]];
                });
            } catch (HttpExceptionInterface $exception) {
                $result = ['http' => $exception->getStatusCode(), 'data' => ['success' => false, 'message' => $exception->getMessage()]];
            } catch (ValidationException $exception) {
                $ignored = $this->alreadyApplied($action, $actor, $attendance);
                if ($ignored) {
                    // The server already reached this state (duplicate tap, or HR acted first): not an error, nothing changes.
                    $body = ['success' => true, 'ignored' => true, 'action_id' => $action['action_id'], 'message' => $ignored];
                    DB::table('offline_action_receipts')->insertOrIgnore(['actor_type' => $actor::class, 'actor_id' => $actor->getKey(), 'action_id' => $action['action_id'], 'payload_hash' => $hash, 'status' => 'ignored', 'response_data' => json_encode($body, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                    app(\App\Services\AuditLogger::class)->record('attendance.offline_duplicate_ignored', null, ['type' => $action['type'], 'action_id' => $action['action_id'], 'reason' => $ignored]);
                    $result = ['http' => 200, 'data' => $body];
                } else {
                    $result = ['http' => $exception->status === 409 ? 409 : 422, 'data' => ['success' => false, 'message' => 'Offline action validation failed.', 'errors' => $exception->errors()]];
                }
            } catch (QueryException $exception) {
                $receipt = DB::table('offline_action_receipts')->where('actor_type', $actor::class)->where('actor_id', $actor->getKey())->where('action_id', $action['action_id'])->first();
                if (!$receipt) throw $exception;
                $result = hash_equals($receipt->payload_hash, $hash) ? ['http' => 200, 'data' => array_merge(json_decode($receipt->response_data, true), ['replayed' => true])] : ['http' => 409, 'data' => ['success' => false, 'conflict' => true, 'message' => 'The action ID was already used with a different payload.']];
            }
            $results[] = ['action_id' => $action['action_id'], 'status' => $result['http'], ...$result['data']];
        }
        $overall = collect($results)->contains(fn ($row) => $row['status'] >= 400) ? 207 : 200;
        return response()->json(['success' => $overall === 200, 'results' => $results], $overall);
    }

    public function status(Request $request): JsonResponse
    {
        $data = $request->validate(['action_ids' => ['required', 'array', 'min:1', 'max:100'], 'action_ids.*' => ['string', 'max:100']]);
        $actor = $request->user();
        $rows = DB::table('offline_action_receipts')->where('actor_type', $actor::class)->where('actor_id', $actor->getKey())->whereIn('action_id', $data['action_ids'])->get(['action_id', 'status', 'response_data', 'created_at']);
        return response()->json(['success' => true, 'actions' => $rows->map(fn ($row) => ['action_id' => $row->action_id, 'status' => $row->status, 'response' => json_decode($row->response_data, true), 'synced_at' => $row->created_at])]);
    }

    private function canonical(array $value): array
    {
        if (array_is_list($value)) return array_map(fn ($item) => is_array($item) ? $this->canonical($item) : $item, $value);
        ksort($value);
        foreach ($value as $key => $item) if (is_array($item)) $value[$key] = $this->canonical($item);
        return $value;
    }

    /** Why an attendance action that failed is really a duplicate (state already reached), or null. */
    private function alreadyApplied(array $action, mixed $actor, AttendanceService $attendance): ?string
    {
        if (! in_array($action['type'], self::ATTENDANCE_ACTIONS, true) || ! ($actor instanceof User) || ! $actor->employeeProfile) return null;
        $employee = $actor->employeeProfile;
        $at = Carbon::parse($action['payload']['occurred_at'] ?? now());
        $open = $attendance->openSession($employee, $at);
        $day = \App\Models\AttendanceSession::where('employee_id', $employee->id)->whereDate('work_date', $at->copy()->setTimezone(config('app.timezone'))->toDateString())->latest('id')->first();

        return match ($action['type']) {
            'attendance.check_in' => ($open || $day) ? 'Already checked in.' : null,
            'attendance.check_out' => (! $open && $day?->check_out) ? 'Already checked out at '.$day->check_out->format('h:i A').'.' : null,
            'attendance.break_start' => $open?->status === 'on_break' ? 'Break already started.' : null,
            'attendance.break_end' => $open?->status === 'working' ? 'Break already ended.' : null,
            default => null,
        };
    }
}

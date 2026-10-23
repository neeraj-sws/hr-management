<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceEmployee;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Base for endpoints used by signed-in people (web/mobile/desktop user tokens, not device tokens). */
abstract class ApiController extends Controller
{
    protected function apiUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403, 'A user API token is required for this endpoint.');

        return $user;
    }

    protected function apiEmployee(Request $request): AttendanceEmployee
    {
        $employee = $this->apiUser($request)->employeeProfile;
        abort_unless($employee && $employee->is_active, 403, 'An active employee profile is required.');

        return $employee;
    }

    /**
     * ?month=YYYY-MM → [first day, last day] as Y-m-d, so a history list shows one month at a time.
     * Null when no month is sent (older app versions still get the whole list).
     */
    /** ?date=YYYY-MM-DD (one day picked in the app's filter), or null. */
    protected function dayFilter(Request $request): ?string
    {
        $date = (string) $request->query('date', '');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) ? $date : null;
    }

    protected function monthRange(Request $request): ?array
    {
        $month = (string) $request->query('month', '');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) return null;
        $start = \Illuminate\Support\Carbon::parse($month.'-01');

        return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
    }

    /**
     * Runs a create action at most once per X-Action-Id header (sent by the
     * mobile offline queue). A replay with the same body returns the first
     * response; the same id with a different body is a 409 conflict.
     */
    protected function once(Request $request, callable $create): JsonResponse
    {
        $actionId = $request->header('X-Action-Id');
        if (! $actionId) return $create();
        abort_if(strlen($actionId) > 100, 422, 'X-Action-Id is too long.');
        $user = $this->apiUser($request);
        $body = $request->except('receipt');
        ksort($body);
        $hash = hash('sha256', $request->path().'|'.json_encode($body).'|'.($request->file('receipt')?->getSize() ?? ''));
        $receipts = fn () => DB::table('offline_action_receipts')->where('actor_type', $user::class)->where('actor_id', $user->id)->where('action_id', $actionId)->first();
        $replay = function ($receipt) use ($hash) {
            if (! hash_equals($receipt->payload_hash, $hash)) return response()->json(['success' => false, 'conflict' => true, 'message' => 'This offline item was already sent with different details.'], 409);

            return response()->json(array_merge(json_decode($receipt->response_data, true), ['replayed' => true]));
        };
        if ($receipt = $receipts()) return $replay($receipt);

        $response = $create();
        if ($response->isSuccessful()) {
            try {
                DB::table('offline_action_receipts')->insert(['actor_type' => $user::class, 'actor_id' => $user->id, 'action_id' => $actionId, 'payload_hash' => $hash, 'status' => 'processed', 'response_data' => json_encode($response->getData(true)), 'created_at' => now(), 'updated_at' => now()]);
            } catch (QueryException) {
                return $replay($receipts());
            }
        }

        return $response;
    }

    protected function requirePermission(Request $request, string ...$permissions): User
    {
        $user = $this->apiUser($request);
        abort_unless($user->canAny($permissions), 403, 'You do not have permission for this action.');

        return $user;
    }
}

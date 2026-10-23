<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDevice;
use App\Models\AttendanceEmployee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class AttendanceDeviceController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        abort_unless($request->user() instanceof User, 403, 'An authenticated SWS user must provision devices.');
        abort_unless($request->user()->can('employees.manage'), 403, 'Employee management permission is required to provision attendance devices.');

        $data = $request->validate([
            'employee_code' => ['required', 'string', 'max:100'],
            'device_id' => ['required', 'string', 'max:100'],
            'computer_name' => ['nullable', 'string', 'max:255'],
            'windows_user' => ['nullable', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:50'],
        ]);

        $employee = AttendanceEmployee::query()
            ->where('employee_code', $data['employee_code'])
            ->where('is_active', true)
            ->firstOrFail();

        $plainTextToken = DB::transaction(function () use ($data, $employee) {
            $device = AttendanceDevice::query()->firstOrNew(['device_id' => $data['device_id']]);

            if ($device->personal_access_token_id) {
                PersonalAccessToken::query()->whereKey($device->personal_access_token_id)->delete();
            }

            $device->fill([
                'employee_id' => $employee->id,
                'computer_name' => $data['computer_name'] ?? null,
                'windows_user' => $data['windows_user'] ?? null,
                'app_version' => $data['app_version'] ?? null,
                'last_seen_at' => now(),
                'is_active' => true,
                'personal_access_token_id' => null,
            ])->save();

            $newToken = $employee->createToken(
                'attendance-device-'.$device->id,
                ['attendance:sync']
            );

            $device->forceFill([
                'personal_access_token_id' => $newToken->accessToken->getKey(),
            ])->save();

            return $newToken->plainTextToken;
        });

        return response()->json([
            'success' => true,
            'employee_id' => $employee->employee_code,
            'device_id' => $data['device_id'],
            'token' => $plainTextToken,
        ], 201);
    }

    public function revoke(Request $request, string $deviceId): JsonResponse
    {
        abort_unless($request->user() instanceof User, 403, 'An authenticated SWS user must revoke devices.');
        abort_unless($request->user()->can('employees.manage'), 403, 'Employee management permission is required to revoke attendance devices.');

        $device = AttendanceDevice::query()->where('device_id', $deviceId)->firstOrFail();

        DB::transaction(function () use ($device) {
            if ($device->personal_access_token_id) {
                PersonalAccessToken::query()->whereKey($device->personal_access_token_id)->delete();
            }

            // A device left behind by a deleted employee cannot be updated (broken employee link), so it is removed.
            if (! $device->employee()->exists()) {
                $device->delete();

                return;
            }
            $device->forceFill([
                'personal_access_token_id' => null,
                'is_active' => false,
            ])->save();
        });

        return response()->json(['success' => true, 'message' => 'Device access revoked.']);
    }
}

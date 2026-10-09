<?php

namespace App\Http\Controllers\Api;

use App\Models\ApprovalRequest;
use App\Services\ApprovalInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovalController extends ApiController
{
    public function __construct(private readonly ApprovalInbox $inbox) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);

        return response()->json(['data' => $this->inbox->pending($user)->map(fn (ApprovalRequest $a) => [
            'id' => $a->id,
            'type' => class_basename($a->approvable_type),
            'label' => $this->inbox->typeLabel($a->approvable_type),
            'summary' => $this->inbox->summary($a),
            'record_id' => $a->approvable_id,
            'created_at' => $a->created_at?->toIso8601String(),
        ])->values()]);
    }

    public function decide(Request $request, ApprovalRequest $approval): JsonResponse
    {
        $user = $this->apiUser($request);
        $data = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'required_if:approve,false', 'string', 'max:1000']]);
        $this->inbox->decide($user, $approval, (bool) $data['approve'], $data['note'] ?? null);
        $approval->refresh();

        return response()->json(['data' => ['id' => $approval->id, 'status' => $approval->status, 'decided_at' => $approval->decided_at?->toIso8601String()]]);
    }
}

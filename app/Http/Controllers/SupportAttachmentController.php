<?php

namespace App\Http\Controllers;

use App\Models\SupportAttachment;
use App\Models\User;
use App\Services\SupportService;
use Illuminate\Http\Request;

/** Opens a Help & Support attachment: on the website (signed in) or from the app through a short-lived signed link. */
class SupportAttachmentController extends Controller
{
    public function show(Request $request, SupportAttachment $attachment, SupportService $support): mixed
    {
        abort_unless($support->canOpenAttachment($request->user(), $attachment->load(['ticket.employee', 'ticket.category', 'message'])), 403);

        return $support->attachmentResponse($attachment, $request->boolean('download'));
    }

    public function signed(Request $request, SupportAttachment $attachment, SupportService $support): mixed
    {
        $user = User::where('is_active', true)->find((int) $request->query('user'));
        abort_unless($support->canOpenAttachment($user, $attachment->load(['ticket.employee', 'ticket.category', 'message'])), 403);

        return $support->attachmentResponse($attachment, $request->boolean('download'));
    }
}

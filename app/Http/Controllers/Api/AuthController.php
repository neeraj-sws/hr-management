<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function token(Request $request)
    {
        $data=$request->validate(['email'=>['required','string','max:255'],'password'=>['required','string'],'device_name'=>['required','string','max:100']]);
        $user=User::findForLogin($data['email']); // e-mail or employee ID
        if(!$user||!$user->is_active||!Hash::check($data['password'],$user->password)){
            // Why an app sign-in failed (never the password), for support and security review.
            app(\App\Services\AuditLogger::class)->record('auth.api_login_failed', $user, ['email' => $data['email'], 'reason' => !$user ? 'unknown_email' : (!$user->is_active ? 'inactive' : 'wrong_password'), 'device' => $data['device_name']]);
            throw ValidationException::withMessages(['email'=>'These credentials do not match our records.']);
        }
        $abilities=$user->getAllPermissions()->pluck('name')->values()->all();
        if($user->hasRole('Master Admin'))$abilities=['*'];
        return response()->json(['token'=>$user->createToken($data['device_name'],$abilities,now()->addDays((int) config('sanctum.user_token_days', 30)))->plainTextToken,'token_type'=>'Bearer','abilities'=>$abilities]);
    }

    public function revoke(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['success'=>true]);
    }
}

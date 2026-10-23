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
        $data=$request->validate(['email'=>['required','string','max:255'],'password'=>['required','string'],'device_name'=>['required','string','max:100'],'challenge'=>['nullable','string','max:64'],'otp'=>['nullable','string','max:12']]);
        $user=User::findForLogin($data['email']); // e-mail or employee ID
        if(!$user||!$user->is_active||!Hash::check($data['password'],$user->password)){
            // Why an app sign-in failed (never the password), for support and security review.
            app(\App\Services\AuditLogger::class)->record('auth.api_login_failed', $user, ['email' => $data['email'], 'reason' => !$user ? 'unknown_email' : (!$user->is_active ? 'inactive' : 'wrong_password'), 'device' => $data['device_name']]);
            throw ValidationException::withMessages(['email'=>'These credentials do not match our records.']);
        }
        // 2-step sign-in in the apps (Settings > Security): the first call e-mails a code and answers
        // 202 {otp_required, challenge}; the app sends the same sign-in again with challenge + otp.
        $twoFactor=app(\App\Services\TwoFactorService::class);
        if($twoFactor->requiredFor($user,'app')){
            if(empty($data['otp'])||empty($data['challenge'])){
                try{
                    $challenge=$twoFactor->start($user,'app');
                }catch(\Throwable $error){
                    report($error);
                    throw ValidationException::withMessages(['email'=>'Your sign-in code could not be e-mailed. Try again in a minute, or ask HR to check the e-mail settings.']);
                }
                return response()->json(['otp_required'=>true,'challenge'=>$challenge,'message'=>'We e-mailed a 6-digit code to '.$twoFactor->maskedEmail($user).'. It works for '.\App\Services\TwoFactorService::CODE_MINUTES.' minutes.'],202);
            }
            $owner=$twoFactor->userFor($data['challenge']);
            if($owner&&$owner->id!==$user->id)throw ValidationException::withMessages(['otp'=>'This code belongs to another sign-in. Sign in again.']);
            try{
                $twoFactor->verify($data['challenge'],$data['otp']);
            }catch(ValidationException $error){
                throw ValidationException::withMessages(['otp'=>$error->errors()['code'][0]??'That code is not right.']);
            }
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

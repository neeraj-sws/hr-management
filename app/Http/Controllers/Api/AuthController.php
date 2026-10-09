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
        $data=$request->validate(['email'=>['required','email'],'password'=>['required','string'],'device_name'=>['required','string','max:100']]);
        $user=User::where('email',$data['email'])->first();
        if(!$user||!$user->is_active||!Hash::check($data['password'],$user->password))throw ValidationException::withMessages(['email'=>'These credentials do not match our records.']);
        $abilities=$user->getAllPermissions()->pluck('name')->values()->all();
        if($user->hasRole('Master Admin'))$abilities=['*'];
        return response()->json(['token'=>$user->createToken($data['device_name'],$abilities)->plainTextToken,'token_type'=>'Bearer','abilities'=>$abilities]);
    }

    public function revoke(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['success'=>true]);
    }
}

@extends('auth.layout')
@section('title', 'Reset password')
@section('card')
    <h2>Reset your password</h2>
    <p class="muted" style="margin-top:0">Enter your work email. If it belongs to an active account, we will send a link to choose a new password.</p>
    @if(session('status'))<div class="alert alert-success" role="status">{{ session('status') }}</div>@endif
    <form method="POST" action="{{ route('password.email') }}" class="stack" style="margin-top:22px">
        @csrf
        @if($errors->any())<div class="alert alert-error" role="alert">{{ $errors->first() }}</div>@endif
        <label class="field"><span>Email</span><input name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"></label>
        <button type="submit" class="btn btn-primary btn-lg" style="width:100%">Send reset link</button>
    </form>
    <p class="small muted" style="margin-top:22px"><a href="{{ route('login') }}">Back to sign in</a></p>
@endsection

@extends('auth.layout')
@section('title', 'Choose a new password')
@section('card')
    <h2>Choose a new password</h2>
    <p class="muted" style="margin-top:0">At least 12 characters with letters and numbers. You will be signed out of the mobile and desktop apps.</p>
    <form method="POST" action="{{ route('password.update') }}" class="stack" style="margin-top:22px">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        @if($errors->any())<div class="alert alert-error" role="alert">{{ $errors->first() }}</div>@endif
        <label class="field"><span>Email</span><input name="email" type="email" value="{{ old('email', $email) }}" required autocomplete="username"></label>
        <label class="field"><span>New password</span><input name="password" type="password" required autocomplete="new-password"></label>
        <label class="field"><span>Confirm new password</span><input name="password_confirmation" type="password" required autocomplete="new-password"></label>
        <button type="submit" class="btn btn-primary btn-lg" style="width:100%">Save new password</button>
    </form>
@endsection

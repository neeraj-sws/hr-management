<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · SWS HR</title>
    @include('partials.favicon')
    <link rel="stylesheet" href="{{ asset('css/sws.css') }}?v={{ filemtime(public_path('css/sws.css')) }}">
</head>
<body>
<div class="auth">
    <section class="auth-hero">
        <div class="profile-chip"><img class="brand-mark brand-logo" src="{{ asset('images/sws-logo.png') }}?v=2" alt="SWS"><span><span class="brand-name">SWS HR</span><span class="brand-sub">HR &amp; Work Management</span></span></div>
        <div>
            <h1>People, attendance, work and payroll — in one place.</h1>
            <ul>
                <li>✓ Attendance with breaks, late and half-day tracking</li>
                <li>✓ Tasks with timers, daily status and productivity</li>
                <li>✓ Leave, approvals and payroll with audit trail</li>
            </ul>
        </div>
        <small style="color:#8ea3c3">© {{ date('Y') }} SWS HR</small>
    </section>
    <main class="auth-panel">
        <div class="auth-card">
            <h2>Welcome back</h2>
            <p class="muted" style="margin-top:0">Sign in with your work email or employee ID.</p>
            <form method="POST" action="{{ route('login.store') }}" class="stack" style="margin-top:22px">
                @csrf
                @if($errors->any())<div class="alert alert-error" role="alert">{{ $errors->first() }}</div>@endif
                <label class="field {{ $errors->has('email') ? 'has-error' : '' }}"><span>Email or Employee ID</span><input id="email" name="email" type="text" placeholder="you@company.com or EMP-001" value="{{ old('email') }}" required autofocus autocomplete="username"></label>
                <label class="field"><span>Password</span><input id="password" name="password" type="password" required autocomplete="current-password"></label>
                <label class="check"><input type="checkbox" name="remember" value="1"> Keep me signed in</label>
                <button type="submit" class="btn btn-primary btn-lg" style="width:100%">Sign in</button>
            </form>
            @if(session('status'))<div class="alert alert-success" role="status" style="margin-top:16px">{{ session('status') }}</div>@endif
            <p class="small muted" style="margin-top:22px"><a href="{{ route('password.request') }}">Forgot your password?</a></p>
        </div>
    </main>
</div>
</body>
</html>

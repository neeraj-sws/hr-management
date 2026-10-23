<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign-in code · SWS HR</title>
    <link rel="stylesheet" href="{{ asset('css/sws.css') }}?v={{ filemtime(public_path('css/sws.css')) }}">
</head>
<body>
<div class="auth">
    <section class="auth-hero">
        <div class="profile-chip"><span class="brand-mark">SW</span><span><span class="brand-name">SWS HR</span><span class="brand-sub">HR &amp; Work Management</span></span></div>
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
            <h2>Enter your sign-in code</h2>
            <p class="muted" style="margin-top:0">We e-mailed a 6-digit code to <strong>{{ $email }}</strong>. It works for {{ $minutes }} minutes.</p>
            <form method="POST" action="{{ route('login.two-factor.verify') }}" class="stack" style="margin-top:22px">
                @csrf
                @if($errors->any())<div class="alert alert-error" role="alert">{{ $errors->first() }}</div>@endif
                @if(session('status'))<div class="alert alert-success" role="status">{{ session('status') }}</div>@endif
                <label class="field {{ $errors->has('code') ? 'has-error' : '' }}"><span>6-digit code</span><input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" placeholder="123456" required autofocus style="font-size:22px;letter-spacing:6px"></label>
                <label class="check"><input type="checkbox" name="trust" value="1"> Trust this browser for {{ $trustDays }} days (don't ask again here)</label>
                <button type="submit" class="btn btn-primary btn-lg" style="width:100%">Verify and sign in</button>
            </form>
            <form method="POST" action="{{ route('login.two-factor.resend') }}" style="margin-top:14px">
                @csrf
                <p class="small muted">No e-mail? Check spam, then <button type="submit" class="linklike" style="color:var(--blue)">send a new code</button> · <a href="{{ route('login') }}">use another account</a></p>
            </form>
        </div>
    </main>
</div>
</body>
</html>

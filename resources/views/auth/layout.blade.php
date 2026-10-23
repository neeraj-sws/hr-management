<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · SWS HR</title>
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
            @yield('card')
        </div>
    </main>
</div>
</body>
</html>

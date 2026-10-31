<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php($user = auth()->user())
    <title>{{ $title ?? 'Client portal' }} · {{ $user->client?->name }}</title>
    @include('partials.favicon')
    <link rel="stylesheet" href="{{ asset('css/sws.css') }}?v={{ filemtime(public_path('css/sws.css')) }}">
    @livewireStyles
    <style>
        .portal-top{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 22px;background:#1f3a5f;color:#fff;flex-wrap:wrap}
        .portal-top a{color:#fff;text-decoration:none;opacity:.85;margin-right:16px;font-weight:600}.portal-top a.on,.portal-top a:hover{opacity:1;text-decoration:underline}
        .portal-top .who{font-size:13px;opacity:.9}.portal-top button{background:transparent;border:1px solid rgba(255,255,255,.5);color:#fff;border-radius:8px;padding:4px 10px;cursor:pointer}
        .portal-main{max-width:1200px;margin:0 auto;padding:22px 16px}
    </style>
</head>
<body>
    <header class="portal-top">
        <div><strong style="margin-right:22px">{{ $user->client?->name }} · Client portal</strong>
            <a href="{{ route('client.dashboard') }}" @class(['on' => request()->routeIs('client.dashboard')])>Projects</a>
            <a href="{{ route('client.timesheet') }}" @class(['on' => request()->routeIs('client.timesheet')])>Timesheet</a>
            <a href="{{ route('client.invoices') }}" @class(['on' => request()->routeIs('client.invoices')])>Invoices</a></div>
        <div class="who">{{ $user->name }} <form method="POST" action="{{ route('logout') }}" style="display:inline">@csrf <button type="submit">Log out</button></form></div>
    </header>
    <main class="portal-main">{{ $slot }}</main>
    <div class="toasts" x-data="{ items: [] }"
         x-on:toast.window="const id = Date.now() + Math.random(); items.push({ id, ...$event.detail }); setTimeout(() => items = items.filter(i => i.id !== id), 4200)">
        <template x-for="item in items" :key="item.id">
            <div class="toast" :class="item.type" role="status"><span x-text="item.message"></span></div>
        </template>
    </div>
    @livewireScripts
</body>
</html>

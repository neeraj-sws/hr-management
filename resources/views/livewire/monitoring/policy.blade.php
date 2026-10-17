<div>
    <div class="page-head"><div><h1>Monitoring policy</h1><p>What the SWS HR desktop app records about your work time, why, and who can see it.</p></div></div>

    @if($own && $policy['enabled'])
        <div class="alert {{ $acknowledged ? 'alert-info' : 'alert-warn' }}">
            <strong>● Screen capture is ON for you.</strong> {{ $terms['frequency'] }}
            @if($acknowledged)<small>You confirmed this policy on {{ $acknowledgedAt?->format('d M Y, h:i A') }}.</small>@endif
        </div>
    @elseif($own)
        <div class="alert alert-success"><strong>Screen capture is OFF for you.</strong> No screenshots of your screen are taken. If your employer turns it on, you are told in the app and by e-mail first.</div>
    @endif

    <section class="card">
        <div class="card-head"><div><h2>Screen capture</h2><p>{{ $own && $policy['enabled'] ? 'The rules that apply to you now.' : 'The rules that apply when screen capture is turned on for someone.' }}</p></div></div>
        <div class="card-body">
            <dl class="kv">
                <dt>How often</dt><dd>{{ $terms['frequency'] }}</dd>
                <dt>When</dt><dd>{{ $terms['when'] }}</dd>
                <dt>Why (purpose)</dt><dd>{{ $terms['purpose'] }}</dd>
                <dt>Who can see them</dt><dd>{{ $terms['viewers'] }}</dd>
                <dt>How long they are kept</dt><dd>{{ $terms['retention'] }}</dd>
                <dt>Never recorded</dt><dd>{{ $terms['never'] }}</dd>
                <dt>How you know it is on</dt><dd>The desktop app shows a red "● Screen capture ON" sign while it is active, and the tray icon says so too. It never captures while the app is closed.</dd>
                @if($own)<dt>Your screenshots</dt><dd>@if($myShots)<a href="{{ route('monitoring.screenshots') }}">See your {{ $myShots }} {{ str('screenshot')->plural($myShots) }}</a>@else None stored. @endif You can always see every screenshot taken of your screen.</dd>@endif
            </dl>
        </div>
        @if($own && $policy['enabled'] && ! $acknowledged)
            <div class="card-foot actions"><button class="btn btn-primary" wire:click="acknowledge" wire:loading.attr="disabled">I have read and understood this policy</button></div>
        @endif
    </section>
</div>

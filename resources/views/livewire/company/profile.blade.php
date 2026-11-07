<div>
    @if(! $company)
        <div class="page-head"><div><h1>Companies</h1><p>Each company has its own rules for salary, working calendar, penalties and leave. Open a company to see them side by side; a company without rules of its own follows the rules for all companies.</p></div></div>
        <section class="card">
            <div class="table-wrap"><table class="table"><thead><tr><th>Company</th><th class="num">Active employees</th><th>Rules</th><th></th></tr></thead><tbody>
                @foreach($companies as $c)
                    <tr wire:key="co-{{ $c->id }}"><td><strong>{{ $c->name }}</strong>@if($c->is_main) <x-badge tone="blue">main</x-badge>@endif</td><td class="num">{{ $c->employees }}</td>
                        <td>@if($c->own_rules)<x-badge tone="green">Own rules</x-badge>@else<x-badge tone="gray">Same as all companies</x-badge>@endif</td>
                        <td class="nowrap"><a class="btn btn-sm" href="{{ route('company.profile', $c) }}">Open profile</a>@if($canEdit) <a class="btn btn-sm btn-primary" href="{{ route('system.settings', ['company' => $c->id, 'tab' => 'payroll']) }}">Edit rules</a>@endif</td></tr>
                @endforeach
            </tbody></table></div>
        </section>
    @else
        <div class="page-head">
            <div><h1>{{ $company->name }}@if($company->is_main) <x-badge tone="blue">main</x-badge>@endif</h1><p>The rules this company works by today. A section marked "Same as all companies" has no rule of its own yet.</p></div>
            @unless($single)<a class="btn" href="{{ route('company.profile') }}">All companies</a>@endunless
        </div>
        <div class="grid grid-2">
            @foreach($sections as $s)
                <section class="card" wire:key="sec-{{ $loop->index }}">
                    <div class="card-head"><div><h2>{{ $s['title'] }}</h2></div>
                        <div class="row-actions">@if($s['own'])<x-badge tone="green">Own rules</x-badge>@else<x-badge tone="gray">Same as all companies</x-badge>@endif
                            @if($canEdit)<a class="btn btn-sm btn-primary" href="{{ route('system.settings', ['company' => $company->id, 'tab' => $s['tab']]) }}">Edit rules</a>@endif</div></div>
                    <div class="card-body"><dl class="kv">@foreach($s['lines'] as [$label, $value])<dt>{{ $label }}</dt><dd>{{ $value }}</dd>@endforeach</dl></div>
                </section>
            @endforeach
        </div>
    @endif
</div>

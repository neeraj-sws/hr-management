@php($reports = $children->get($person->id, collect()))
<li wire:key="org-{{ $person->id }}">
    <div @class(['org-card', 'org-me' => $own && $own->id === $person->id, 'org-match' => $matches->contains($person->id)])>
        <span class="avatar">{{ mb_strtoupper(mb_substr($person->name, 0, 1)) }}</span>
        <div>
            <strong>{{ $person->name }}</strong>@if($own && $own->id === $person->id) <span class="small muted">(you)</span>@endif
            <small>{{ $person->designation ?: 'Employee' }}{{ $person->department ? ' · '.$person->department->name : '' }}</small>
            @if($reports->isNotEmpty())<small>{{ $reports->count() }} direct · {{ $countReports($person->id) }} total</small>@endif
        </div>
    </div>
    @if($reports->isNotEmpty() && $depth < 20)
        <ul>
            @foreach($reports as $child)
                @include('livewire.company.partials.org-node', ['person' => $child, 'depth' => $depth + 1])
            @endforeach
        </ul>
    @endif
</li>

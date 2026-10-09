<div>
    <div class="page-head">
        <div><h1>Org Chart</h1><p>{{ $total }} people · reporting lines come from each employee's manager (People → Employees).</p></div>
        <div class="toolbar">
            <input type="search" class="input" wire:model.live.debounce.300ms="search" placeholder="Find a person…" aria-label="Find a person" style="width:200px">
            <select class="input" wire:model.live="department" style="width:auto" aria-label="Department"><option value="">Whole company</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
        </div>
    </div>
    <section class="card card-body org">
        @if($roots->isEmpty())
            <x-empty title="No people to show" icon="users" />
        @else
            <ul class="org-tree">
                @foreach($roots as $person)
                    @include('livewire.company.partials.org-node', ['person' => $person, 'depth' => 0])
                @endforeach
            </ul>
        @endif
    </section>
</div>

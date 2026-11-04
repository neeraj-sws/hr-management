<div>
    <div class="page-head">
        <div><h1>Support desk</h1><p>Employee support requests{{ $manage ? '' : ' assigned to you or not taken yet' }}. Open a request to reply, change its status or priority{{ $manage ? ', or assign it' : '' }}.</p></div>
        @if($manage)<div class="actions"><a class="btn" href="{{ route('support.reports') }}"><x-icon name="chart" class="icon-sm" />Reports</a><a class="btn" href="{{ route('support.settings') }}"><x-icon name="settings" class="icon-sm" />Categories</a></div>@endif
    </div>

    <div class="tabs mb" role="tablist">
        @foreach($tabs as $key => $label)
            <button class="tab {{ $tab === $key ? 'active' : '' }}" wire:click="showTab('{{ $key }}')" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}">{{ $label }} <span class="muted">({{ $counts[$key] }})</span></button>
        @endforeach
    </div>

    <section class="card">
        <div class="toolbar">
            <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.400ms="search" placeholder="Request ID, subject, employee name or code"></label>
            <label class="field"><span>Category</span><select wire:model.live="category"><option value="">All</option>@foreach($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></label>
            <label class="field"><span>Priority</span><select wire:model.live="priority"><option value="">All</option>@foreach($priorities as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
            @if($tab === 'all')<label class="field"><span>Status</span><select wire:model.live="status"><option value="">All</option>@foreach($statuses as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>@endif
            <label class="field"><span>Assigned to</span><select wire:model.live="assignee"><option value="">Anyone</option><option value="none">Nobody</option>@foreach($handlers as $h)<option value="{{ $h->id }}">{{ $h->name }}</option>@endforeach</select></label>
            <label class="field"><span>Raised from</span><input type="date" wire:model.live="from"></label>
            <label class="field"><span>to</span><input type="date" wire:model.live="to"></label>
            <button type="button" class="btn" wire:click="resetFilters"><x-icon name="refresh" class="icon-sm" />Reset</button>
        </div>

        @php($th = fn ($col, $label) => '<th class="sortable '.($sort === $col ? 'sorted-'.$dir : '').'" wire:click="sortBy(\''.$col.'\')">'.$label.'</th>')
        <div class="table-wrap"><table class="table sup-table" data-no-sort>
            <thead><tr>
                {!! $th('id', 'Request ID') !!}<th>Employee</th>{!! $th('subject', 'Subject') !!}<th>Category</th>{!! $th('priority', 'Priority') !!}{!! $th('status', 'Status') !!}<th>Assigned to</th>{!! $th('created_at', 'Raised') !!}{!! $th('updated_at', 'Last update') !!}
            </tr></thead>
            <tbody>
            @forelse($tickets as $t)
                <tr wire:key="desk-{{ $t->id }}" class="{{ in_array($t->id, $unread) ? 'sup-row-unread' : '' }}">
                    <td class="nowrap"><a href="{{ route('support.show', $t->id) }}"><strong>@if(in_array($t->id, $unread))<span class="sup-dot" title="Unread message"></span>@endif{{ $t->number() }}</strong></a></td>
                    <td><strong>{{ $t->employee?->name }}</strong><small>{{ $t->employee?->employee_code }}</small></td>
                    <td class="sup-subject"><a href="{{ route('support.show', $t->id) }}">{{ $t->subject }}</a></td>
                    <td>{{ $t->category?->name }}@if($t->category?->is_restricted)<small>restricted</small>@endif</td>
                    <td><x-badge :tone="$priorityTones[$t->priority] ?? 'gray'">{{ $priorities[$t->priority] ?? $t->priority }}</x-badge></td>
                    <td><x-badge :tone="$statusTones[$t->status] ?? 'gray'">{{ $statuses[$t->status] ?? $t->status }}</x-badge></td>
                    <td>{{ $t->assignee?->name ?? '—' }}</td>
                    <td class="nowrap"><small>{{ $t->created_at->format('d M Y, h:i A') }}</small></td>
                    <td class="nowrap"><small>{{ $t->updated_at?->format('d M Y, h:i A') }}</small></td>
                </tr>
            @empty
                <tr><td colspan="9"><x-empty title="No requests here" icon="inbox" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
        <div class="card-foot sup-foot">
            <label class="field sup-perpage"><span>Rows</span><select wire:model.live="perPage">@foreach($perPageOptions as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach</select></label>
            {{ $tickets->links() }}
        </div>
    </section>
</div>

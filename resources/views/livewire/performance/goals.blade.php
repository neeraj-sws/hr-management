<div>
    <div class="page-head">
        <div><h1>Goals</h1><p>KRA (key result areas, ongoing) and OKR (objective + measurable key result). Each goal has a weight; an employee's goals for a period should add up to 100%. Progress is updated during the period and the manager scores each goal in the review.</p></div>
        <div class="actions">
            @if($tab === 'mine' && $own)<button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Propose a goal</button>@endif
            @if($tab === 'team' && $canManage)<button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Set a goal</button>@endif
        </div>
    </div>
    <div class="tabs">
        @if($own)<button class="tab {{ $tab === 'mine' ? 'active' : '' }}" wire:click="$set('tab','mine')">My goals</button>@endif
        @if($canManage)<button class="tab {{ $tab === 'team' ? 'active' : '' }}" wire:click="$set('tab','team')">Team goals @if($pendingApproval)<span class="badge badge-amber">{{ $pendingApproval }} to approve</span>@endif</button>@endif
    </div>
    <div class="toolbar">
        <x-field label="Period" name="period"><select wire:model.live="period">@foreach($this->periods() as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></x-field>
        @if($tab === 'team')<x-field label="Employee" name="employeeFilter"><select wire:model.live="employeeFilter"><option value="">Everyone in my team</option>@foreach($people as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></x-field>@endif
    </div>

    <div class="stack">
        @forelse($groups as $employeeId => $goals)
            @php($person = $goals->first()->employee)
            @php($counted = $goals->where('status', '!=', 'proposed'))
            @php($weight = (int) $counted->sum('weight'))
            @php($overall = $weight ? round($counted->sum(fn ($g) => $g->weight * $g->progress) / $weight) : ($counted->count() ? round($counted->avg('progress')) : 0))
            <section class="card" wire:key="emp-{{ $employeeId }}">
                <div class="card-head">
                    <div><h2>{{ $person?->name }}</h2><p>{{ $goals->count() }} {{ str('goal')->plural($goals->count()) }} · overall progress {{ $overall }}% @if($weight && $weight !== 100)· <span style="color:var(--amber)">weights add up to {{ $weight }}%, not 100%</span>@endif</p></div>
                    @if($tab === 'team')<button class="btn btn-sm" wire:click="create({{ $employeeId }})"><x-icon name="plus" class="icon-sm" />Add goal</button>@endif
                </div>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>Goal</th><th>Type</th><th class="num">Weight</th><th style="min-width:160px">Progress</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    @foreach($goals as $g)
                        <tr wire:key="goal-{{ $g->id }}">
                            <td><strong>{{ $g->title }}</strong>@if($g->target)<small>Target: {{ $g->target }}</small>@endif @if($g->description)<small class="muted" style="white-space:pre-line">{{ $g->description }}</small>@endif<small class="muted">{{ $g->starts_on->format('d M Y') }} – {{ $g->ends_on->format('d M Y') }}@if($last = $g->updates->first()) · last update {{ $last->created_at->format('d M') }}@if($last->note): “{{ str($last->note)->limit(80) }}”@endif @endif</small></td>
                            <td><x-badge tone="{{ $g->kind === 'okr' ? 'violet' : 'blue' }}">{{ \App\Models\PerformanceGoal::KINDS[$g->kind] ?? $g->kind }}</x-badge></td>
                            <td class="num">{{ $g->weight }}%</td>
                            <td><div class="progress" title="{{ $g->progress }}%"><span style="width:{{ $g->progress }}%"></span></div><small>{{ $g->progress }}%</small></td>
                            <td><x-badge :status="$g->status" /></td>
                            <td class="nowrap">
                                @if($g->status === 'active')<button class="btn btn-sm" wire:click="openProgress({{ $g->id }})">Update progress</button>@endif
                                @if($tab === 'team')
                                    @if($g->status === 'proposed')<button class="btn btn-sm btn-primary" wire:click="approve({{ $g->id }})">Approve</button>@endif
                                    <button class="btn btn-sm btn-ghost" wire:click="edit({{ $g->id }})" title="Edit"><x-icon name="edit" class="icon-sm" /></button>
                                    @if($g->status === 'active')<button class="btn btn-sm btn-ghost" wire:click="setStatus({{ $g->id }}, 'completed')" title="Mark completed"><x-icon name="check" class="icon-sm" /></button>@endif
                                    @if(in_array($g->status, ['completed', 'cancelled'], true))<button class="btn btn-sm btn-ghost" wire:click="setStatus({{ $g->id }}, 'active')">Reopen</button>@endif
                                    @if($g->status !== 'cancelled')<button class="btn btn-sm btn-ghost" wire:click="setStatus({{ $g->id }}, 'cancelled')" wire:confirm="Cancel this goal? It will not count in the review." title="Cancel"><x-icon name="x" class="icon-sm" /></button>@endif
                                @elseif($g->status === 'proposed')
                                    <button class="btn btn-sm btn-ghost" wire:click="edit({{ $g->id }})">Edit</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            </section>
        @empty
            <div class="card"><x-empty title="No goals for this period yet" icon="check" /></div>
        @endforelse
    </div>

    @if($showForm)
        <x-modal :title="$editingId ? 'Edit goal' : ($tab === 'mine' ? 'Propose a goal' : 'Set a goal')" size="lg">
            <form wire:submit="save"><div class="modal-body"><x-errors /><div class="form-grid">
                @if($tab === 'team')<x-field label="Employee" name="goal.employee_id" full><select wire:model="goal.employee_id" @disabled($editingId)><option value="">Select</option>@foreach($people as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></x-field>@endif
                <x-field label="Type" name="goal.kind"><select wire:model="goal.kind"><option value="kra">KRA — key result area (ongoing responsibility)</option><option value="okr">OKR — objective with a measurable key result</option></select></x-field>
                <x-field label="Weight (%)" name="goal.weight" hint="share of the review; all goals together = 100"><input type="number" min="0" max="100" wire:model="goal.weight"></x-field>
                <x-field label="Goal" name="goal.title" full><input type="text" maxlength="200" wire:model="goal.title" placeholder="e.g. Grow qualified leads from social media"></x-field>
                <x-field label="Target / how it is measured" name="goal.target" full><input type="text" maxlength="200" wire:model="goal.target" placeholder="e.g. 20 qualified leads a month"></x-field>
                <x-field label="Details" name="goal.description" full><textarea wire:model="goal.description"></textarea></x-field>
                <x-field label="From" name="goal.starts_on"><input type="date" wire:model="goal.starts_on"></x-field>
                <x-field label="To" name="goal.ends_on"><input type="date" wire:model="goal.ends_on"></x-field>
            </div>@if($tab === 'mine' && ! $editingId)<p class="small muted">Your manager approves the goal before it counts.</p>@endif</div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">{{ $editingId ? 'Save' : ($tab === 'mine' ? 'Send for approval' : 'Set goal') }}</button></div></form>
        </x-modal>
    @endif

    @if($progressGoal)
        <x-modal title="Update progress">
            <form wire:submit="saveProgress"><div class="modal-body"><x-errors />
                <p style="margin-top:0"><strong>{{ $progressGoal->title }}</strong>@if($progressGoal->target)<br><span class="small muted">Target: {{ $progressGoal->target }}</span>@endif</p>
                <x-field label="Progress: {{ $progress }}%" name="progress"><input type="range" min="0" max="100" step="5" wire:model.live="progress"></x-field>
                <x-field label="What was done (optional)" name="progressNote"><textarea wire:model="progressNote" placeholder="e.g. 14 leads this month from Instagram campaign"></textarea></x-field>
                @if($progressGoal->updates->isNotEmpty())<p class="eyebrow">Earlier updates</p><div class="list">@foreach($progressGoal->updates->take(6) as $u)<div class="list-row"><div><strong>{{ $u->progress }}%</strong> <small>{{ $u->created_at->format('d M Y') }} · {{ $u->user?->name }}@if($u->note) · {{ $u->note }}@endif</small></div></div>@endforeach</div>@endif
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Save progress</button></div></form>
        </x-modal>
    @endif
</div>

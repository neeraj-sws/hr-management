<div>
    <div class="page-head">
        <div><h1>Training Courses</h1><p>{{ $canManage ? 'Build the course catalogue and assign courses to people or whole departments.' : 'Assign courses to your team and track their progress.' }}</p></div>
        @if($canManage)<button class="btn btn-primary" wire:click="edit"><x-icon name="plus" class="icon-sm" />New course</button>@endif
    </div>
    <section class="card">
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Course</th><th>Category</th><th class="num">Hours</th><th class="num">Fee</th><th>Completion</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($courses as $c)
                @php($pct = $c->assignments_count ? (int) round($c->completed_count * 100 / $c->assignments_count) : 0)
                <tr wire:key="course-{{ $c->id }}">
                    <td><strong>{{ $c->title }}</strong>@if($c->is_mandatory) <x-badge tone="red">Mandatory</x-badge>@endif<small>{{ $c->provider ?: '—' }}</small></td>
                    <td>{{ $c->category ?: '—' }}</td><td class="num">{{ $c->duration_hours !== null ? \App\Support\Duration::fromHours($c->duration_hours) : ($c->duration_label ?: '—') }}</td>
                    <td class="num">@if($c->fee !== null){{ number_format((float) $c->fee, 2) }}@if((float) $c->one_time_discount > 0)<small>−{{ number_format((float) $c->one_time_discount, 2) }} if paid at once</small>@endif @else <span class="muted">Free</span>@endif</td>
                    <td style="min-width:160px">@if($c->assignments_count)<div class="progress" title="{{ $pct }}%"><span style="width:{{ $pct }}%"></span></div><small>{{ $c->completed_count }} of {{ $c->assignments_count }} completed</small>@else<span class="muted small">Not assigned yet</span>@endif</td>
                    <td><x-badge :status="$c->is_active ? 'active' : 'inactive'" /></td>
                    <td><div class="row-actions">
                        @if($c->is_active)<button class="btn btn-sm btn-primary" wire:click="openAssign({{ $c->id }})">Assign</button>@endif
                        @if($c->assignments_count)<button class="btn btn-sm" wire:click="$set('progressId', {{ $c->id }})">Progress</button>@endif
                        @if($canManage)<button class="btn btn-sm" wire:click="edit({{ $c->id }})">Edit</button>@endif
                    </div></td>
                </tr>
            @empty
                <tr><td colspan="6"><x-empty title="No courses yet" icon="star">@if($canManage)Add courses such as "Information security basics" or "POSH awareness".@endif</x-empty></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $courses->links() }}
    </section>

    @if($showForm)
        <x-modal :title="$editingId ? 'Edit course' : 'New course'" size="lg">
            <form wire:submit="save"><div class="modal-body"><div class="form-grid">
                <x-field label="Title" name="title" full><input wire:model="title"></x-field>
                <x-field label="Category" name="category"><input wire:model="category" placeholder="Compliance, Technical, Soft skills…"></x-field>
                <x-field label="Provider / trainer" name="provider"><input wire:model="provider"></x-field>
                <x-field label="Duration (hours)" name="durationHours"><input type="number" step="0.5" min="0" wire:model="durationHours"></x-field>
                <x-field label="Duration (as told to trainees)" name="durationLabel" hint="optional, e.g. 6 months"><input wire:model="durationLabel" placeholder="e.g. 6 months"></x-field>
                <x-field label="Fee" name="fee" hint="for trainees; leave empty for free staff training"><input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="fee"></x-field>
                @if($fee !== '')<x-field label="Discount if paid at once" name="oneTimeDiscount" hint="taken off when the whole fee is paid in one go; none for installments"><input type="number" step="0.01" min="0" wire:model="oneTimeDiscount" placeholder="e.g. 3000"></x-field>@endif
                <x-field label="Material link" name="materialUrl" hint="video, document or course page"><input type="url" wire:model="materialUrl" placeholder="https://"></x-field>
                <x-field label="Description" name="description" full><textarea wire:model="description"></textarea></x-field>
                <label class="check"><input type="checkbox" wire:model="isMandatory"> Mandatory</label>
                <label class="check"><input type="checkbox" wire:model="isActive"> Active</label>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Save</button></div></form>
        </x-modal>
    @endif

    @if($assigning)
        <x-modal title="Assign · {{ $assigning->title }}" size="lg">
            <form wire:submit="assign"><div class="modal-body"><div class="form-grid">
                @if($canManage)<x-field label="Whole department" name="departmentId" hint="selects everyone in it"><select wire:model.live="departmentId"><option value="">—</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></x-field>@endif
                <x-field label="Complete by" name="dueOn"><input type="date" wire:model="dueOn" min="{{ today()->toDateString() }}"></x-field>
                <div class="field full"><span class="label">Employees</span>
                    <div class="checks" style="max-height:260px;overflow:auto">@forelse($employees as $e)<label class="check"><input type="checkbox" value="{{ $e->id }}" wire:model="employeeIds"> {{ $e->name }} <span class="muted small">{{ $e->employee_code }}</span></label>@empty<span class="muted small">No one in your team.</span>@endforelse</div>
                    @error('employeeIds')<span class="error">{{ $message }}</span>@enderror
                </div>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Assign</button></div></form>
        </x-modal>
    @endif

    @if($progress !== null && $progressCourse)
        <x-modal title="Progress · {{ $progressCourse->title }}">
            <div class="modal-body"><div class="list">
                @foreach($progress->sortBy(fn ($a) => $a->status === 'completed') as $a)
                    <div class="list-row"><div><strong>{{ $a->employee?->name }}</strong><small>@if($a->completed_at)Completed {{ $a->completed_at->format('d M Y') }}@elseif($a->due_on)Due {{ $a->due_on->format('d M Y') }}@else No due date @endif</small></div><x-badge :status="$a->displayStatus()" /></div>
                @endforeach
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Close</button></div>
        </x-modal>
    @endif
</div>

<div>
    <div class="page-head">
        <div><h1>Job Openings</h1><p>Positions you are hiring for. Add candidates to an opening from Candidates.</p></div>
        <div class="actions">
            <select wire:model.live="status" class="input" style="width:auto" aria-label="Status"><option value="">All</option>@foreach(\App\Models\JobOpening::STATUSES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
            <button class="btn btn-primary" wire:click="edit"><x-icon name="plus" class="icon-sm" />New opening</button>
        </div>
    </div>
    <section class="card">
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Position</th><th>Department</th><th>Type</th><th class="num">Openings</th><th class="num">In pipeline</th><th class="num">Hired</th><th>Closes</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($jobs as $job)
                <tr wire:key="job-{{ $job->id }}">
                    <td><strong>{{ $job->title }}</strong><small>{{ $job->location ?: '—' }}@if($job->hiringManager) · {{ $job->hiringManager->name }}@endif</small></td>
                    <td>{{ $job->department?->name ?? '—' }}</td><td>{{ \App\Models\JobOpening::TYPES[$job->employment_type] ?? $job->employment_type }}</td>
                    <td class="num">{{ $job->openings }}</td><td class="num">{{ $job->active_count }}</td><td class="num">{{ $job->hired_count }}</td>
                    <td class="nowrap">{{ $job->closes_on?->format('d M Y') ?? '—' }}</td><td><x-badge :status="$job->status" /></td>
                    <td><div class="row-actions"><a class="btn btn-sm" href="{{ route('recruitment.candidates', ['job' => $job->id]) }}">Candidates</a><button class="btn btn-sm" wire:click="edit({{ $job->id }})">Edit</button></div></td>
                </tr>
            @empty
                <tr><td colspan="9"><x-empty title="No job openings" icon="briefcase">Create an opening to start adding candidates.</x-empty></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $jobs->links() }}
    </section>

    @if($showForm)
        <x-modal :title="$editingId ? 'Edit job opening' : 'New job opening'" size="lg">
            <form wire:submit="save"><div class="modal-body"><div class="form-grid">
                <x-field label="Position title" name="title" full><input wire:model="title" placeholder="Senior Laravel Developer"></x-field>
                <x-field label="Department" name="departmentId"><select wire:model="departmentId"><option value="">—</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></x-field>
                <x-field label="Location" name="location"><input wire:model="location" placeholder="Office / Remote"></x-field>
                <x-field label="Employment type" name="employmentType"><select wire:model="employmentType">@foreach(\App\Models\JobOpening::TYPES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></x-field>
                <x-field label="Number of openings" name="openings"><input type="number" min="1" wire:model="openings"></x-field>
                <x-field label="Hiring manager" name="hiringManagerId"><select wire:model="hiringManagerId"><option value="">—</option>@foreach($managers as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select></x-field>
                <x-field label="Applications close" name="closesOn"><input type="date" wire:model="closesOn"></x-field>
                <x-field label="Status" name="jobStatus"><select wire:model="jobStatus">@foreach(\App\Models\JobOpening::STATUSES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></x-field>
                <x-field label="Description" name="description" full><textarea wire:model="description" rows="6" placeholder="Responsibilities, requirements, experience…"></textarea></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Save</button></div></form>
        </x-modal>
    @endif
</div>

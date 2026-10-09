<div>
    <div class="page-head">
        <div><h1>Candidates</h1><p>Move people through the hiring stages. Hiring creates their employee login and an onboarding case.</p></div>
        <div class="actions">
            <input type="search" class="input" wire:model.live.debounce.300ms="search" placeholder="Search name or email" aria-label="Search candidates" style="width:200px">
            <select wire:model.live="job" class="input" style="width:auto" aria-label="Job opening"><option value="">All openings</option>@foreach($jobs as $j)<option value="{{ $j->id }}">{{ $j->title }}{{ $j->status !== 'open' ? ' ('.str_replace('_', ' ', $j->status).')' : '' }}</option>@endforeach</select>
            <button class="btn btn-primary" wire:click="openAdd" @disabled($jobs->isEmpty())><x-icon name="plus" class="icon-sm" />Add candidate</button>
        </div>
    </div>
    @if($jobs->isEmpty())<div class="alert alert-info">Create a job opening first under <a href="{{ route('recruitment.jobs') }}">Job Openings</a>.</div>@endif

    <div class="pipeline">
        @foreach(\App\Models\Candidate::STAGES as $stage => $label)
            <section class="card pipeline-col">
                <div class="card-head"><div><h2>{{ $label }}</h2></div><x-badge :status="$stage">{{ $board[$stage]->count() }}</x-badge></div>
                <div class="list">
                    @forelse($board[$stage] as $c)
                        <button type="button" class="list-row pipeline-card" wire:click="view({{ $c->id }})" wire:key="cand-{{ $c->id }}">
                            <div><strong>{{ $c->name }}</strong><small>{{ $c->jobOpening?->title }}</small>
                                <small>@if($c->averageRating())★ {{ $c->averageRating() }} · @endif{{ $c->interviews->where('status', 'scheduled')->count() ? $c->interviews->where('status', 'scheduled')->count().' upcoming interview(s)' : (\App\Models\Candidate::SOURCES[$c->source] ?? $c->source) }}</small></div>
                        </button>
                    @empty
                        <p class="small muted" style="padding:8px 4px;margin:0">No one here</p>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>

    @if($showAdd)
        <x-modal title="Add candidate">
            <form wire:submit="add"><div class="modal-body"><div class="form-grid">
                <x-field label="Job opening" name="jobId" full><select wire:model="jobId"><option value="">Choose…</option>@foreach($jobs as $j)<option value="{{ $j->id }}">{{ $j->title }}</option>@endforeach</select></x-field>
                <x-field label="Full name" name="name"><input wire:model="name"></x-field>
                <x-field label="Email" name="email"><input type="email" wire:model="email"></x-field>
                <x-field label="Phone" name="phone"><input wire:model="phone"></x-field>
                <x-field label="Source" name="source"><select wire:model="source">@foreach(\App\Models\Candidate::SOURCES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></x-field>
                <x-field label="CV" name="resume" hint="PDF or Word, up to 5 MB" full><input type="file" wire:model="resume" accept=".pdf,.doc,.docx"></x-field>
                <x-field label="Notes" name="notes" full><textarea wire:model="notes"></textarea></x-field>
            </div><div wire:loading wire:target="resume" class="small muted">Uploading CV…</div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled" wire:target="add,resume">Add</button></div></form>
        </x-modal>
    @endif

    @if($viewing && ! $showHire)
        <x-modal :title="$viewing->name" size="lg">
            <div class="modal-body">
                <dl class="kv">
                    <dt>Applied for</dt><dd>{{ $viewing->jobOpening?->title }}</dd>
                    <dt>Email</dt><dd>{{ $viewing->email }}</dd>
                    <dt>Phone</dt><dd>{{ $viewing->phone ?: '—' }}</dd>
                    <dt>Source</dt><dd>{{ \App\Models\Candidate::SOURCES[$viewing->source] ?? $viewing->source }}</dd>
                    <dt>CV</dt><dd>@if($viewing->resume_path)<a href="{{ route('recruitment.resume', $viewing) }}">{{ $viewing->resume_name ?? 'Download CV' }}</a>@else<span class="muted">Not uploaded</span>@endif</dd>
                    @if($viewing->notes)<dt>Notes</dt><dd style="white-space:pre-line">{{ $viewing->notes }}</dd>@endif
                    @if($viewing->hiredEmployee)<dt>Employee</dt><dd><a href="{{ route('people.employees.show', $viewing->hiredEmployee) }}">{{ $viewing->hiredEmployee->employee_code }}</a></dd>@endif
                </dl>
                @if($viewing->stage !== 'hired')
                    <div class="form-grid mt">
                        <x-field label="Stage" name="stage"><select wire:model.live="stage">@foreach(\App\Models\Candidate::STAGES as $key => $label)@if($key !== 'hired')<option value="{{ $key }}">{{ $label }}</option>@endif @endforeach</select></x-field>
                        <div class="field" style="align-self:end">@if(in_array($viewing->stage, ['offer', 'interview', 'screening'], true))<button type="button" class="btn btn-success" wire:click="openHire"><x-icon name="user-plus" class="icon-sm" />Hire</button>@endif</div>
                    </div>
                @endif

                <h3 class="mt" style="font-size:14px">Interviews</h3>
                <div class="list">
                    @forelse($viewing->interviews->sortByDesc('scheduled_at') as $iv)
                        <div class="list-row"><div><strong>{{ $iv->scheduled_at->format('D d M, h:i A') }} · {{ \App\Models\Interview::MODES[$iv->mode] ?? $iv->mode }}</strong>
                            <small>{{ $iv->interviewer?->name }}{{ $iv->location ? ' · '.$iv->location : '' }}</small>
                            @if($iv->status === 'completed')<small>@if($iv->rating)★ {{ $iv->rating }}/5 · @endif{{ \App\Models\Interview::RECOMMENDATIONS[$iv->recommendation] ?? 'No recommendation' }}{{ $iv->feedback ? ' — '.$iv->feedback : '' }}</small>@endif</div>
                            <div class="row-actions"><x-badge :status="$iv->status" />@if($iv->status === 'scheduled')<button class="btn btn-sm" wire:click="cancelInterview({{ $iv->id }})" wire:confirm="Cancel this interview?">Cancel</button>@endif</div></div>
                    @empty
                        <p class="small muted">No interviews yet.</p>
                    @endforelse
                </div>

                @if(! in_array($viewing->stage, ['hired', 'rejected'], true))
                    <form wire:submit="schedule" class="mt"><h3 style="font-size:14px">Schedule an interview</h3><div class="form-grid">
                        <x-field label="Date & time" name="scheduledAt"><input type="datetime-local" wire:model="scheduledAt" min="{{ now()->format('Y-m-d\TH:i') }}"></x-field>
                        <x-field label="Interviewer" name="interviewerId"><select wire:model="interviewerId"><option value="">Choose…</option>@foreach($interviewers as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select></x-field>
                        <x-field label="Mode" name="mode"><select wire:model="mode">@foreach(\App\Models\Interview::MODES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></x-field>
                        <x-field label="Duration (minutes)" name="duration"><input type="number" min="10" max="480" step="5" wire:model="duration"></x-field>
                        <x-field label="Place or meeting link" name="interviewLocation" full><input wire:model="interviewLocation" placeholder="Room 2 / meeting link"></x-field>
                    </div><div class="form-actions"><button class="btn btn-primary" wire:loading.attr="disabled">Schedule</button></div></form>
                @endif
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Close</button></div>
        </x-modal>
    @endif

    @if($showHire && $viewing)
        <x-modal title="Hire {{ $viewing->name }}">
            <form wire:submit="hire"><div class="modal-body">
                <p class="small muted" style="margin-top:0">Creates the employee record and a login for {{ $viewing->email }}, then opens an onboarding case.</p>
                <div class="form-grid">
                    <x-field label="Employee code" name="employeeCode"><input wire:model="employeeCode" placeholder="SWS-120"></x-field>
                    <x-field label="Joining date" name="joinedAt"><input type="date" wire:model="joinedAt"></x-field>
                    <x-field label="Designation" name="designation"><input wire:model="designation"></x-field>
                    <x-field label="Department" name="departmentId"><select wire:model="departmentId"><option value="">—</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></x-field>
                    <x-field label="Manager" name="managerId"><select wire:model="managerId"><option value="">—</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select></x-field>
                    <x-field label="Monthly salary" name="monthlySalary"><input type="number" step="0.01" min="0" wire:model="monthlySalary"></x-field>
                    <x-field label="Temporary password" name="password" hint="at least 12 characters with letters and numbers" full><input type="password" wire:model="password" autocomplete="new-password"></x-field>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Back</button><button class="btn btn-success" wire:loading.attr="disabled">Hire and create login</button></div></form>
        </x-modal>
    @endif
</div>

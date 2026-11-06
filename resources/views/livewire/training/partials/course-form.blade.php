{{-- The one course form (Training > Courses and Course fees). Needs $mentorOptions (employees who can teach). --}}
@php($mentorNames = $mentorOptions->pluck('name', 'id'))
<x-modal :title="$editingCourseId ? 'Edit course' : 'New course'" size="lg">
    <form wire:submit="saveCourse"><div class="modal-body"><div class="form-grid">
        <x-field label="Course name" name="title" full><input wire:model="title" placeholder="e.g. Web Development"></x-field>
        <x-field label="Category" name="category"><input wire:model="category" placeholder="Technical, Compliance, Soft skills…"></x-field>
        <x-field label="Duration" name="durationValue" hint="the end date of an enrolment follows it">
            <div class="tc-duration"><input type="number" min="1" max="999" wire:model="durationValue" placeholder="e.g. 6"><select wire:model="durationUnit">@foreach(\App\Models\TrainingCourse::DURATION_UNITS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
        </x-field>

        <div class="tc-box full">
            <div class="tc-box-head"><strong>Fee</strong><span class="muted small">for trainees; leave empty for free staff training</span></div>
            <div class="tc-fees">
                <x-field label="Fee in installments" name="fee" hint="full fee"><input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="fee" placeholder="e.g. 20000"></x-field>
                <x-field label="Fee if paid at once" name="atOnceFee" hint="lower; empty = same"><input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="atOnceFee" placeholder="e.g. 17000" @disabled($fee === '')></x-field>
                <x-field label="Usual installments" name="defaultInstallments" hint="can be changed per trainee"><input type="number" min="1" max="36" wire:model="defaultInstallments" placeholder="e.g. 3" @disabled($fee === '')></x-field>
            </div>
            @if($fee !== '' && $atOnceFee !== '' && (float) $atOnceFee < (float) $fee)<p class="small muted" style="margin:6px 0 0">Paying at once saves {{ number_format((float) $fee - (float) $atOnceFee, 2) }}.</p>@endif
            @error('oneTimeDiscount')<span class="error">{{ $message }}</span>@enderror
        </div>

        <div class="tc-box full">
            <div class="tc-box-head"><strong>Topics and mentors</strong><span class="muted small">in teaching order; a topic can have more than one mentor (one is picked per trainee)</span></div>
            @forelse($topics as $i => $topic)
                <div class="tc-topic" wire:key="tc-topic-{{ $i }}-{{ $topic['id'] ?? 'new' }}">
                    <span class="tc-topic-no">{{ $i + 1 }}</span>
                    <div class="tc-topic-main">
                        <input wire:model="topics.{{ $i }}.title" placeholder="Topic, e.g. HTML & CSS">
                        @error('topics.'.$i.'.title')<span class="error">{{ $message }}</span>@enderror
                        <div class="tc-mentors">
                            @foreach($topic['mentor_ids'] ?? [] as $mid)
                                <span class="tc-mentor">{{ $mentorNames[$mid] ?? 'Removed' }}<button type="button" wire:click="removeTopicMentor({{ $i }}, {{ (int) $mid }})" title="Remove">×</button></span>
                            @endforeach
                            <select wire:change="addTopicMentor({{ $i }}, $event.target.value)" class="tc-add-mentor"><option value="">+ Add mentor</option>@foreach($mentorOptions as $m)@unless(in_array((string) $m->id, $topic['mentor_ids'] ?? [], true))<option value="{{ $m->id }}">{{ $m->name }}</option>@endunless @endforeach</select>
                        </div>
                    </div>
                    <div class="tc-topic-actions">
                        <button type="button" class="btn btn-sm" wire:click="moveTopic({{ $i }}, -1)" @disabled($i === 0) title="Move up">↑</button>
                        <button type="button" class="btn btn-sm" wire:click="moveTopic({{ $i }}, 1)" @disabled($i === count($topics) - 1) title="Move down">↓</button>
                        <button type="button" class="btn btn-sm btn-danger" wire:click="removeTopic({{ $i }})" title="Remove topic">Remove</button>
                    </div>
                </div>
            @empty
                <p class="small muted" style="margin:4px 0 8px">No topics yet. Add them in order, e.g. HTML &amp; CSS (Risha), JavaScript (Fahad, Khushboo), React (Khushboo).</p>
            @endforelse
            <button type="button" class="btn btn-sm" wire:click="addTopic"><x-icon name="plus" class="icon-sm" />Add topic</button>
        </div>

        <x-field label="Provider / trainer" name="provider" hint="optional"><input wire:model="provider"></x-field>
        <x-field label="Hours" name="durationHours" hint="optional, for short staff training"><input type="number" step="0.5" min="0" wire:model="durationHours"></x-field>
        <x-field label="Material link" name="materialUrl" hint="video, document or course page" full><input type="url" wire:model="materialUrl" placeholder="https://"></x-field>
        <x-field label="Description" name="description" full><textarea wire:model="description" rows="2"></textarea></x-field>
        <label class="check"><input type="checkbox" wire:model="isMandatory"> Mandatory for staff</label>
        <label class="check"><input type="checkbox" wire:model="isActive"> Active (offered now)</label>
    </div></div>
    <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Save</button></div></form>
</x-modal>

{{-- "3 members" as a button; the names open in a dialog instead of filling the list. --}}
@props(['people', 'title', 'label' => 'member', 'empty' => null])
@php($count = $people->count())
@if($count)
    <span x-data="{ open: false }">
        <button type="button" class="linklike" x-on:click="open = true" aria-haspopup="dialog" title="Show {{ str($label)->plural() }}"><strong>{{ $count }} {{ str($label)->plural($count) }}</strong></button>
        <template x-teleport="body">
            <div class="modal-backdrop" x-show="open" x-cloak x-on:keydown.escape.window="open = false" role="dialog" aria-modal="true" aria-label="{{ $title }}">
                <div class="modal" x-on:click.outside="open = false">
                    <div class="modal-head">
                        <h2>{{ $title }}</h2>
                        <button type="button" class="btn btn-ghost btn-sm" x-on:click="open = false" aria-label="Close"><x-icon name="x" /></button>
                    </div>
                    <div class="modal-body">
                        <ul class="people-list">
                            @foreach($people as $person)
                                <li><strong>{{ $person->name }}</strong>@if($sub = $person->designation ?: $person->employee_code)<small>{{ $sub }}</small>@endif</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        </template>
    </span>
@else
    <span class="muted">{{ $empty ?? '0 '.str($label)->plural() }}</span>
@endif

@props(['model', 'placeholders' => [], 'error' => null])
{{-- A small rich-text editor for HR letters: bold, italic, underline, headings, lists, alignment and {placeholders}.
     Bound to a Livewire property (sent with the next save); the server keeps only this simple formatting. --}}
<div class="letter-editor" wire:ignore
     x-on:letter-editor-set.window="if ($event.detail.model === @js($model)) { $refs.ed.innerHTML = $event.detail.html || ''; sync(); }"
     x-data="{
        html: @entangle($model),
        init() {
            this.$refs.ed.innerHTML = this.html || '';
            this.$watch('html', v => { if (v !== this.$refs.ed.innerHTML) this.$refs.ed.innerHTML = v || ''; });
        },
        sync() { this.html = this.$refs.ed.innerHTML; },
        cmd(name, value = null) { this.$refs.ed.focus(); document.execCommand(name, false, value); this.sync(); },
        insert(key) { if (! key) return; this.$refs.ed.focus(); document.execCommand('insertText', false, '{' + key + '}'); this.sync(); },
     }">
    <div class="le-toolbar" role="toolbar" aria-label="Formatting">
        <button type="button" class="le-btn" title="Bold" @mousedown.prevent @click="cmd('bold')"><b>B</b></button>
        <button type="button" class="le-btn" title="Italic" @mousedown.prevent @click="cmd('italic')"><i>I</i></button>
        <button type="button" class="le-btn" title="Underline" @mousedown.prevent @click="cmd('underline')"><u>U</u></button>
        <span class="le-sep"></span>
        <button type="button" class="le-btn" title="Heading" @mousedown.prevent @click="cmd('formatBlock', 'h3')">H</button>
        <button type="button" class="le-btn" title="Normal text" @mousedown.prevent @click="cmd('formatBlock', 'p')">¶</button>
        <button type="button" class="le-btn" title="Bulleted list" @mousedown.prevent @click="cmd('insertUnorderedList')">• List</button>
        <button type="button" class="le-btn" title="Numbered list" @mousedown.prevent @click="cmd('insertOrderedList')">1. List</button>
        <span class="le-sep"></span>
        <button type="button" class="le-btn" title="Align left" @mousedown.prevent @click="cmd('justifyLeft')">Left</button>
        <button type="button" class="le-btn" title="Centre" @mousedown.prevent @click="cmd('justifyCenter')">Centre</button>
        <button type="button" class="le-btn" title="Align right" @mousedown.prevent @click="cmd('justifyRight')">Right</button>
        @if($placeholders)
            <select class="le-insert" aria-label="Insert placeholder" @change="insert($event.target.value); $event.target.value = ''">
                <option value="">Insert placeholder…</option>
                @foreach($placeholders as $key => $label)<option value="{{ $key }}">{{ $label }} — {{ '{'.$key.'}' }}</option>@endforeach
            </select>
        @endif
    </div>
    <div class="le-page" contenteditable="true" x-ref="ed" @input="sync()" @blur="sync()" spellcheck="true"></div>
</div>
@if($error)<span class="error" role="alert" style="display:block;margin-top:6px">{{ $error }}</span>@endif

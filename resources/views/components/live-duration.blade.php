@props(['seconds' => 0, 'running' => false])
{{-- HH:MM:SS that keeps counting every second while the employee is working, like the desktop and
     mobile apps; the server figure replaces it on every refresh (wire:key forces a fresh start). --}}
@if($running)
<span {{ $attributes }} wire:key="live-{{ (int) $seconds }}-{{ now()->timestamp }}"
      x-data="{ base: {{ (int) $seconds }}, start: Date.now(), now: Date.now(), timer: null,
                init() { this.timer = setInterval(() => this.now = Date.now(), 1000) },
                destroy() { clearInterval(this.timer) },
                get text() { const s = this.base + Math.max(0, Math.floor((this.now - this.start) / 1000)); const p = (n) => String(n).padStart(2, '0'); return p(Math.floor(s / 3600)) + ':' + p(Math.floor(s % 3600 / 60)) + ':' + p(s % 60) } }"
      x-text="text">{{ \App\Support\Duration::hms((int) $seconds) }}</span>
@else
<span {{ $attributes }}>{{ \App\Support\Duration::hms((int) $seconds) }}</span>
@endif

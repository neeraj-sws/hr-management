@props(['root'])
{{-- A manager and everyone under them (direct and indirect reports), drawn like the Org Chart. --}}
@php
    $people = \App\Models\AttendanceEmployee::with('department')->where('is_active', true)->inCodeOrder()->get();
    $children = $people->groupBy('manager_id');
    $countReports = function (int $id, int $depth = 0) use (&$countReports, $children): int {
        if ($depth > 20) return 0;
        return $children->get($id, collect())->sum(fn ($e) => 1 + $countReports($e->id, $depth + 1));
    };
    $own = auth()->user()?->employeeProfile;
    $matches = collect();
@endphp
@if($children->get($root->id, collect())->isNotEmpty())
    <div class="org">
        <ul class="org-tree">
            @include('livewire.company.partials.org-node', ['person' => $root, 'depth' => 0])
        </ul>
    </div>
@else
    <x-empty title="No one reports to {{ $root->name }} yet" icon="users" />
@endif

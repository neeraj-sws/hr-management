<div class="table-wrap"><table class="table">
    <thead><tr><th>Date</th><th>Leave type</th><th>Entry</th><th>Details</th><th class="num">Days</th><th class="num">Balance</th><th>By</th></tr></thead>
    <tbody>
    @forelse($entries as $entry)
        <tr wire:key="ledger-{{ $entry->id }}"><td>{{ $entry->created_at?->format('d M Y, h:i A') }}</td><td>{{ $entry->leaveType?->name }}</td>
            <td><x-badge :tone="(float) $entry->days >= 0 ? 'green' : 'red'">{{ $entry->label() }}</x-badge></td><td>{{ $entry->reason }}</td>
            <td class="num">{{ (float) $entry->days > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format((float) $entry->days, 3), '0'), '.') }}</td>
            <td class="num"><strong>{{ rtrim(rtrim(number_format((float) $entry->running, 3), '0'), '.') }}</strong></td><td>{{ $entry->creator?->name ?? 'System' }}</td></tr>
    @empty
        <tr><td colspan="7"><x-empty title="No ledger entries for this leave year" icon="calendar" /></td></tr>
    @endforelse
    </tbody>
</table></div>

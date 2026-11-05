{{-- A course fee's installments: amount, due date, paid towards it, what is left and its status. $enrollment = TrainingEnrollment. --}}
@php($rowsPlan = $enrollment->installmentRows())
<div class="table-wrap"><table class="table">
    <thead><tr><th>Installment</th><th>Due on</th><th class="num">Amount</th><th class="num">Paid</th><th class="num">Left</th><th>Status</th></tr></thead>
    <tbody>
    @foreach($rowsPlan as $r)
        @php([$label, $tone] = \App\Models\TrainingEnrollment::INSTALLMENT_STATUSES[$r['status']])
        <tr><td>#{{ $r['no'] }} of {{ count($rowsPlan) }}</td><td class="nowrap">{{ \Illuminate\Support\Carbon::parse($r['due_on'])->format('d M Y') }}</td>
            <td class="num">{{ number_format($r['amount'], 2) }}</td><td class="num">{{ number_format($r['paid'], 2) }}</td><td class="num">{{ number_format($r['left'], 2) }}</td>
            <td><x-badge :tone="$tone">{{ $label }}</x-badge></td></tr>
    @endforeach
    </tbody>
</table></div>

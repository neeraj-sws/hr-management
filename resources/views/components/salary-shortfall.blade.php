@props(['s'])
{{-- "Why your salary is less than full": full salary for the working days, each cut with its dates, salary for days worked. --}}
@if($s)
<div class="why-less">
    <div class="why-less-head"><strong>{{ $s['lines'] ? 'Why the salary is less than full' : 'Full salary, no cuts' }}</strong>@if($s['estimate'])<small>so far this month</small>@endif</div>
    <table>
        <tr><td>{{ $s['full_label'] }}</td><td class="num">₹{{ number_format($s['full'], 2) }}</td></tr>
        @foreach($s['lines'] as $l)
            <tr class="why-cut"><td>− {{ $l['label'] }}@if($l['detail'])<small>{{ $l['detail'] }}</small>@endif<small>{{ rtrim(rtrim(number_format($l['days'], 2), '0'), '.') }} {{ abs($l['days'] - 1) < 0.001 ? 'day' : 'days' }} × ₹{{ number_format($s['rate'], 2) }}</small></td><td class="num">−₹{{ number_format($l['amount'], 2) }}</td></tr>
        @endforeach
        <tr class="why-total"><td>= Salary for days worked <small>{{ rtrim(rtrim(number_format($s['days_paid'], 4), '0'), '.') }} days paid</small></td><td class="num">₹{{ number_format($s['earned'], 2) }}</td></tr>
    </table>
    <p class="why-note">Bonus, reimbursements and deductions (advance, penalties, TDS) are added or taken off after this.</p>
</div>
@endif

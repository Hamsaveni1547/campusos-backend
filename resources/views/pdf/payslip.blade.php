@extends('pdf.layout')
@php
    $money = fn ($v) => number_format((float) $v, 2);
    $lines = collect($p['lines']);
    $earnings = $lines->where('kind', 'earning')->values();
    $deductions = $lines->where('kind', 'deduction')->values();
    foreach ($p['adjustments'] as $a) {
        $a['kind'] === 'deduction' ? $deductions->push(['label' => $a['label'], 'amount' => $a['amount']]) : $earnings->push(['label' => $a['label'], 'amount' => $a['amount']]);
    }
    $rows = max($earnings->count(), $deductions->count());
@endphp
@section('content')
    <table class="info">
        <tr><td class="k">Employee</td><td><strong>{{ $p['employee']['name'] }}</strong></td><td class="k">Employee code</td><td>{{ $p['employee']['code'] }}</td></tr>
        <tr><td class="k">Department</td><td>{{ $p['employee']['department'] ?? '—' }}</td><td class="k">Designation</td><td>{{ $p['designation'] ?? '—' }}</td></tr>
        <tr><td class="k">Pay period</td><td>{{ $period }}</td><td class="k">Days / loss of pay</td><td>{{ $p['days_in_month'] }} / {{ $p['lop_days'] + 0 }}</td></tr>
    </table>

    <h2>Earnings and deductions</h2>
    <table class="grid">
        <tr><th>Earnings</th><th class="r">Amount (Rs.)</th><th>Deductions</th><th class="r">Amount (Rs.)</th></tr>
        @for ($i = 0; $i < $rows; $i++)
            <tr>
                <td>{{ $earnings[$i]['label'] ?? '' }}</td><td class="r">{{ isset($earnings[$i]) ? $money($earnings[$i]['amount']) : '' }}</td>
                <td>{{ $deductions[$i]['label'] ?? '' }}</td><td class="r">{{ isset($deductions[$i]) ? $money($deductions[$i]['amount']) : '' }}</td>
            </tr>
        @endfor
        <tr class="total"><td>Gross</td><td class="r">{{ $money($p['gross']) }}</td><td>Total deductions</td><td class="r">{{ $money($p['deductions']) }}</td></tr>
    </table>
    <h2>Net pay: Rs. {{ $money($p['net']) }}</h2>
@endsection

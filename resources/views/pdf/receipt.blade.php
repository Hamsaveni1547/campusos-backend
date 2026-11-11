@extends('pdf.layout')
@php($money = fn ($v) => 'Rs. '.number_format((float) $v, 2))
@section('content')
    <table class="info">
        <tr><td class="k">Receipt no.</td><td><strong>{{ $r['receipt_no'] }}</strong></td><td class="k">Date</td><td>{{ \Illuminate\Support\Carbon::parse($r['paid_on'])->format('d M Y') }}</td></tr>
        <tr><td class="k">Student</td><td>{{ $r['student']['name'] }}</td><td class="k">Register no.</td><td>{{ $r['student']['register_no'] }}</td></tr>
        <tr><td class="k">Programme</td><td colspan="3">{{ $r['programme'] }}</td></tr>
        <tr><td class="k">Mode</td><td>{{ strtoupper($r['mode']) }}</td><td class="k">Reference</td><td>{{ $r['reference'] ?? '—' }}</td></tr>
    </table>

    <h2>Paid towards</h2>
    <table class="grid">
        <tr><th>Fee</th><th class="r">Amount</th></tr>
        @foreach ($r['paid_for'] as $line)
            <tr><td>{{ $line['demand'] }}</td><td class="r">{{ $money($line['amount']) }}</td></tr>
        @endforeach
        <tr class="total"><td>Total received</td><td class="r">{{ $money($r['amount']) }}</td></tr>
    </table>
    <p class="muted">Balance after this payment: {{ $money($r['balance_after']) }}</p>
    @if ($r['status'] === 'cancelled')<p><strong>CANCELLED</strong> — {{ $r['cancel_reason'] }}</p>@endif

    <table class="stamp"><tr><td>Received by: {{ $r['received_by'] ?? 'Online payment' }}</td><td class="r">Accounts Office</td></tr></table>
@endsection

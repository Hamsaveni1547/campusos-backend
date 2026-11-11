@extends('pdf.layout')
@section('content')
    <table class="info">
        <tr><td class="k">Examination</td><td colspan="3"><strong>{{ $t['cycle']['name'] }}</strong></td></tr>
        <tr><td class="k">Student</td><td>{{ $t['student']['name'] }}</td><td class="k">Register no.</td><td><strong>{{ $t['student']['register_no'] }}</strong></td></tr>
        <tr><td class="k">Programme</td><td colspan="3">{{ $t['student']['programme'] }}</td></tr>
    </table>

    <h2>Exam timetable</h2>
    <table class="grid">
        <tr><th>Course</th><th>Title</th><th>Date</th><th>Time</th></tr>
        @foreach ($t['exams'] as $e)
            <tr>
                <td>{{ $e['code'] }}</td><td>{{ $e['title'] }}</td>
                <td>{{ $e['exam'] ? \Illuminate\Support\Carbon::parse($e['exam']['date'])->format('D, d M Y') : 'To be announced' }}</td>
                <td>{{ $e['exam'] ? $e['exam']['starts_at'].' – '.$e['exam']['ends_at'] : '' }}</td>
            </tr>
        @endforeach
    </table>

    <h2>Instructions</h2>
    <ol class="muted">
        <li>Carry this hall ticket and your college ID card to every exam.</li>
        <li>Be seated 15 minutes before the start. No entry 30 minutes after the start.</li>
        <li>Mobile phones and smart watches are not allowed in the exam hall.</li>
    </ol>

    <table class="stamp"><tr><td>Signature of the student</td><td class="r">Controller of Examinations</td></tr></table>
@endsection

@extends('pdf.layout')
@section('content')
    <table class="info">
        <tr><td class="k">Student</td><td><strong>{{ $student['name'] }}</strong></td><td class="k">Register no.</td><td><strong>{{ $student['register_no'] }}</strong></td></tr>
        <tr><td class="k">Programme</td><td>{{ $student['programme'] }}</td><td class="k">Semester</td><td>{{ $result['semester'] ?? '—' }}</td></tr>
        <tr><td class="k">Examination</td><td>{{ $result['cycle']['name'] }}</td><td class="k">Term</td><td>{{ $result['term'] }}</td></tr>
    </table>

    <h2>Courses</h2>
    <table class="grid">
        <tr><th>Code</th><th>Course</th><th class="r">Credits</th><th class="r">Internal</th><th class="r">Exam</th><th class="r">Total</th><th class="c">Grade</th><th class="r">Points</th><th>Result</th></tr>
        @foreach ($result['courses'] as $c)
            <tr>
                <td>{{ $c['code'] }}</td><td>{{ $c['title'] }}</td><td class="r">{{ $c['credits'] + 0 }}</td>
                <td class="r">{{ $c['internal'] !== null ? $c['internal'] + 0 : '—' }}</td><td class="r">{{ $c['external'] !== null ? $c['external'] + 0 : '—' }}</td>
                <td class="r">{{ $c['total'] !== null ? $c['total'] + 0 : '—' }}</td><td class="c"><strong>{{ $c['grade'] ?? '—' }}</strong></td>
                <td class="r">{{ $c['grade_points'] !== null ? $c['grade_points'] + 0 : '—' }}</td><td>{{ $c['passed'] === false ? 'Fail' : ($c['passed'] ? 'Pass' : '') }}</td>
            </tr>
        @endforeach
    </table>

    <table class="info" style="margin-top: 10px;">
        <tr><td class="k">Credits earned</td><td>{{ $result['credits_earned'] + 0 }} of {{ $result['credits_registered'] + 0 }}</td><td class="k">SGPA</td><td><strong>{{ $result['sgpa'] ?? '—' }}</strong></td></tr>
        <tr><td class="k">Backlogs</td><td>{{ $backlogs }}</td><td class="k">CGPA</td><td><strong>{{ $result['cgpa'] ?? '—' }}</strong></td></tr>
    </table>

    <table class="stamp"><tr><td class="muted">Results published {{ $result['cycle']['published_at'] ? \Illuminate\Support\Carbon::parse($result['cycle']['published_at'])->format('d M Y') : '' }}</td><td class="r">Controller of Examinations</td></tr></table>
@endsection

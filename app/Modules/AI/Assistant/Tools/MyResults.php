<?php

namespace App\Modules\AI\Assistant\Tools;

use App\Models\User;
use App\Modules\Exams\Models\ExamRegistration;
use App\Modules\Exams\Models\ExamResult;

class MyResults extends StudentTool
{
    public function name(): string
    {
        return 'my_results';
    }

    public function description(): string
    {
        return "The student's published exam results: SGPA and CGPA per exam, backlogs, and courses not yet cleared.";
    }

    public function run(User $user, array $arguments): array
    {
        $student = $this->student($user);

        if ($student === null) {
            return [];
        }

        $results = ExamResult::query()->with('cycle')->where('student_id', $student->id)->whereHas('cycle', fn ($q) => $q->where('status', 'published'))->orderBy('exam_cycle_id')->get();
        $failed = ExamRegistration::query()->with('course')->where('student_id', $student->id)->where('passed', false)->whereIn('exam_cycle_id', $results->pluck('exam_cycle_id'))->get();

        return [
            'exams' => $results->map(fn (ExamResult $r): array => ['exam' => $r->cycle->name, 'sgpa' => $r->sgpa, 'cgpa' => $r->cgpa, 'backlogs' => $r->backlogs])->values()->all(),
            'courses_not_cleared' => $failed->map(fn (ExamRegistration $r): string => $r->course->code.' '.$r->course->title)->unique()->values()->all(),
        ];
    }
}

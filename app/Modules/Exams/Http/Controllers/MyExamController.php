<?php

namespace App\Modules\Exams\Http\Controllers;

use App\Models\User;
use App\Modules\Academics\Models\Course;
use App\Modules\Exams\Models\ExamCycle;
use App\Modules\Exams\Models\ExamRegistration;
use App\Modules\Exams\Models\ExamResult;
use App\Modules\Exams\Models\ExamSchedule;
use App\Modules\Exams\Models\RevaluationRequest;
use App\Modules\Exams\Services\ExamService;
use App\Modules\Students\Models\Student;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @tags Examinations (students)
 */
class MyExamController extends Controller
{
    public function __construct(private ExamService $exams) {}

    /**
     * Unpublished exam cycles the student is in or can register backlogs for, with courses and the timetable.
     */
    public function index(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $backlogs = $this->exams->backlogs($student);
        $cycles = ExamCycle::query()->with('term')->whereIn('status', ['registration_open', 'registration_closed', 'results_processed', 'results_verified'])
            ->where(fn ($query) => $query->where('status', 'registration_open')->orWhereHas('registrations', fn ($q) => $q->where('student_id', $student->id)))
            ->latest('id')->get();

        return ApiResponse::data([
            'cycles' => $cycles->map(function (ExamCycle $cycle) use ($student, $backlogs): array {
                $mine = $cycle->registrations()->with('course')->where('student_id', $student->id)->get();
                $schedules = $cycle->schedules()->get()->keyBy('course_id');

                return [
                    'id' => $cycle->ulid,
                    'name' => $cycle->name,
                    'status' => $cycle->status,
                    'registration_ends_on' => $cycle->registration_ends_on?->toDateString(),
                    'hall_ticket' => $cycle->status !== 'registration_open' && $mine->contains('eligibility', 'eligible'),
                    'courses' => $mine->map(fn (ExamRegistration $r): array => [
                        'code' => $r->course->code,
                        'title' => $r->course->title,
                        'kind' => $r->kind,
                        'eligibility' => $r->eligibility,
                        'reasons' => $r->eligibility_reasons ?? [],
                        'exam' => $this->exam($schedules->get($r->course_id)),
                    ])->values(),
                    'backlogs_open' => $cycle->status === 'registration_open'
                        ? $backlogs->reject(fn (ExamRegistration $b): bool => $mine->contains('course_id', $b->course_id))
                            ->map(fn (ExamRegistration $b): array => ['course_id' => $b->course->ulid, 'code' => $b->course->code, 'title' => $b->course->title, 'grade' => $b->grade])->values()
                        : [],
                ];
            })->values(),
        ]);
    }

    /**
     * Register for backlog courses: `course_ids`.
     */
    public function registerBacklogs(Request $request, ExamCycle $cycle): JsonResponse
    {
        $validated = $request->validate(['course_ids' => ['required', 'array', 'min:1'], 'course_ids.*' => ['string', 'size:26']]);
        $ids = Course::query()->whereIn('ulid', $validated['course_ids'])->pluck('id')->all();
        $this->exams->registerBacklogs($cycle, $this->student($request), $ids);

        return ApiResponse::message('Registered.');
    }

    /**
     * Hall ticket: eligible courses with the timetable, once registration has closed.
     */
    public function hallTicket(Request $request, ExamCycle $cycle): JsonResponse
    {
        $student = $this->student($request)->load(['user', 'programme', 'photo']);
        $rows = $cycle->registrations()->with('course')->where('student_id', $student->id)->where('eligibility', 'eligible')->get();

        if ($cycle->status === 'registration_open' || $cycle->status === 'draft' || $rows->isEmpty()) {
            throw new BusinessRuleException('Your hall ticket is not available.', 'HALL_TICKET_UNAVAILABLE', 404);
        }

        $schedules = $cycle->schedules()->get()->keyBy('course_id');

        return ApiResponse::data([
            'cycle' => ['id' => $cycle->ulid, 'name' => $cycle->name],
            'student' => ['name' => $student->user->name, 'register_no' => $student->register_no, 'programme' => $student->programme->name, 'photo_id' => $student->photo?->ulid],
            'exams' => $rows->map(fn (ExamRegistration $r): array => ['code' => $r->course->code, 'title' => $r->course->title, 'exam' => $this->exam($schedules->get($r->course_id))])
                ->sortBy(fn (array $e): string => ($e['exam']['date'] ?? '9999').($e['exam']['starts_at'] ?? ''))->values(),
        ]);
    }

    /**
     * Published results, newest first, with each course's grade and any revaluation.
     */
    public function results(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $results = ExamResult::query()->with('cycle')->where('student_id', $student->id)->whereHas('cycle', fn ($query) => $query->where('status', 'published'))->get()->sortByDesc('exam_cycle_id');
        $rows = ExamRegistration::query()->with('course')->where('student_id', $student->id)->whereIn('exam_cycle_id', $results->pluck('exam_cycle_id'))->get();
        $revaluations = RevaluationRequest::query()->whereIn('exam_registration_id', $rows->pluck('id'))->get()->keyBy('exam_registration_id');

        return ApiResponse::data([
            'cgpa' => $results->first()?->cgpa === null ? null : (float) $results->first()->cgpa,
            'backlogs' => $results->first()->backlogs ?? 0,
            'results' => $results->map(fn (ExamResult $result): array => [
                'cycle' => ['id' => $result->cycle->ulid, 'name' => $result->cycle->name, 'published_at' => $result->cycle->published_at?->toIso8601String()],
                'sgpa' => $result->sgpa === null ? null : (float) $result->sgpa,
                'cgpa' => $result->cgpa === null ? null : (float) $result->cgpa,
                'credits_earned' => (float) $result->credits_earned,
                'credits_registered' => (float) $result->credits_registered,
                'courses' => $rows->where('exam_cycle_id', $result->exam_cycle_id)->map(fn (ExamRegistration $r): array => [
                    'id' => $r->ulid,
                    'code' => $r->course->code,
                    'title' => $r->course->title,
                    'credits' => (float) $r->course->credits,
                    'internal' => $r->internal_marks === null ? null : (float) $r->internal_marks,
                    'external' => $r->external_marks === null ? null : (float) $r->external_marks,
                    'grade' => $r->grade,
                    'passed' => $r->passed,
                    'revaluation' => ($rv = $revaluations->get($r->id)) === null ? null : ['status' => $rv->status, 'old_marks' => $rv->old_marks === null ? null : (float) $rv->old_marks, 'new_marks' => $rv->new_marks === null ? null : (float) $rv->new_marks, 'remarks' => $rv->remarks],
                    'can_request_revaluation' => $r->attendance === 'present' && ! $revaluations->has($r->id),
                ])->values(),
            ])->values(),
        ]);
    }

    /**
     * Ask for revaluation of one course's exam: `registration_id`, `reason`.
     */
    public function requestRevaluation(Request $request): JsonResponse
    {
        $validated = $request->validate(['registration_id' => ['required', 'string', 'size:26'], 'reason' => ['required', 'string', 'max:500']]);
        $student = $this->student($request);
        $registration = ExamRegistration::query()->where('ulid', $validated['registration_id'])->where('student_id', $student->id)->firstOrFail();
        $revaluation = $this->exams->requestRevaluation($registration, $validated['reason'], $this->user($request));

        return ApiResponse::data(['id' => $revaluation->ulid], 201);
    }

    /**
     * @return array{date: string, starts_at: string, ends_at: string}|null
     */
    private function exam(?ExamSchedule $schedule): ?array
    {
        return $schedule === null ? null : ['date' => $schedule->exam_date->toDateString(), 'starts_at' => substr($schedule->starts_at, 0, 5), 'ends_at' => substr($schedule->ends_at, 0, 5)];
    }

    private function student(Request $request): Student
    {
        return Student::query()->where('user_id', $this->user($request)->id)->first() ?? throw new BusinessRuleException('You do not have a student record in this institution.', 'NOT_A_STUDENT', 404);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}

<?php

namespace App\Modules\Timetable\Http\Controllers;

use App\Models\User;
use App\Modules\Academics\Models\OfferingSection;
use App\Modules\HR\Models\Employee;
use App\Modules\Institution\Models\Room;
use App\Modules\Institution\Models\Section;
use App\Modules\Institution\Models\Term;
use App\Modules\Students\Models\Student;
use App\Modules\Timetable\Authorization\TimetablePermissions;
use App\Modules\Timetable\Models\TimeSlot;
use App\Modules\Timetable\Models\Timetable;
use App\Modules\Timetable\Models\TimetableAdjustment;
use App\Modules\Timetable\Models\TimetableEntry;
use App\Modules\Timetable\Services\TimetableService;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Published timetables by section, teacher or room; personal timetables; day-to-day changes.
 *
 * @tags Timetable: views & changes
 */
class ScheduleController extends Controller
{
    public function __construct(private TimetableService $timetables) {}

    /**
     * Published week for one of `filter[section]`, `filter[teacher]` (employee id), `filter[room]`; `filter[term]` default current.
     */
    public function week(Request $request): JsonResponse
    {
        Gate::authorize(TimetablePermissions::View);
        [$by, $id] = $this->target($request);

        return ApiResponse::data($this->weekFor($by, $id, $this->term($request->input('filter.term'))));
    }

    /**
     * One day (default today) with cancellations, substitutions and extra classes applied. Same filters as the week.
     */
    public function day(Request $request): JsonResponse
    {
        Gate::authorize(TimetablePermissions::View);
        $request->validate(['date' => ['sometimes', 'date']]);
        [$by, $id] = $this->target($request);
        $date = Carbon::parse($request->input('date', now()->toDateString()));

        return ApiResponse::data(['date' => $date->toDateString(), 'periods' => $this->timetables->day($date, $by, $id, $this->term(null)?->id)]);
    }

    /**
     * The signed-in student's or teacher's week and day (`date`, default today).
     */
    public function mine(Request $request): JsonResponse
    {
        $request->validate(['date' => ['sometimes', 'date']]);

        /** @var User $user */
        $user = $request->user();
        $date = Carbon::parse($request->input('date', now()->toDateString()));
        $term = $this->term(null);

        $student = Student::query()->where('user_id', $user->id)->whereNotNull('section_id')->first();
        $employee = Employee::query()->where('user_id', $user->id)->first();

        [$by, $id] = $student !== null ? ['section', (int) $student->section_id] : ($employee !== null ? ['teacher', $employee->id] : [null, null]);

        if ($by === null) {
            return ApiResponse::data(['as' => null, 'date' => $date->toDateString(), 'today' => [], 'week' => null]);
        }

        return ApiResponse::data([
            'as' => $by === 'section' ? 'student' : 'teacher',
            'date' => $date->toDateString(),
            'today' => $this->timetables->day($date, $by, $id, $term?->id),
            'week' => $this->weekFor($by, $id, $term),
        ]);
    }

    /**
     * Changes in a date range: `filter[from]`, `filter[to]` (default: this week).
     */
    public function adjustments(Request $request): JsonResponse
    {
        Gate::authorize(TimetablePermissions::View);
        $from = Carbon::parse($request->input('filter.from', now()->startOfWeek()->toDateString()));
        $to = Carbon::parse($request->input('filter.to', now()->endOfWeek()->toDateString()));

        $rows = TimetableAdjustment::query()
            ->with(['slot', 'class.offering.course', 'class.section.batch', 'employee.user', 'entry.employee.user', 'room'])
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->orderBy('date')
            ->get();

        return ApiResponse::list($rows, fn (TimetableAdjustment $adjustment): array => [
            'id' => $adjustment->ulid,
            'date' => $adjustment->date->toDateString(),
            'type' => $adjustment->type,
            'slot' => $adjustment->slot === null ? null : ['label' => $adjustment->slot->label, 'starts_at' => substr($adjustment->slot->starts_at, 0, 5)],
            'course' => $adjustment->class?->offering->course->code,
            'section' => $adjustment->class === null ? null : $adjustment->class->section->batch->name.' '.$adjustment->class->section->name,
            'teacher' => $adjustment->entry?->employee?->user->name,
            'substitute' => $adjustment->type !== 'cancelled' ? $adjustment->employee?->user->name : null,
            'room' => $adjustment->room?->code,
            'reason' => $adjustment->reason,
        ]);
    }

    /**
     * Cancel a period on a date, or give it to a substitute: `entry_id, date, type (cancelled|substituted), substitute_employee_id?, reason?`.
     */
    public function adjust(Request $request): JsonResponse
    {
        Gate::authorize(TimetablePermissions::Adjust);
        $validated = $request->validate([
            'entry_id' => ['required', 'string', 'size:26'],
            'date' => ['required', 'date', 'after_or_equal:today'],
            'type' => ['required', 'in:cancelled,substituted'],
            'substitute_employee_id' => ['nullable', 'required_if:type,substituted', 'string', 'size:26'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $entry = TimetableEntry::query()->with(['timetable.term', 'slot'])->where('ulid', $validated['entry_id'])->firstOrFail();
        $substitute = isset($validated['substitute_employee_id']) ? Employee::query()->where('ulid', $validated['substitute_employee_id'])->firstOrFail() : null;

        $adjustment = $this->timetables->adjust($entry, Carbon::parse($validated['date']), $validated['type'], $substitute, $validated['reason'] ?? null);

        return ApiResponse::data(['id' => $adjustment->ulid, 'message' => $validated['type'] === 'cancelled' ? 'Class cancelled for that day.' : 'Substitute assigned.'], 201);
    }

    /**
     * Extra class: `timetable_id, date, time_slot_id, offering_section_id, employee_id?, room_id?, reason?`.
     */
    public function extra(Request $request): JsonResponse
    {
        Gate::authorize(TimetablePermissions::Adjust);
        $validated = $request->validate([
            'timetable_id' => ['required', 'string', 'size:26'],
            'date' => ['required', 'date', 'after_or_equal:today'],
            'time_slot_id' => ['required', 'string', 'size:26'],
            'offering_section_id' => ['required', 'string', 'size:26'],
            'employee_id' => ['nullable', 'string', 'size:26'],
            'room_id' => ['nullable', 'string', 'size:26'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $adjustment = $this->timetables->extraClass(
            Timetable::query()->with('term')->where('ulid', $validated['timetable_id'])->firstOrFail(),
            Carbon::parse($validated['date']),
            TimeSlot::query()->where('ulid', $validated['time_slot_id'])->firstOrFail(),
            OfferingSection::query()->with('offering.course')->where('ulid', $validated['offering_section_id'])->firstOrFail(),
            isset($validated['employee_id']) ? Employee::query()->where('ulid', $validated['employee_id'])->firstOrFail() : null,
            isset($validated['room_id']) ? Room::query()->where('ulid', $validated['room_id'])->value('id') : null,
            $validated['reason'] ?? null,
        );

        return ApiResponse::data(['id' => $adjustment->ulid, 'message' => 'Extra class added.'], 201);
    }

    public function undo(string $timetableAdjustment): JsonResponse
    {
        Gate::authorize(TimetablePermissions::Adjust);
        TimetableAdjustment::query()->where('ulid', $timetableAdjustment)->firstOrFail()->delete();

        return ApiResponse::message('Change removed; the regular timetable applies again.');
    }

    /**
     * @return array<string, mixed>
     */
    private function weekFor(string $by, int $id, ?Term $term): array
    {
        $entries = TimetableEntry::query()
            ->with(['slot', 'class.offering.course', 'class.section.batch', 'employee.user', 'room'])
            ->whereHas('timetable', fn ($query) => $query->where('status', 'published')->where('term_id', $term->id ?? 0)->when($by === 'section', fn ($query) => $query->where('section_id', $id)))
            ->when($by === 'teacher', fn ($query) => $query->where('employee_id', $id))
            ->when($by === 'room', fn ($query) => $query->where('room_id', $id))
            ->get()
            ->sortBy(fn (TimetableEntry $entry): string => $entry->day_of_week.$entry->slot->starts_at)
            ->values();

        return [
            'term' => $term === null ? null : ['id' => $term->ulid, 'name' => $term->name],
            'entries' => collect(TimetableService::grid($entries))->map(function (array $row, int $index) use ($entries): array {
                $slot = $entries[$index]->slot;

                return $row + ['slot' => $slot->label, 'starts_at' => substr($slot->starts_at, 0, 5), 'ends_at' => substr($slot->ends_at, 0, 5)];
            })->values()->all(),
        ];
    }

    /**
     * @return array{0: 'section'|'teacher'|'room', 1: int}
     */
    private function target(Request $request): array
    {
        foreach (['section' => Section::class, 'teacher' => Employee::class, 'room' => Room::class] as $by => $model) {
            if ($request->filled('filter.'.$by)) {
                $id = $model::query()->where('ulid', $request->input('filter.'.$by))->value('id');

                if ($id === null) {
                    abort(404);
                }

                return [$by, (int) $id];
            }
        }

        throw new BusinessRuleException('Choose a section, teacher or room.', 'TARGET_REQUIRED', 422);
    }

    private function term(?string $ulid): ?Term
    {
        return $ulid ? Term::query()->where('ulid', $ulid)->first() : Term::query()->where('is_current', true)->first();
    }
}

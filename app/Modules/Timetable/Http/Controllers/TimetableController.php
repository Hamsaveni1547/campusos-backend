<?php

namespace App\Modules\Timetable\Http\Controllers;

use App\Models\User;
use App\Modules\Academics\Models\FacultyAllocation;
use App\Modules\Academics\Models\OfferingSection;
use App\Modules\HR\Models\Employee;
use App\Modules\Institution\Models\Room;
use App\Modules\Institution\Models\Section;
use App\Modules\Institution\Models\Term;
use App\Modules\Timetable\Authorization\TimetablePermissions;
use App\Modules\Timetable\Models\SlotTemplate;
use App\Modules\Timetable\Models\TimeSlot;
use App\Modules\Timetable\Models\Timetable;
use App\Modules\Timetable\Models\TimetableEntry;
use App\Modules\Timetable\Services\TimetableService;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Building and publishing section timetables.
 *
 * @tags Timetable: builder
 */
class TimetableController extends Controller
{
    private const ENTRY_RELATIONS = ['entries.slot', 'entries.class.offering.course', 'entries.class.section.batch', 'entries.employee.user', 'entries.room'];

    public function __construct(private TimetableService $timetables) {}

    /**
     * Timetables of a term. Filters: `filter[term]` (default current), `filter[status]`, `filter[programme]`.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(TimetablePermissions::View);
        $term = $this->term($request->input('filter.term'));

        $timetables = Timetable::query()
            ->with(['section.batch', 'template', 'term', 'publisher'])
            ->withCount('entries')
            ->where('term_id', $term->id ?? 0)
            ->when($request->input('filter.status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->input('filter.programme'), fn ($query, $id) => $query->whereHas('section.batch.programme', fn ($query) => $query->where('ulid', $id)))
            ->get()
            ->sortBy(fn (Timetable $timetable): string => $timetable->section->batch->name.$timetable->section->name)
            ->values();

        return ApiResponse::list($timetables, fn (Timetable $timetable): array => $this->summary($timetable));
    }

    /**
     * Start a section's timetable for a term: `term_id, section_id, slot_template_id`.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize(TimetablePermissions::Manage);
        $validated = $request->validate(['term_id' => ['required', 'string', 'size:26'], 'section_id' => ['required', 'string', 'size:26'], 'slot_template_id' => ['required', 'string', 'size:26']]);

        $term = Term::query()->where('ulid', $validated['term_id'])->firstOrFail();
        $section = Section::query()->where('ulid', $validated['section_id'])->firstOrFail();

        if (Timetable::query()->where('term_id', $term->id)->where('section_id', $section->id)->exists()) {
            throw new BusinessRuleException('This section already has a timetable for the term.', 'TIMETABLE_EXISTS', 409);
        }

        $timetable = Timetable::query()->create([
            'term_id' => $term->id,
            'section_id' => $section->id,
            'slot_template_id' => SlotTemplate::query()->where('ulid', $validated['slot_template_id'])->firstOrFail()->id,
        ]);

        return $this->show($timetable, 201);
    }

    /**
     * The builder view: periods, entries, classes of the section with their teachers, clashes and planned hours.
     */
    public function show(Timetable $timetable, int $status = 200): JsonResponse
    {
        Gate::authorize(TimetablePermissions::View);
        $timetable->load(['section.batch', 'template.slots', 'term', 'publisher', ...self::ENTRY_RELATIONS])->loadCount('entries');

        $classes = OfferingSection::query()
            ->with(['offering.course', 'allocations.employee.user'])
            ->where('section_id', $timetable->section_id)
            ->whereHas('offering', fn ($query) => $query->where('term_id', $timetable->term_id))
            ->get();

        return ApiResponse::data($this->summary($timetable) + [
            'slots' => SlotTemplateController::present($timetable->template)['slots'],
            'working_days' => $timetable->template->working_days,
            'entries' => TimetableService::grid($timetable->entries),
            'classes' => $classes->map(fn (OfferingSection $class): array => [
                'id' => $class->ulid,
                'course' => ['code' => $class->offering->course->code, 'title' => $class->offering->course->title],
                'teachers' => $class->allocations->map(fn (FacultyAllocation $allocation): array => ['id' => $allocation->employee->ulid, 'name' => $allocation->employee->user->name, 'component' => $allocation->component->value])->values()->all(),
            ])->sortBy('course.code')->values()->all(),
            'planned_hours' => $this->timetables->plannedHours($timetable),
            'clashes' => $this->timetables->clashes($timetable),
        ], $status);
    }

    /**
     * Put a class in a period: `day_of_week, time_slot_id, offering_section_id, employee_id?, room_id?, component?`.
     * Refused when the section, teacher or room is already busy then.
     */
    public function storeEntry(Request $request, Timetable $timetable): JsonResponse
    {
        Gate::authorize(TimetablePermissions::Manage);
        $this->timetables->saveEntry($timetable, $this->entryData($request));

        return $this->show($timetable->refresh());
    }

    public function updateEntry(Request $request, string $timetableEntry): JsonResponse
    {
        Gate::authorize(TimetablePermissions::Manage);
        $entry = TimetableEntry::query()->with('timetable.template')->where('ulid', $timetableEntry)->firstOrFail();
        $this->timetables->saveEntry($entry->timetable, $this->entryData($request), $entry);

        return $this->show($entry->timetable->refresh());
    }

    public function destroyEntry(string $timetableEntry): JsonResponse
    {
        Gate::authorize(TimetablePermissions::Manage);
        $entry = TimetableEntry::query()->with('timetable')->where('ulid', $timetableEntry)->firstOrFail();
        $this->timetables->deleteEntry($entry);

        return $this->show($entry->timetable->refresh());
    }

    public function publish(Request $request, Timetable $timetable): JsonResponse
    {
        Gate::authorize(TimetablePermissions::Publish);

        /** @var User $user */
        $user = $request->user();
        $this->timetables->publish($timetable, $user);

        return $this->show($timetable->refresh());
    }

    /**
     * Back to draft for changes (the section has no published timetable until it is published again).
     */
    public function unpublish(Timetable $timetable): JsonResponse
    {
        Gate::authorize(TimetablePermissions::Publish);
        $this->timetables->unpublish($timetable);

        return $this->show($timetable->refresh());
    }

    public function destroy(Timetable $timetable): JsonResponse
    {
        Gate::authorize(TimetablePermissions::Manage);

        if ($timetable->isPublished()) {
            throw new BusinessRuleException('Take the timetable back to draft first.', 'TIMETABLE_PUBLISHED', 409);
        }

        $timetable->delete();

        return ApiResponse::message('Timetable deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function entryData(Request $request): array
    {
        $validated = $request->validate([
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'time_slot_id' => ['required', 'string', 'size:26'],
            'offering_section_id' => ['required', 'string', 'size:26'],
            'employee_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'room_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'component' => ['sometimes', 'nullable', 'in:theory,lab,tutorial'],
        ]);

        $data = [
            'day_of_week' => (int) $validated['day_of_week'],
            'time_slot_id' => (int) (TimeSlot::query()->where('ulid', $validated['time_slot_id'])->value('id') ?? 0),
            'offering_section_id' => (int) (OfferingSection::query()->where('ulid', $validated['offering_section_id'])->value('id') ?? 0),
            'room_id' => isset($validated['room_id']) ? Room::query()->where('ulid', $validated['room_id'])->value('id') : null,
            'component' => $validated['component'] ?? null,
        ];

        if (array_key_exists('employee_id', $validated)) {
            $data['employee_id'] = $validated['employee_id'] === null ? null : Employee::query()->where('ulid', $validated['employee_id'])->value('id');
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Timetable $timetable): array
    {
        return [
            'id' => $timetable->ulid,
            'term' => ['id' => $timetable->term->ulid, 'name' => $timetable->term->name],
            'section' => ['id' => $timetable->section->ulid, 'name' => $timetable->section->name, 'batch' => $timetable->section->batch->name],
            'template' => ['id' => $timetable->template->ulid, 'name' => $timetable->template->name],
            'status' => $timetable->status,
            'version' => $timetable->version,
            'periods_count' => $timetable->entries_count ?? null,
            'published_by' => $timetable->publisher?->name,
            'published_at' => $timetable->published_at?->toIso8601String(),
        ];
    }

    private function term(?string $ulid): ?Term
    {
        return $ulid ? Term::query()->where('ulid', $ulid)->first() : Term::query()->where('is_current', true)->first();
    }
}

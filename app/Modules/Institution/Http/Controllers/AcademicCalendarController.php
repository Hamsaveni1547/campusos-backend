<?php

namespace App\Modules\Institution\Http\Controllers;

use App\Modules\Institution\Authorization\InstitutionPermissions;
use App\Modules\Institution\Enums\Audience;
use App\Modules\Institution\Enums\CalendarEventType;
use App\Modules\Institution\Enums\PeriodStatus;
use App\Modules\Institution\Enums\TermType;
use App\Modules\Institution\Http\Resources\AcademicResources;
use App\Modules\Institution\Models\AcademicYear;
use App\Modules\Institution\Models\CalendarEvent;
use App\Modules\Institution\Models\Term;
use App\Modules\Institution\Services\AcademicCalendar;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Academic years, terms (semesters) and calendar events (holidays, exams, deadlines).
 *
 * @tags Academic calendar
 */
class AcademicCalendarController extends Controller
{
    public function __construct(private AcademicCalendar $calendar) {}

    /**
     * Current academic year and term (used by most screens).
     */
    public function context(): JsonResponse
    {
        $year = $this->calendar->current();
        $term = $this->calendar->currentTerm();

        return ApiResponse::data([
            'academic_year' => $year === null ? null : AcademicResources::year($year),
            'term' => $term === null ? null : AcademicResources::term($term),
        ]);
    }

    public function years(): JsonResponse
    {
        return ApiResponse::list(
            AcademicYear::query()->with('terms')->orderByDesc('starts_on')->get(),
            fn (AcademicYear $year): array => AcademicResources::year($year),
        );
    }

    public function showYear(AcademicYear $academicYear): JsonResponse
    {
        return ApiResponse::data(AcademicResources::year($academicYear->load('terms')));
    }

    public function storeYear(Request $request): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::CalendarManage);
        $validated = $request->validate($this->yearRules());

        $this->calendar->assertYearDates(Carbon::parse($validated['starts_on']), Carbon::parse($validated['ends_on']));
        $year = AcademicYear::query()->create($validated + ['status' => PeriodStatus::Planned]);

        return ApiResponse::data(AcademicResources::year($year->load('terms')), 201);
    }

    public function updateYear(Request $request, AcademicYear $academicYear): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::CalendarManage);
        $this->assertNotClosed($academicYear->status);
        $validated = $request->validate($this->yearRules($academicYear));

        $this->calendar->assertYearDates(
            Carbon::parse($validated['starts_on'] ?? $academicYear->starts_on),
            Carbon::parse($validated['ends_on'] ?? $academicYear->ends_on),
            $academicYear,
        );

        $academicYear->update($validated);

        return ApiResponse::data(AcademicResources::year($academicYear->load('terms')));
    }

    public function destroyYear(AcademicYear $academicYear): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::CalendarManage);

        if ($academicYear->status !== PeriodStatus::Planned) {
            throw new BusinessRuleException('Only a planned academic year can be deleted.', 'ACADEMIC_YEAR_IN_USE', 409);
        }

        return ApiResponse::deleteUnlessUsed($academicYear, ['terms' => 'terms'], 'academic year');
    }

    public function makeYearCurrent(AcademicYear $academicYear): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::CalendarManage);

        return ApiResponse::data(AcademicResources::year($this->calendar->makeYearCurrent($academicYear)->load('terms')));
    }

    public function closeYear(AcademicYear $academicYear): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::CalendarManage);

        return ApiResponse::data(AcademicResources::year($this->calendar->closeYear($academicYear)->load('terms')));
    }

    /**
     * Terms. Filter: `filter[academic_year]` (id), `filter[status]`.
     */
    public function terms(Request $request): JsonResponse
    {
        $terms = Term::query()
            ->with('academicYear')
            ->when($request->input('filter.academic_year'), fn ($query, $id) => $query->whereHas('academicYear', fn ($query) => $query->where('ulid', $id)))
            ->when($request->input('filter.status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('starts_on')
            ->get();

        return ApiResponse::list($terms, fn (Term $term): array => AcademicResources::term($term));
    }

    public function storeTerm(Request $request): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::CalendarManage);
        $validated = $request->validate($this->termRules());

        $year = AcademicYear::query()->where('ulid', $validated['academic_year_id'])->firstOrFail();
        $this->assertNotClosed($year->status);

        if ($year->terms()->where('type', $validated['type'])->exists()) {
            throw new BusinessRuleException('This academic year already has a term of this type.', 'TERM_TYPE_EXISTS', 409);
        }

        $this->calendar->assertTermDates($year, Carbon::parse($validated['starts_on']), Carbon::parse($validated['ends_on']));

        $term = Term::query()->create(array_merge($validated, ['academic_year_id' => $year->id, 'status' => PeriodStatus::Planned]));

        return ApiResponse::data(AcademicResources::term($term->load('academicYear')), 201);
    }

    public function updateTerm(Request $request, Term $term): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::CalendarManage);
        $this->assertNotClosed($term->status);
        $validated = $request->validate(collect($this->termRules(true))->except(['academic_year_id', 'type'])->all());

        $this->calendar->assertTermDates(
            $term->academicYear,
            Carbon::parse($validated['starts_on'] ?? $term->starts_on),
            Carbon::parse($validated['ends_on'] ?? $term->ends_on),
            $term,
        );

        $term->update($validated);

        return ApiResponse::data(AcademicResources::term($term->load('academicYear')));
    }

    public function destroyTerm(Term $term): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::CalendarManage);

        if ($term->status !== PeriodStatus::Planned) {
            throw new BusinessRuleException('Only a planned term can be deleted.', 'TERM_IN_USE', 409);
        }

        $term->delete();

        return ApiResponse::message('Term deleted.');
    }

    public function makeTermCurrent(Term $term): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::CalendarManage);

        return ApiResponse::data(AcademicResources::term($this->calendar->makeTermCurrent($term)->load('academicYear')));
    }

    public function closeTerm(Term $term): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::CalendarManage);

        return ApiResponse::data(AcademicResources::term($this->calendar->closeTerm($term)->load('academicYear')));
    }

    /**
     * Calendar events between dates. Filters: `filter[from]`, `filter[to]` (dates, default: current month),
     * `filter[type]`, `filter[audience]`, `filter[holidays]=1`.
     */
    public function events(Request $request): JsonResponse
    {
        $request->validate([
            'filter.from' => ['sometimes', 'date'],
            'filter.to' => ['sometimes', 'date', 'after_or_equal:filter.from'],
        ]);

        $from = Carbon::parse($request->input('filter.from', now()->startOfMonth()->toDateString()));
        $to = Carbon::parse($request->input('filter.to', now()->endOfMonth()->toDateString()));

        $events = CalendarEvent::query()
            ->with('academicYear')
            ->where('starts_on', '<=', $to)
            ->where('ends_on', '>=', $from)
            ->when($request->input('filter.type'), fn ($query, $type) => $query->where('type', $type))
            ->when($request->input('filter.audience'), fn ($query, $audience) => $query->whereIn('audience', [$audience, Audience::All->value]))
            ->when($request->boolean('filter.holidays'), fn ($query) => $query->where('is_holiday', true))
            ->orderBy('starts_on')
            ->get();

        return ApiResponse::list($events, fn (CalendarEvent $event): array => AcademicResources::event($event));
    }

    public function storeEvent(Request $request): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::CalendarManage);
        $event = CalendarEvent::query()->create($this->eventAttributes($request));

        return ApiResponse::data(AcademicResources::event($event->load('academicYear')), 201);
    }

    public function updateEvent(Request $request, CalendarEvent $calendarEvent): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::CalendarManage);
        $calendarEvent->update($this->eventAttributes($request, $calendarEvent));

        return ApiResponse::data(AcademicResources::event($calendarEvent->load('academicYear')));
    }

    public function destroyEvent(CalendarEvent $calendarEvent): JsonResponse
    {
        Gate::authorize(InstitutionPermissions::CalendarManage);
        $calendarEvent->delete();

        return ApiResponse::message('Event deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function yearRules(?AcademicYear $year = null): array
    {
        $required = $year === null ? 'required' : 'sometimes';

        return [
            /** e.g. "2026-27" */
            'name' => [$required, 'string', 'max:20', Rule::unique('academic_years', 'name')->where('institution_id', app(CurrentInstitution::class)->id())->ignore($year?->id)],
            'starts_on' => [$required, 'date'],
            'ends_on' => [$required, 'date'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function termRules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'academic_year_id' => [$required, 'string', 'size:26'],
            /** odd = semesters 1,3,5,7 · even = 2,4,6,8 · annual · summer */
            'type' => [$required, Rule::enum(TermType::class)],
            'name' => [$required, 'string', 'max:50'],
            'starts_on' => [$required, 'date'],
            'ends_on' => [$required, 'date'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventAttributes(Request $request, ?CalendarEvent $event = null): array
    {
        $required = $event === null ? 'required' : 'sometimes';

        $validated = $request->validate([
            'title' => [$required, 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'type' => [$required, Rule::enum(CalendarEventType::class)],
            'starts_on' => [$required, 'date'],
            /** Defaults to starts_on (one-day event). */
            'ends_on' => ['sometimes', 'nullable', 'date'],
            'is_holiday' => ['sometimes', 'boolean'],
            'audience' => ['sometimes', Rule::enum(Audience::class)],
        ]);

        $startsOn = Carbon::parse($validated['starts_on'] ?? $event?->starts_on);
        $endsOn = Carbon::parse($validated['ends_on'] ?? ($event->ends_on ?? $startsOn));

        if ($endsOn->lt($startsOn)) {
            throw new BusinessRuleException('The end date cannot be before the start date.', 'INVALID_DATES');
        }

        $validated['ends_on'] = $endsOn->toDateString();

        if (($validated['type'] ?? null) === CalendarEventType::Holiday->value) {
            $validated['is_holiday'] = true;
        }

        $validated['academic_year_id'] = AcademicYear::query()
            ->where('starts_on', '<=', $startsOn)
            ->where('ends_on', '>=', $startsOn)
            ->value('id');

        return $validated;
    }

    private function assertNotClosed(PeriodStatus $status): void
    {
        if ($status === PeriodStatus::Closed) {
            throw new BusinessRuleException('Closed periods cannot be changed.', 'PERIOD_CLOSED', 409);
        }
    }
}

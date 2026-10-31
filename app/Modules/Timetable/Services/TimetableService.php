<?php

namespace App\Modules\Timetable\Services;

use App\Models\User;
use App\Modules\Academics\Models\FacultyAllocation;
use App\Modules\Academics\Models\OfferingSection;
use App\Modules\HR\Models\Employee;
use App\Modules\Timetable\Models\TimeSlot;
use App\Modules\Timetable\Models\Timetable;
use App\Modules\Timetable\Models\TimetableAdjustment;
use App\Modules\Timetable\Models\TimetableEntry;
use App\Shared\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds section timetables without clashes, publishes them, and handles daily changes.
 * A clash is the same teacher or room in two places whose periods overlap in time on the same day.
 */
class TimetableService
{
    // ----- building --------------------------------------------------------

    /**
     * @param  array{day_of_week: int, time_slot_id: int, offering_section_id: int, employee_id?: int|null, room_id?: int|null, component?: string|null}  $data
     */
    public function saveEntry(Timetable $timetable, array $data, ?TimetableEntry $entry = null): TimetableEntry
    {
        if ($timetable->isPublished()) {
            throw new BusinessRuleException('Take the timetable back to draft before changing it.', 'TIMETABLE_PUBLISHED', 409);
        }

        $slot = TimeSlot::query()->where('slot_template_id', $timetable->slot_template_id)->find($data['time_slot_id']);

        if ($slot === null || $slot->is_break) {
            throw new BusinessRuleException('Choose a teaching period of this timetable.', 'SLOT_INVALID', 422);
        }

        if (! in_array($data['day_of_week'], $timetable->template->working_days, true)) {
            throw new BusinessRuleException('That day is not a working day in this timetable.', 'DAY_INVALID', 422);
        }

        $class = OfferingSection::query()->with('offering')->find($data['offering_section_id']);

        if ($class === null || $class->section_id !== $timetable->section_id || $class->offering->term_id !== $timetable->term_id) {
            throw new BusinessRuleException('Choose a course offered to this section in this term.', 'CLASS_INVALID', 422);
        }

        $component = $data['component'] ?? 'theory';
        $employeeId = array_key_exists('employee_id', $data) ? $data['employee_id'] : $this->defaultTeacher($class, $component);

        if ($employeeId !== null && ! FacultyAllocation::query()->where('offering_section_id', $class->id)->where('employee_id', $employeeId)->exists()) {
            throw new BusinessRuleException('This teacher is not allocated to the class.', 'TEACHER_NOT_ALLOCATED', 422);
        }

        $taken = $timetable->entries()->where('day_of_week', $data['day_of_week'])->where('time_slot_id', $slot->id)->when($entry, fn ($query) => $query->whereKeyNot($entry->id))->exists();

        if ($taken) {
            throw new BusinessRuleException('The section already has a class in that period.', 'SECTION_BUSY', 409);
        }

        $this->assertFree($timetable->term_id, $data['day_of_week'], $slot, $employeeId, $data['room_id'] ?? null, $entry?->id);

        $entry ??= new TimetableEntry(['timetable_id' => $timetable->id]);
        $entry->fill([
            'day_of_week' => $data['day_of_week'],
            'time_slot_id' => $slot->id,
            'offering_section_id' => $class->id,
            'employee_id' => $employeeId,
            'room_id' => $data['room_id'] ?? null,
            'component' => $component,
        ])->save();

        return $entry;
    }

    public function deleteEntry(TimetableEntry $entry): void
    {
        if ($entry->timetable->isPublished()) {
            throw new BusinessRuleException('Take the timetable back to draft before changing it.', 'TIMETABLE_PUBLISHED', 409);
        }

        $entry->delete();
    }

    /**
     * Clashes in a timetable caused by other timetables (e.g. another section booked the same teacher later).
     *
     * @return list<array{entry_id: string, day_of_week: int, slot: string, kind: string, with: string}>
     */
    public function clashes(Timetable $timetable): array
    {
        $found = [];

        foreach ($timetable->entries()->with(['slot', 'employee.user', 'room'])->get() as $entry) {
            foreach (['teacher' => $entry->employee_id, 'room' => $entry->room_id] as $kind => $id) {
                if ($id === null) {
                    continue;
                }

                $other = $this->overlapping($timetable->term_id, $entry->day_of_week, $entry->slot, $kind === 'teacher' ? 'employee_id' : 'room_id', $id, $entry->id)->with('timetable.section.batch')->first();

                if ($other !== null) {
                    $found[] = [
                        'entry_id' => $entry->ulid,
                        'day_of_week' => $entry->day_of_week,
                        'slot' => $entry->slot->label,
                        'kind' => $kind,
                        'with' => $other->timetable->section->batch->name.' '.$other->timetable->section->name,
                    ];
                }
            }
        }

        return $found;
    }

    public function publish(Timetable $timetable, User $publisher): Timetable
    {
        if (! $timetable->entries()->exists()) {
            throw new BusinessRuleException('Add classes before publishing.', 'TIMETABLE_EMPTY', 422);
        }

        $clashes = $this->clashes($timetable);

        if ($clashes !== []) {
            throw new BusinessRuleException('Fix the clashes before publishing.', 'CLASHES', 409, ['clashes' => $clashes]);
        }

        $timetable->forceFill([
            'status' => 'published',
            'version' => $timetable->published_at === null ? $timetable->version : $timetable->version + 1,
            'published_by' => $publisher->id,
            'published_at' => now(),
        ])->save();

        return $timetable;
    }

    public function unpublish(Timetable $timetable): Timetable
    {
        $timetable->forceFill(['status' => 'draft'])->save();

        return $timetable;
    }

    /**
     * Periods per week planned for each class versus hours allocated in M05.
     *
     * @return list<array{class_id: string, course: string, planned: int, allocated: int}>
     */
    public function plannedHours(Timetable $timetable): array
    {
        $classes = OfferingSection::query()
            ->with(['offering.course', 'allocations'])
            ->where('section_id', $timetable->section_id)
            ->whereHas('offering', fn ($query) => $query->where('term_id', $timetable->term_id))
            ->get();

        $planned = $timetable->entries()->selectRaw('offering_section_id, count(*) as total')->groupBy('offering_section_id')->pluck('total', 'offering_section_id');

        return $classes->map(fn (OfferingSection $class): array => [
            'class_id' => $class->ulid,
            'course' => $class->offering->course->code.' · '.$class->offering->course->title,
            'planned' => (int) ($planned[$class->id] ?? 0),
            'allocated' => (int) $class->allocations->sum('weekly_hours'),
        ])->sortBy('course')->values()->all();
    }

    // ----- daily changes ---------------------------------------------------

    /**
     * Cancel a period on one date, or have a substitute take it.
     */
    public function adjust(TimetableEntry $entry, Carbon $date, string $type, ?Employee $substitute, ?string $reason): TimetableAdjustment
    {
        $timetable = $entry->timetable;
        $this->assertOnDate($timetable, $entry->day_of_week, $date);

        if (TimetableAdjustment::query()->where('timetable_entry_id', $entry->id)->whereDate('date', $date)->exists()) {
            throw new BusinessRuleException('This period is already changed for that date.', 'ALREADY_ADJUSTED', 409);
        }

        if ($type === 'substituted') {
            if ($substitute === null) {
                throw new BusinessRuleException('Choose the substitute teacher.', 'SUBSTITUTE_REQUIRED', 422);
            }

            $this->assertTeacherFreeOn($timetable->term_id, $date, $entry->slot, $substitute->id);
        }

        return $timetable->adjustments()->create([
            'institution_id' => $timetable->institution_id,
            'date' => $date,
            'type' => $type,
            'timetable_entry_id' => $entry->id,
            'time_slot_id' => $entry->time_slot_id,
            'offering_section_id' => $entry->offering_section_id,
            'employee_id' => $substitute?->id,
            'room_id' => $entry->room_id,
            'reason' => $reason,
        ]);
    }

    /**
     * Extra class on one date in a free period of the section.
     */
    public function extraClass(Timetable $timetable, Carbon $date, TimeSlot $slot, OfferingSection $class, ?Employee $teacher, ?int $roomId, ?string $reason): TimetableAdjustment
    {
        if ($slot->slot_template_id !== $timetable->slot_template_id || $slot->is_break) {
            throw new BusinessRuleException('Choose a teaching period of this timetable.', 'SLOT_INVALID', 422);
        }

        if ($class->section_id !== $timetable->section_id) {
            throw new BusinessRuleException('Choose a course of this section.', 'CLASS_INVALID', 422);
        }

        $day = $date->dayOfWeekIso;
        $this->assertOnDate($timetable, $day, $date);

        $busy = $timetable->entries()->where('day_of_week', $day)->where('time_slot_id', $slot->id)
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('timetable_adjustments')
                ->whereColumn('timetable_adjustments.timetable_entry_id', 'timetable_entries.id')
                ->whereDate('timetable_adjustments.date', $date)->where('timetable_adjustments.type', 'cancelled'))
            ->exists()
            || $timetable->adjustments()->where('type', 'extra')->whereDate('date', $date)->where('time_slot_id', $slot->id)->exists();

        if ($busy) {
            throw new BusinessRuleException('The section already has a class in that period.', 'SECTION_BUSY', 409);
        }

        if ($teacher !== null) {
            $this->assertTeacherFreeOn($timetable->term_id, $date, $slot, $teacher->id);
        }

        return $timetable->adjustments()->create([
            'institution_id' => $timetable->institution_id,
            'date' => $date,
            'type' => 'extra',
            'time_slot_id' => $slot->id,
            'offering_section_id' => $class->id,
            'employee_id' => $teacher?->id,
            'room_id' => $roomId,
            'reason' => $reason,
        ]);
    }

    /**
     * Periods on a date for a section, teacher or room, with that day's changes applied.
     *
     * @param  'section'|'teacher'|'room'  $by
     * @return list<array<string, mixed>>
     */
    public function day(Carbon $date, string $by, int $id, ?int $termId): array
    {
        $day = $date->dayOfWeekIso;

        $adjustments = TimetableAdjustment::query()
            ->with(['slot', 'class.offering.course', 'class.section.batch', 'employee.user', 'room', 'entry'])
            ->whereDate('date', $date)
            ->get();
        $substitutedFor = $adjustments->where('type', 'substituted')->where('employee_id', $id)->pluck('timetable_entry_id')->all();

        $entries = TimetableEntry::query()
            ->with(['slot', 'class.offering.course', 'class.section.batch', 'employee.user', 'room'])
            ->join('timetables', 'timetables.id', '=', 'timetable_entries.timetable_id')
            ->where('timetables.status', 'published')
            ->when($termId, fn ($query) => $query->where('timetables.term_id', $termId))
            ->where('timetable_entries.day_of_week', $day)
            ->when($by === 'section', fn ($query) => $query->where('timetables.section_id', $id))
            ->when($by === 'room', fn ($query) => $query->where('timetable_entries.room_id', $id))
            ->when($by === 'teacher', fn ($query) => $query->where(fn ($query) => $query->where('timetable_entries.employee_id', $id)->orWhereIn('timetable_entries.id', $substitutedFor)))
            ->select('timetable_entries.*')
            ->get();
        $byEntry = $adjustments->whereNotNull('timetable_entry_id')->keyBy('timetable_entry_id');

        $rows = [];

        foreach ($entries as $entry) {
            /** @var TimetableAdjustment|null $change */
            $change = $byEntry->get($entry->id);
            $teacher = $change?->type === 'substituted' ? $change->employee : $entry->employee;
            $matches = match ($by) {
                'section' => $entry->class->section_id === $id,
                'teacher' => $teacher?->id === $id || $entry->employee_id === $id,
                'room' => $entry->room_id === $id,
            };

            if ($matches) {
                $rows[] = $this->row($entry->slot, $entry->class, $teacher, $entry->room, $change->type ?? 'scheduled', $change?->reason, $entry->ulid, $change?->ulid, $by === 'teacher' && $change?->type === 'substituted' && $entry->employee_id === $id ? 'handed_over' : null);
            }
        }

        foreach ($adjustments->where('type', 'extra') as $extra) {
            $matches = match ($by) {
                'section' => $extra->class?->section_id === $id,
                'teacher' => $extra->employee_id === $id,
                'room' => $extra->room_id === $id,
            };

            if ($matches && $extra->slot !== null && $extra->class !== null) {
                $rows[] = $this->row($extra->slot, $extra->class, $extra->employee, $extra->room, 'extra', $extra->reason, null, $extra->ulid, null);
            }
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['starts_at'], $b['starts_at']));

        return $rows;
    }

    // ----- internals -------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function row(TimeSlot $slot, OfferingSection $class, ?Employee $teacher, mixed $room, string $status, ?string $reason, ?string $entryId, ?string $adjustmentId, ?string $note): array
    {
        return [
            'entry_id' => $entryId,
            'adjustment_id' => $adjustmentId,
            'slot_id' => $slot->ulid,
            'slot' => $slot->label,
            'starts_at' => substr($slot->starts_at, 0, 5),
            'ends_at' => substr($slot->ends_at, 0, 5),
            'class_id' => $class->ulid,
            'course' => ['code' => $class->offering->course->code, 'title' => $class->offering->course->title],
            'section' => $class->section->batch->name.' '.$class->section->name,
            'teacher' => $teacher?->user->name,
            'room' => $room?->code,
            'status' => $note ?? $status,
            'reason' => $reason,
        ];
    }

    private function defaultTeacher(OfferingSection $class, string $component): ?int
    {
        $allocations = FacultyAllocation::query()->where('offering_section_id', $class->id)->get();

        return ($allocations->firstWhere('component.value', $component) ?? $allocations->first())?->employee_id;
    }

    /**
     * @return Builder<TimetableEntry>
     */
    private function overlapping(int $termId, int $day, TimeSlot $slot, string $column, int $id, ?int $exceptEntryId): Builder
    {
        return TimetableEntry::query()
            ->where('timetable_entries.'.$column, $id)
            ->where('timetable_entries.day_of_week', $day)
            ->when($exceptEntryId, fn ($query) => $query->where('timetable_entries.id', '!=', $exceptEntryId))
            ->whereHas('timetable', fn ($query) => $query->where('term_id', $termId))
            ->whereHas('slot', fn ($query) => $query->where('starts_at', '<', $slot->ends_at)->where('ends_at', '>', $slot->starts_at));
    }

    private function assertFree(int $termId, int $day, TimeSlot $slot, ?int $employeeId, ?int $roomId, ?int $exceptEntryId): void
    {
        foreach (['employee_id' => [$employeeId, 'The teacher', 'TEACHER_BUSY'], 'room_id' => [$roomId, 'The room', 'ROOM_BUSY']] as $column => [$id, $who, $code]) {
            if ($id === null) {
                continue;
            }

            $other = $this->overlapping($termId, $day, $slot, $column, $id, $exceptEntryId)->with('timetable.section.batch')->first();

            if ($other !== null) {
                throw new BusinessRuleException("{$who} is already booked then for ".$other->timetable->section->batch->name.' '.$other->timetable->section->name.'.', $code, 409);
            }
        }
    }

    private function assertTeacherFreeOn(int $termId, Carbon $date, TimeSlot $slot, int $employeeId): void
    {
        $regular = $this->overlapping($termId, $date->dayOfWeekIso, $slot, 'employee_id', $employeeId, null)
            ->whereHas('timetable', fn ($query) => $query->where('status', 'published'))
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('timetable_adjustments')
                ->whereColumn('timetable_adjustments.timetable_entry_id', 'timetable_entries.id')
                ->whereDate('timetable_adjustments.date', $date)->whereIn('timetable_adjustments.type', ['cancelled', 'substituted']))
            ->exists();

        $changes = TimetableAdjustment::query()->whereDate('date', $date)->where('employee_id', $employeeId)->whereIn('type', ['substituted', 'extra'])
            ->whereHas('slot', fn ($query) => $query->where('starts_at', '<', $slot->ends_at)->where('ends_at', '>', $slot->starts_at))->exists();

        if ($regular || $changes) {
            throw new BusinessRuleException('That teacher has another class then.', 'TEACHER_BUSY', 409);
        }
    }

    private function assertOnDate(Timetable $timetable, int $day, Carbon $date): void
    {
        if (! $timetable->isPublished()) {
            throw new BusinessRuleException('Only a published timetable can be changed day by day.', 'TIMETABLE_NOT_PUBLISHED', 409);
        }

        $term = $timetable->term;

        if ($date->dayOfWeekIso !== $day || $date->lt($term->starts_on) || $date->gt($term->ends_on)) {
            throw new BusinessRuleException('That date does not have this period.', 'DATE_INVALID', 422);
        }
    }

    /**
     * @param  Collection<int, TimetableEntry>  $entries
     * @return list<array<string, mixed>>
     */
    public static function grid(Collection $entries): array
    {
        return $entries->map(fn (TimetableEntry $entry): array => [
            'id' => $entry->ulid,
            'day_of_week' => $entry->day_of_week,
            'slot_id' => $entry->slot->ulid,
            'class_id' => $entry->class->ulid,
            'course' => ['code' => $entry->class->offering->course->code, 'title' => $entry->class->offering->course->title],
            'section' => $entry->class->section->batch->name.' '.$entry->class->section->name,
            'component' => $entry->component,
            'teacher' => $entry->employee === null ? null : ['id' => $entry->employee->ulid, 'name' => $entry->employee->user->name],
            'room' => $entry->room === null ? null : ['id' => $entry->room->ulid, 'code' => $entry->room->code],
        ])->values()->all();
    }
}

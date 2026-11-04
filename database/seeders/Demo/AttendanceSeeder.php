<?php

namespace Database\Seeders\Demo;

use App\Modules\Academics\Models\OfferingSection;
use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Models\AttendanceSession;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Students\Models\Student;
use App\Modules\Timetable\Models\TimetableEntry;
use App\Shared\Exceptions\BusinessRuleException;
use Database\Seeders\Support\CampusSeeder;
use Illuminate\Support\Carbon;

/**
 * Five weeks of attendance for the 2025 batches (3rd semester), marked by each class's teacher from
 * the published timetable. Most students attend well; some fall in the condonation band, a few short.
 * One condonation is granted and one correction request waits for review.
 */
class AttendanceSeeder extends CampusSeeder
{
    public const WEEKS = 5;

    protected function seed(): void
    {
        if (AttendanceSession::query()->exists()) {
            return;
        }

        $attendance = app(AttendanceService::class);
        $entries = TimetableEntry::query()->with(['class.offering.term', 'employee.user'])
            ->whereHas('timetable.section.batch', fn ($q) => $q->where('admission_year', 2025))->get()->groupBy('day_of_week');
        $rosters = [];

        for ($date = now()->subWeeks(self::WEEKS)->startOfWeek(); $date->lt(now()->startOfDay()); $date->addDay()) {
            foreach ($entries->get($date->dayOfWeekIso, collect()) as $entry) {
                /** @var TimetableEntry $entry */
                $teacher = $entry->employee?->user;

                if ($teacher === null || $date->lt($entry->class->offering->term->starts_on)) {
                    continue;
                }

                $roster = $rosters[$entry->offering_section_id] ??= $attendance->roster($entry->class)->pluck('id')->all();
                $statuses = [];

                foreach ($roster as $studentId) {
                    $status = $this->status($studentId, $date);

                    if ($status !== null) {
                        $statuses[$studentId] = $status;
                    }
                }

                try {
                    $this->as($teacher, fn () => $attendance->mark($entry->class, $date->copy(), $entry->slot, $statuses, null, $this->fresh($teacher)));
                } catch (BusinessRuleException) {
                    // e.g. a teacher who has since left
                }
            }
        }

        $this->extras($attendance);
    }

    /**
     * Absent rate by student: every 10th student is short (~40%), every 10th + 1 condonable (~28%), others ~5%.
     */
    private function status(int $studentId, Carbon $date): ?string
    {
        $roll = abs(crc32($studentId.'|'.$date->toDateString())) % 100;
        $rate = match ($studentId % 10) {
            0 => 40,
            1 => 28,
            default => 5,
        };

        if ($roll < $rate) {
            return $roll % 9 === 0 ? AttendanceStatus::Medical->value : AttendanceStatus::Absent->value;
        }

        return $roll >= 97 ? AttendanceStatus::Late->value : null;
    }

    private function extras(AttendanceService $attendance): void
    {
        $class = OfferingSection::query()->whereIn('id', AttendanceSession::query()->select('offering_section_id'))->orderBy('id')->first();

        if ($class === null) {
            return;
        }

        $hod = $this->user('hod.'.strtolower($class->offering->course->department->code).'@'.self::DOMAIN);

        foreach ($attendance->classSummary($class) as $row) {
            if (($row['standing'] ?? null) === 'condonable') {
                $student = Student::query()->where('ulid', $row['student_id'])->firstOrFail();
                $this->as($hod, fn () => $attendance->condone($student, $class, 'Hospitalised for dengue; medical certificate verified.', 'CND-2026-014', $this->fresh($hod)));
                break;
            }
        }

        $record = AttendanceRecord::query()->where('status', 'absent')->whereHas('session', fn ($q) => $q->where('offering_section_id', $class->id))->orderByDesc('id')->first();

        if ($record !== null) {
            $student = $record->student->user;
            $this->as($student, fn () => $attendance->requestCorrection($record, AttendanceStatus::OnDuty, 'Represented the college at the inter-college hackathon (letter from the HOD attached).', $this->fresh($student)));
        }
    }
}

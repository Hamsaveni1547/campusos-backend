<?php

namespace App\Modules\Institution\Http\Resources;

use App\Modules\Institution\Models\AcademicYear;
use App\Modules\Institution\Models\Batch;
use App\Modules\Institution\Models\CalendarEvent;
use App\Modules\Institution\Models\Department;
use App\Modules\Institution\Models\Programme;
use App\Modules\Institution\Models\Room;
use App\Modules\Institution\Models\Section;
use App\Modules\Institution\Models\Term;

/**
 * Compact references used inside other resources (`{ id, code, name }`).
 */
final class AcademicResources
{
    /**
     * @return array{id: string, code: string, name: string}|null
     */
    public static function department(?Department $department): ?array
    {
        return $department === null ? null : ['id' => $department->ulid, 'code' => $department->code, 'name' => $department->name];
    }

    /**
     * @return array{id: string, code: string, name: string}|null
     */
    public static function programme(?Programme $programme): ?array
    {
        return $programme === null ? null : ['id' => $programme->ulid, 'code' => $programme->code, 'name' => $programme->name];
    }

    /**
     * @return array{id: string, name: string}|null
     */
    public static function academicYear(?AcademicYear $year): ?array
    {
        return $year === null ? null : ['id' => $year->ulid, 'name' => $year->name];
    }

    /**
     * @return array<string, mixed>
     */
    public static function year(AcademicYear $year): array
    {
        return [
            'id' => $year->ulid,
            'name' => $year->name,
            'starts_on' => $year->starts_on->toDateString(),
            'ends_on' => $year->ends_on->toDateString(),
            'is_current' => $year->is_current,
            'status' => $year->status->value,
            'terms' => $year->relationLoaded('terms') ? $year->terms->map(fn (Term $term): array => self::term($term, false))->values()->all() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function term(Term $term, bool $withYear = true): array
    {
        $data = [
            'id' => $term->ulid,
            'name' => $term->name,
            'type' => $term->type->value,
            'academic_year' => self::academicYear($term->academicYear),
            'starts_on' => $term->starts_on->toDateString(),
            'ends_on' => $term->ends_on->toDateString(),
            'is_current' => $term->is_current,
            'status' => $term->status->value,
        ];

        // Terms listed inside their year leave out the (repeated) year.
        if (! $withYear) {
            unset($data['academic_year']);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function batch(Batch $batch): array
    {
        return [
            'id' => $batch->ulid,
            'name' => $batch->name,
            'admission_year' => $batch->admission_year,
            'graduation_year' => $batch->graduationYear(),
            'status' => $batch->status->value,
            'programme' => self::programme($batch->programme),
            'sections' => $batch->relationLoaded('sections') ? $batch->sections->map(fn (Section $section): array => self::section($section))->values()->all() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function section(Section $section): array
    {
        return [
            'id' => $section->ulid,
            'name' => $section->name,
            'capacity' => $section->capacity,
            'class_advisor' => $section->classAdvisor === null ? null : ['id' => $section->classAdvisor->ulid, 'name' => $section->classAdvisor->name],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function room(Room $room): array
    {
        return [
            'id' => $room->ulid,
            'code' => $room->code,
            'name' => $room->name,
            'building' => $room->building,
            'floor' => $room->floor,
            'type' => $room->type->value,
            'capacity' => $room->capacity,
            'status' => $room->status->value,
            'department' => self::department($room->department),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function event(CalendarEvent $event): array
    {
        return [
            'id' => $event->ulid,
            'title' => $event->title,
            'description' => $event->description,
            'type' => $event->type->value,
            'starts_on' => $event->starts_on->toDateString(),
            'ends_on' => $event->ends_on->toDateString(),
            'is_holiday' => $event->is_holiday,
            'audience' => $event->audience->value,
            'academic_year' => self::academicYear($event->academicYear),
        ];
    }
}

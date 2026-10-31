<?php

namespace App\Modules\Academics\Http;

use App\Modules\Academics\Models\Course;
use App\Modules\Academics\Models\CourseMaterial;
use App\Modules\Academics\Models\CourseOffering;
use App\Modules\Academics\Models\Curriculum;
use App\Modules\Academics\Models\CurriculumCourse;
use App\Modules\Academics\Models\FacultyAllocation;
use App\Modules\Academics\Models\OfferingSection;
use App\Modules\Academics\Models\SyllabusTopic;
use App\Modules\Academics\Models\SyllabusUnit;
use App\Modules\Institution\Http\Resources\AcademicResources;

/**
 * JSON shapes for curriculum and teaching.
 */
final class AcademicsPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function course(Course $course, bool $withSyllabus = false): array
    {
        $data = [
            'id' => $course->ulid,
            'code' => $course->code,
            'title' => $course->title,
            'type' => $course->type->value,
            'type_label' => $course->type->label(),
            'credits' => (float) $course->credits,
            'lecture_hours' => $course->lecture_hours,
            'tutorial_hours' => $course->tutorial_hours,
            'practical_hours' => $course->practical_hours,
            'ltp' => $course->ltp(),
            'regulation' => $course->regulation,
            'status' => $course->status->value,
            'department' => AcademicResources::department($course->department),
        ];

        if ($withSyllabus) {
            $data += [
                'description' => $course->description,
                'textbooks' => $course->textbooks ?? [],
                'units' => $course->units->map(fn (SyllabusUnit $unit): array => [
                    'id' => $unit->ulid,
                    'number' => $unit->number,
                    'title' => $unit->title,
                    'hours' => $unit->hours,
                    'topics' => $unit->topics->map(fn (SyllabusTopic $topic): array => ['id' => $topic->ulid, 'title' => $topic->title, 'hours' => (float) $topic->hours])->values()->all(),
                ])->values()->all(),
            ];
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function curriculum(Curriculum $curriculum, bool $withEntries = false): array
    {
        $data = [
            'id' => $curriculum->ulid,
            'programme' => AcademicResources::programme($curriculum->programme),
            'total_terms' => $curriculum->programme->total_terms,
            'regulation' => $curriculum->regulation,
            'version' => $curriculum->version,
            'status' => $curriculum->status->value,
            'remarks' => $curriculum->remarks,
            'approved_by' => $curriculum->approver?->name,
            'approved_at' => $curriculum->approved_at?->toIso8601String(),
            'courses_count' => $curriculum->entries_count ?? null,
        ];

        if ($withEntries) {
            $data['entries'] = $curriculum->entries->map(fn (CurriculumCourse $entry): array => [
                'term_number' => $entry->term_number,
                'category' => $entry->category,
                'is_elective' => $entry->is_elective,
                'course' => self::course($entry->course),
            ])->values()->all();
            $data['credits_by_term'] = $curriculum->entries->groupBy('term_number')->map(fn ($entries): float => (float) $entries->sum(fn (CurriculumCourse $entry): float => (float) $entry->course->credits))->all();
        }

        return $data;
    }

    /**
     * @param  array<int, int>  $coverage  offering_section id => percent
     * @return array<string, mixed>
     */
    public static function offering(CourseOffering $offering, array $coverage = []): array
    {
        return [
            'id' => $offering->ulid,
            'course' => self::course($offering->course),
            'term' => ['id' => $offering->term->ulid, 'name' => $offering->term->name, 'is_current' => $offering->term->is_current],
            'is_elective' => $offering->is_elective,
            'capacity' => $offering->capacity,
            'status' => $offering->status->value,
            'sections' => $offering->sections->map(fn (OfferingSection $section): array => self::offeringSection($section, $coverage[$section->id] ?? null))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function offeringSection(OfferingSection $offeringSection, ?int $coverage = null): array
    {
        return [
            'id' => $offeringSection->ulid,
            'section' => ['id' => $offeringSection->section->ulid, 'name' => $offeringSection->section->name, 'batch' => $offeringSection->section->batch->name],
            'faculty' => $offeringSection->allocations->map(fn (FacultyAllocation $allocation): array => self::allocation($allocation))->values()->all(),
            'coverage_percent' => $coverage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function allocation(FacultyAllocation $allocation): array
    {
        return [
            'id' => $allocation->ulid,
            'employee_id' => $allocation->employee->ulid,
            'name' => $allocation->employee->user->name,
            'component' => $allocation->component->value,
            'weekly_hours' => $allocation->weekly_hours,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function material(CourseMaterial $material): array
    {
        return [
            'id' => $material->ulid,
            'title' => $material->title,
            'unit' => $material->unit === null ? null : ['id' => $material->unit->ulid, 'number' => $material->unit->number, 'title' => $material->unit->title],
            'file_id' => $material->file?->ulid,
            'file_name' => $material->file?->original_name,
            'url' => $material->url,
            'is_published' => $material->is_published,
            'created_at' => $material->created_at?->toIso8601String(),
        ];
    }
}

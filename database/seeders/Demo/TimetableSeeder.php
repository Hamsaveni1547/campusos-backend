<?php

namespace Database\Seeders\Demo;

use App\Modules\Academics\Models\OfferingSection;
use App\Modules\Institution\Models\Term;
use App\Modules\Timetable\Models\SlotTemplate;
use App\Modules\Timetable\Models\TimeSlot;
use App\Modules\Timetable\Models\Timetable;
use App\Modules\Timetable\Services\TimetableService;
use App\Shared\Exceptions\BusinessRuleException;
use Database\Seeders\Support\CampusSeeder;

/**
 * A Monday–Saturday day of six periods, and a published Odd 2026 timetable for every section:
 * three periods a week per class, placed where both the section and the teacher are free.
 */
class TimetableSeeder extends CampusSeeder
{
    public const PERIODS_PER_CLASS = 3;

    protected function seed(): void
    {
        if (SlotTemplate::query()->exists()) {
            return;
        }

        $template = SlotTemplate::query()->create(['name' => 'Regular day', 'working_days' => [1, 2, 3, 4, 5, 6], 'is_default' => true]);

        foreach ([
            ['P1', '09:00', '09:55', false], ['P2', '09:55', '10:50', false], ['Tea break', '10:50', '11:05', true], ['P3', '11:05', '12:00', false],
            ['P4', '12:00', '12:55', false], ['Lunch', '12:55', '13:45', true], ['P5', '13:45', '14:40', false], ['P6', '14:40', '15:35', false],
        ] as $position => [$label, $start, $end, $break]) {
            TimeSlot::query()->create(['slot_template_id' => $template->id, 'position' => $position + 1, 'label' => $label, 'starts_at' => $start, 'ends_at' => $end, 'is_break' => $break]);
        }

        $term = Term::query()->where('name', TeachingSeeder::CURRENT_TERM)->firstOrFail();
        $slots = TimeSlot::query()->where('slot_template_id', $template->id)->where('is_break', false)->orderBy('position')->pluck('id')->all();
        $service = app(TimetableService::class);
        $admin = $this->admin();

        $this->as($admin, function () use ($term, $template, $slots, $service, $admin): void {
            $classes = OfferingSection::query()->whereHas('offering', fn ($q) => $q->where('term_id', $term->id))->orderBy('section_id')->orderBy('id')->get()->groupBy('section_id');

            foreach ($classes as $sectionId => $sectionClasses) {
                $timetable = Timetable::query()->create(['term_id' => $term->id, 'section_id' => $sectionId, 'slot_template_id' => $template->id]);
                $cells = [];

                foreach ($template->working_days as $day) {
                    foreach ($slots as $slot) {
                        $cells[] = [$day, $slot];
                    }
                }

                foreach ($sectionClasses->values() as $index => $class) {
                    $placed = 0;
                    $rotated = [...array_slice($cells, ($index * 7 + $sectionId) % count($cells)), ...array_slice($cells, 0, ($index * 7 + $sectionId) % count($cells))];

                    foreach ($rotated as [$day, $slot]) {
                        if ($placed >= self::PERIODS_PER_CLASS) {
                            break;
                        }

                        try {
                            $service->saveEntry($timetable, ['day_of_week' => $day, 'time_slot_id' => $slot, 'offering_section_id' => $class->id]);
                            $placed++;
                            $cells = array_values(array_filter($cells, fn (array $c): bool => $c !== [$day, $slot]));
                        } catch (BusinessRuleException) {
                            // section or teacher busy then; try the next period
                        }
                    }
                }

                $service->publish($timetable, $this->fresh($admin));
            }
        });
    }
}

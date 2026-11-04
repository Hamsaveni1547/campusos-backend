<?php

namespace Database\Seeders\Demo;

use App\Modules\Feedback\Models\Complaint;
use App\Modules\Feedback\Models\ComplaintCategory;
use App\Modules\Feedback\Models\Survey;
use App\Modules\Feedback\Services\FeedbackService;
use App\Modules\Institution\Models\Term;
use App\Modules\Students\Models\Student;
use App\Shared\Exceptions\BusinessRuleException;
use Database\Seeders\Support\CampusSeeder;

/**
 * An open mid-semester course feedback survey answered by most 2025-batch students (so results show),
 * a draft staff survey, complaint categories, and complaints in every state.
 */
class FeedbackSeeder extends CampusSeeder
{
    private const COMMENTS = ['More examples in class please', 'Very clear explanations', 'Pace is a little fast', 'Please share notes before class', 'Lab sessions are very useful', 'Good use of real-world cases'];

    protected function seed(): void
    {
        if (Survey::query()->exists()) {
            return;
        }

        $feedback = app(FeedbackService::class);
        $principal = $this->user('principal@'.self::DOMAIN);
        $term = Term::query()->where('name', TeachingSeeder::CURRENT_TERM)->firstOrFail();

        $survey = $this->as($principal, function () use ($feedback, $term): Survey {
            $survey = $feedback->saveSurvey(['title' => 'Mid-semester course feedback', 'description' => 'Tell us how your courses are going. Answers are anonymous.', 'kind' => 'course', 'term_id' => $term->id, 'audience' => 'students', 'opens_on' => now()->subDays(5)->toDateString(), 'closes_on' => now()->addDays(10)->toDateString()], [
                ['text' => 'The teacher explains concepts clearly', 'type' => 'rating'],
                ['text' => 'The teacher is well prepared for class', 'type' => 'rating'],
                ['text' => 'Pace of the course', 'type' => 'choice', 'options' => ['Too slow', 'Just right', 'Too fast']],
                ['text' => 'Any suggestions?', 'type' => 'text', 'required' => false],
            ]);
            $feedback->setStatus($survey, 'open');
            $feedback->saveSurvey(['title' => 'Staff satisfaction survey 2026', 'kind' => 'general', 'audience' => 'staff', 'opens_on' => now()->addWeek()->toDateString(), 'closes_on' => now()->addWeeks(3)->toDateString()], [
                ['text' => 'I have the resources I need to do my job', 'type' => 'rating'],
                ['text' => 'What one thing would improve your work?', 'type' => 'text', 'required' => false],
            ]);

            return $survey->load('questions');
        });

        $answered = 0;
        foreach (Student::query()->with('user')->whereHas('batch', fn ($q) => $q->where('admission_year', 2025))->where('status', 'active')->orderBy('id')->get() as $i => $student) {
            if ($i % 6 === 5) {
                continue; // some have not answered yet
            }

            foreach ($feedback->pending($student->user) as $pending) {
                if ($pending['survey']->id !== $survey->id) {
                    continue;
                }

                $seed = abs(crc32($student->id.'-'.$pending['class']?->id));
                [$q1, $q2, $q3, $q4] = $survey->questions->all();

                try {
                    $feedback->submit($survey, $student->user, $pending['class'], [
                        $q1->ulid => 3 + $seed % 3, $q2->ulid => 2 + $seed % 4,
                        $q3->ulid => ['Too slow', 'Just right', 'Just right', 'Too fast'][$seed % 4],
                        $q4->ulid => $seed % 3 === 0 ? self::COMMENTS[$seed % count(self::COMMENTS)] : null,
                    ]);
                    $answered++;
                } catch (BusinessRuleException) {
                    // already answered
                }
            }
        }

        $categories = $this->as($principal, fn () => collect([
            ['Academic', 72, false], ['Examination', 72, false], ['Infrastructure', 48, false], ['Fees', 72, false],
            ['Harassment (ICC)', 24, true], ['Anti-ragging', 24, true],
        ])->mapWithKeys(fn (array $c): array => [$c[0] => ComplaintCategory::query()->create(['name' => $c[0], 'sla_hours' => $c[1], 'is_restricted' => $c[2]])]));

        $office = $this->user('office@'.self::DOMAIN);
        $students = Student::query()->with('user')->where('status', 'active')->orderBy('id')->limit(6)->get()->values();

        foreach ([
            [0, 'Infrastructure', 'Drinking water cooler not working on the 2nd floor', 'The cooler near LH-201 has been off for three days.', false, 'resolved_closed'],
            [1, 'Academic', 'Lab manual not available for DS lab', 'We have not received the lab manual for Data Structures lab.', false, 'in_progress'],
            [2, 'Infrastructure', 'Wi-Fi drops in the library', 'Wi-Fi disconnects every few minutes on the first floor of the library.', true, 'open'],
            [3, 'Examination', 'IA2 marks not updated', 'My IA2 marks for 22CS32 are not shown yet.', false, 'resolved'],
            [4, 'Harassment (ICC)', 'Request a confidential meeting', 'I would like to speak to the committee confidentially.', true, 'open'],
        ] as [$i, $category, $subject, $description, $anonymous, $outcome]) {
            $raiser = $students[$i]->user;
            $complaint = $this->as($raiser, fn (): Complaint => $feedback->raise($categories[$category], $subject, $description, $anonymous));

            if (in_array($outcome, ['in_progress', 'resolved', 'resolved_closed'], true)) {
                $this->as($office, function () use ($feedback, $complaint, $office, $outcome): void {
                    $feedback->assign($complaint, $this->fresh($office));
                    $feedback->respond($complaint->refresh(), $this->fresh($office), 'Forwarded to the maintenance team.', true, false);

                    if ($outcome !== 'in_progress') {
                        $feedback->respond($complaint->refresh(), $this->fresh($office), 'This has been fixed. Please let us know if it happens again.', false, true);
                    }
                });
            }

            if ($outcome === 'resolved_closed') {
                $feedback->close($complaint->refresh(), 5);
            }
        }

        // One overdue complaint for the dashboard.
        Complaint::query()->where('status', 'open')->whereHas('category', fn ($q) => $q->where('is_restricted', false))->first()?->forceFill(['due_at' => now()->subHours(6), 'created_at' => now()->subDays(3)])->save();

        $this->command?->info("Feedback seeded: {$answered} anonymous course responses.");
    }
}

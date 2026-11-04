<?php

namespace Database\Seeders\Demo;

use App\Modules\Communication\Models\Announcement;
use App\Modules\Communication\Services\AnnouncementService;
use App\Modules\Institution\Models\Department;
use App\Modules\Institution\Models\Section;
use App\Modules\Students\Models\Student;
use Database\Seeders\Support\CampusSeeder;

/**
 * Announcements from the principal, a HOD and a teacher (one asking for acknowledgement, one pinned),
 * a withdrawn one and a draft. Recipients are stored; notifications are not sent while seeding.
 */
class AnnouncementSeeder extends CampusSeeder
{
    protected function seed(): void
    {
        if (Announcement::query()->exists()) {
            return;
        }

        $service = app(AnnouncementService::class);
        $post = function (string $email, array $data, string $state = 'published') use ($service): void {
            $this->as($this->user($email.'@'.self::DOMAIN), function () use ($service, $data, $state): void {
                $announcement = Announcement::query()->create([...['category' => 'general', 'priority' => 'normal', 'is_pinned' => false, 'requires_ack' => false], ...$data]);
                $announcement->refresh();

                if ($state !== 'draft') {
                    $service->publish($announcement, notify: false);
                }
                if ($state === 'withdrawn') {
                    $service->withdraw($announcement);
                }
            });
        };

        $post('principal', ['title' => 'Code of conduct for the semester', 'body' => "All students and staff must carry their ID cards on campus.\n\nMobile phones must be on silent in classrooms and labs. Please read the attached rules on the notice board and acknowledge below.", 'audience_type' => 'everyone', 'is_pinned' => true, 'requires_ack' => true, 'priority' => 'important']);
        $post('principal', ['title' => 'Kannada Rajyotsava celebrations on 1 November', 'body' => 'Cultural programme in the main auditorium at 10 am. Classes resume at 1 pm.', 'audience_type' => 'everyone', 'category' => 'general']);
        $post('office', ['title' => 'Fee payment counter timings', 'body' => 'The accounts counter is open 9:30 am to 3:30 pm on working days and closed on second Saturdays.', 'audience_type' => 'students', 'category' => 'finance']);
        $post('hr', ['title' => 'Submit investment proofs by 30 November', 'body' => 'Teaching and non-teaching staff: upload your tax-saving investment proofs to the HR office.', 'audience_type' => 'staff', 'category' => 'hr']);

        $cse = Department::query()->where('code', 'CSE')->value('id');
        if ($cse) {
            $post('hod.cse', ['title' => 'Department seminar on generative AI', 'body' => 'Guest lecture by an industry expert in the CSE seminar hall on Friday at 2 pm. Attendance is compulsory for final-year students.', 'audience_type' => 'departments', 'audience_ids' => [$cse], 'category' => 'academic']);
            $post('hod.cse', ['title' => 'Lab timings changed', 'body' => 'Old timings — replaced by the new timetable.', 'audience_type' => 'departments', 'audience_ids' => [$cse], 'category' => 'academic'], 'withdrawn');
        }

        $section = Student::query()->whereHas('user', fn ($q) => $q->where('email', 'student@'.self::DOMAIN))->value('section_id') ?? Section::query()->value('id');
        if ($section) {
            $post('kavya.shetty', ['title' => 'Internal assessment 2 syllabus', 'body' => "IA-2 covers units 3 and 4.\n\nBring your calculators. The question paper has two parts: short answers and one long problem.", 'audience_type' => 'sections', 'audience_ids' => [$section], 'category' => 'academic', 'requires_ack' => true]);
            $post('kavya.shetty', ['title' => 'Project review schedule', 'body' => 'Draft: review slots for mini-projects will be shared here.', 'audience_type' => 'sections', 'audience_ids' => [$section], 'category' => 'academic'], 'draft');
        }
    }
}

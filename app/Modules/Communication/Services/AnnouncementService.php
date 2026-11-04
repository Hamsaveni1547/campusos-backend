<?php

namespace App\Modules\Communication\Services;

use App\Models\User;
use App\Modules\Academics\Models\FacultyAllocation;
use App\Modules\Communication\Authorization\CommunicationPermissions;
use App\Modules\Communication\Models\Announcement;
use App\Modules\Communication\Notifications\AnnouncementNotification;
use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Institution\Models\Batch;
use App\Modules\Institution\Models\Programme;
use App\Modules\Institution\Models\Section;
use App\Modules\Students\Models\Student;
use App\Shared\Authorization\DepartmentScope;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Resolves announcement audiences to people, publishes and withdraws announcements.
 */
class AnnouncementService
{
    private const ACTIVE_STUDENT = ['active', 'on_leave', 'detained'];

    /**
     * Ids of the people an audience reaches.
     *
     * @param  list<int|string>  $ids  role names for "roles", record ids otherwise
     * @return Collection<int, int>
     */
    public function audienceUserIds(string $type, array $ids): Collection
    {
        $students = fn () => Student::query()->whereIn('status', self::ACTIVE_STUDENT)->whereNotNull('user_id');
        $staff = fn () => Employee::query()->whereIn('status', ['active', 'on_leave'])->whereNotNull('user_id');

        $userIds = match ($type) {
            'everyone' => $students()->pluck('user_id')->merge($staff()->pluck('user_id')),
            'students' => $students()->pluck('user_id'),
            'staff' => $staff()->pluck('user_id'),
            'roles' => RoleAssignment::query()->where('institution_id', app(CurrentInstitution::class)->id())->effective()
                ->whereHas('role', fn ($q) => $q->whereIn('name', $ids))->pluck('user_id'),
            'departments' => $students()->whereHas('programme', fn ($q) => $q->whereIn('department_id', $ids))->pluck('user_id')
                ->merge($staff()->whereIn('department_id', $ids)->pluck('user_id')),
            'programmes' => $students()->whereIn('programme_id', $ids)->pluck('user_id'),
            'batches' => $students()->whereIn('batch_id', $ids)->pluck('user_id'),
            'sections' => $students()->whereIn('section_id', $ids)->pluck('user_id'),
            default => collect(),
        };

        return $userIds->map(fn ($id): int => (int) $id)->unique()->values();
    }

    /**
     * What a publisher may target. Everyone-publishers have no limits; a HOD is limited to their departments
     * (plus sections they teach); a teacher without a department role to the sections they teach.
     *
     * @return array{types: list<string>, departments: list<int>|null, sections: list<int>|null}
     */
    public function limitsFor(User $user): array
    {
        if ($user->can(CommunicationPermissions::AnnouncementsPublishAll)) {
            return ['types' => Announcement::AUDIENCES, 'departments' => null, 'sections' => null];
        }

        $departments = DepartmentScope::for($user, CommunicationPermissions::AnnouncementsPublish);
        $taught = FacultyAllocation::query()->whereHas('employee', fn ($q) => $q->where('user_id', $user->id))
            ->join('offering_sections', 'offering_sections.id', '=', 'faculty_allocations.offering_section_id')
            ->distinct()->pluck('offering_sections.section_id')->map(fn ($id): int => (int) $id);

        if ($departments !== null) {
            $inDepartments = Section::query()->whereHas('batch.programme', fn ($q) => $q->whereIn('department_id', $departments))->pluck('id')->map(fn ($id): int => (int) $id);

            return ['types' => Announcement::LIMITED_AUDIENCES, 'departments' => $departments, 'sections' => $inDepartments->merge($taught)->unique()->values()->all()];
        }

        if ($taught->isNotEmpty()) {
            return ['types' => ['sections'], 'departments' => [], 'sections' => $taught->unique()->values()->all()];
        }

        return ['types' => Announcement::LIMITED_AUDIENCES, 'departments' => null, 'sections' => null];
    }

    /**
     * Fails when the chosen audience is outside what the publisher may target.
     *
     * @param  list<int|string>  $ids
     */
    public function assertWithinLimits(User $user, string $type, array $ids): void
    {
        $limits = $this->limitsFor($user);
        $departments = $limits['departments'];

        $allowed = match ($type) {
            'departments' => $departments === null || array_diff($ids, $departments) === [],
            'programmes' => $departments === null || Programme::query()->whereIn('id', $ids)->whereNotIn('department_id', $departments)->doesntExist(),
            'batches' => $departments === null || Batch::query()->whereIn('id', $ids)->whereHas('programme', fn ($q) => $q->whereNotIn('department_id', $departments))->doesntExist(),
            'sections' => $limits['sections'] === null || array_diff($ids, $limits['sections']) === [],
            default => in_array($type, $limits['types'], true),
        };

        if (! $allowed || ! in_array($type, $limits['types'], true)) {
            throw new BusinessRuleException('You can only post to your own department or the sections you teach.', 'OUT_OF_SCOPE', 403);
        }
    }

    public function publish(Announcement $announcement, bool $notify = true): void
    {
        if (! in_array($announcement->status, ['draft', 'scheduled'], true)) {
            throw new BusinessRuleException('Only a draft can be published.', 'NOT_DRAFT', 422);
        }

        $userIds = $this->audienceUserIds($announcement->audience_type, $announcement->audience_ids ?? []);

        if ($userIds->isEmpty()) {
            throw new BusinessRuleException('Nobody is in this audience yet.', 'EMPTY_AUDIENCE', 422);
        }

        DB::transaction(function () use ($announcement, $userIds): void {
            foreach ($userIds->chunk(500) as $chunk) {
                DB::table('announcement_recipients')->insertOrIgnore($chunk->map(fn (int $id): array => ['announcement_id' => $announcement->id, 'user_id' => $id])->all());
            }
            $announcement->forceFill(['status' => 'published', 'published_at' => now(), 'recipients_count' => $userIds->count()])->save();
        });

        if ($notify) {
            $announcement->loadMissing('author');
            foreach ($userIds->chunk(500) as $chunk) {
                Notification::send(User::query()->whereIn('id', $chunk)->get(), AnnouncementNotification::fromAnnouncement($announcement));
            }
        }
    }

    /**
     * Sends now, or schedules it when its publish time is in the future. Checks the audience is not empty first.
     */
    public function publishOrSchedule(Announcement $announcement): string
    {
        if ($announcement->publish_at?->isFuture()) {
            if ($announcement->status !== 'draft') {
                throw new BusinessRuleException('Only a draft can be scheduled.', 'NOT_DRAFT', 422);
            }
            if ($this->audienceUserIds($announcement->audience_type, $announcement->audience_ids ?? [])->isEmpty()) {
                throw new BusinessRuleException('Nobody is in this audience yet.', 'EMPTY_AUDIENCE', 422);
            }
            $announcement->forceFill(['status' => 'scheduled'])->save();

            return 'Scheduled for '.$announcement->publish_at->format('d M Y, h:i A').'.';
        }

        $this->publish($announcement);

        return "Sent to {$announcement->recipients_count} people.";
    }

    public function withdraw(Announcement $announcement): void
    {
        if ($announcement->status === 'scheduled') {
            $announcement->forceFill(['status' => 'draft'])->save();

            return;
        }

        if ($announcement->status !== 'published') {
            throw new BusinessRuleException('Only a published announcement can be withdrawn.', 'NOT_PUBLISHED', 422);
        }

        $announcement->forceFill(['status' => 'withdrawn'])->save();
    }
}

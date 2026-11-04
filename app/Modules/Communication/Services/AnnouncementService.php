<?php

namespace App\Modules\Communication\Services;

use App\Models\User;
use App\Modules\Communication\Models\Announcement;
use App\Modules\Communication\Notifications\AnnouncementNotification;
use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Students\Models\Student;
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

    public function publish(Announcement $announcement, bool $notify = true): void
    {
        if ($announcement->status !== 'draft') {
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

    public function withdraw(Announcement $announcement): void
    {
        if ($announcement->status !== 'published') {
            throw new BusinessRuleException('Only a published announcement can be withdrawn.', 'NOT_PUBLISHED', 422);
        }

        $announcement->forceFill(['status' => 'withdrawn'])->save();
    }
}

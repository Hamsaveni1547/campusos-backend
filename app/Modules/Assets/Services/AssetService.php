<?php

namespace App\Modules\Assets\Services;

use App\Modules\Assets\Models\MaintenanceRequest;
use App\Modules\Assets\Models\RoomBooking;
use App\Modules\Institution\Models\Room;
use App\Modules\Institution\Models\Term;
use App\Modules\Timetable\Models\TimetableEntry;
use App\Shared\Exceptions\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Maintenance workflow and room/lab bookings.
 */
class AssetService
{
    /**
     * Move a request on: `in_progress` (assign), `resolved` (with resolution) or `closed`.
     * The asset is marked under repair while work is in progress and working again once resolved.
     *
     * @param  array{assigned_to?: string|null, resolution?: string|null, cost?: float|null}  $data
     */
    public function updateRequest(MaintenanceRequest $request, string $status, array $data): void
    {
        $allowed = ['open' => ['in_progress', 'resolved', 'closed'], 'in_progress' => ['resolved'], 'resolved' => ['closed', 'in_progress'], 'closed' => []];

        if (! in_array($status, $allowed[$request->status] ?? [], true)) {
            throw new BusinessRuleException('A '.str_replace('_', ' ', $request->status).' request cannot become '.str_replace('_', ' ', $status).'.', 'INVALID_STATUS', 409);
        }

        if ($status === 'in_progress' && blank($data['assigned_to'] ?? $request->assigned_to)) {
            throw new BusinessRuleException('Say who is working on it.', 'ASSIGNEE_REQUIRED', 422);
        }

        if ($status === 'resolved' && blank($data['resolution'] ?? null)) {
            throw new BusinessRuleException('Describe what was done.', 'RESOLUTION_REQUIRED', 422);
        }

        DB::transaction(function () use ($request, $status, $data): void {
            $request->forceFill([
                'status' => $status,
                'assigned_to' => $data['assigned_to'] ?? $request->assigned_to,
                'resolution' => $data['resolution'] ?? $request->resolution,
                'cost' => $data['cost'] ?? $request->cost,
                'resolved_at' => $status === 'resolved' ? now() : ($status === 'in_progress' ? null : $request->resolved_at),
            ])->save();

            if ($request->asset !== null && $request->asset->status !== 'condemned') {
                if ($status === 'in_progress') {
                    $request->asset->forceFill(['status' => 'under_repair'])->save();
                } elseif ($status === 'resolved') {
                    $request->asset->forceFill(['status' => 'working'])->save();
                }
            }
        });
    }

    /**
     * Books a room when it is free: no other booking and no timetable class in that room at that time.
     */
    public function book(Room $room, Carbon $date, string $startsAt, string $endsAt, string $purpose): RoomBooking
    {
        if ($endsAt <= $startsAt) {
            throw new BusinessRuleException('The booking must end after it starts.', 'TIME_INVALID', 422);
        }

        if ($date->lt(today())) {
            throw new BusinessRuleException('Bookings are for today or later.', 'DATE_INVALID', 422);
        }

        $clash = RoomBooking::query()->where('room_id', $room->id)->where('status', 'booked')->whereDate('date', $date)
            ->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt)->exists();
        $term = Term::query()->where('is_current', true)->first();
        $class = $term !== null && TimetableEntry::query()->where('room_id', $room->id)->where('day_of_week', $date->dayOfWeekIso)
            ->whereHas('timetable', fn ($q) => $q->where('term_id', $term->id)->where('status', 'published'))
            ->whereHas('slot', fn ($q) => $q->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt))->exists();

        if ($clash || $class) {
            throw new BusinessRuleException($class ? 'A timetabled class uses this room then.' : 'The room is already booked then.', 'ROOM_BUSY', 409);
        }

        $booking = RoomBooking::query()->create(['room_id' => $room->id, 'date' => $date->toDateString(), 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'purpose' => $purpose]);
        $booking->forceFill(['status' => 'booked'])->save();

        return $booking;
    }
}

<?php

namespace App\Modules\Admissions\Models;

use App\Models\User;
use App\Modules\Admissions\Enums\ApplicationStatus;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An applicant's application for one seat row (programme and quota) of a cycle.
 *
 * @property int $admission_cycle_id
 * @property int $admission_seat_id
 * @property int $user_id
 * @property string|null $application_no
 * @property ApplicationStatus $status
 * @property string|null $category
 * @property array<string, mixed>|null $personal
 * @property array<string, mixed>|null $guardian
 * @property array<string, mixed>|null $academic
 * @property array<string, mixed>|null $entrance
 * @property string|null $qualifying_percentage
 * @property bool $seat_held
 * @property string|null $remarks
 * @property Carbon|null $submitted_at
 * @property Carbon|null $offered_at
 * @property Carbon|null $offer_expires_at
 * @property Carbon|null $admitted_at
 * @property string|null $register_no
 * @property string|null $fee_amount
 * @property string|null $fee_reference
 * @property-read AdmissionCycle $cycle
 * @property-read AdmissionSeat $seat
 * @property-read User $user
 */
#[Fillable(['admission_cycle_id', 'admission_seat_id', 'user_id', 'category', 'personal', 'guardian', 'academic', 'entrance', 'qualifying_percentage'])]
class Application extends TenantModel
{
    protected string $auditLog = 'admissions';

    protected $attributes = ['status' => 'draft', 'seat_held' => false];

    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'personal' => 'array',
            'guardian' => 'array',
            'academic' => 'array',
            'entrance' => 'array',
            'qualifying_percentage' => 'decimal:2',
            'seat_held' => 'boolean',
            'submitted_at' => 'datetime',
            'offered_at' => 'datetime',
            'offer_expires_at' => 'datetime',
            'admitted_at' => 'datetime',
            'fee_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<AdmissionCycle, $this>
     */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(AdmissionCycle::class, 'admission_cycle_id');
    }

    /**
     * @return BelongsTo<AdmissionSeat, $this>
     */
    public function seat(): BelongsTo
    {
        return $this->belongsTo(AdmissionSeat::class, 'admission_seat_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ApplicationDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(ApplicationDocument::class);
    }

    /**
     * @return HasMany<ApplicationStatusHistory, $this>
     */
    public function history(): HasMany
    {
        return $this->hasMany(ApplicationStatusHistory::class)->orderBy('id');
    }
}

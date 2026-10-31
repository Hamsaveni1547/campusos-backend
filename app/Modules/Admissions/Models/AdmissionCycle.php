<?php

namespace App\Modules\Admissions\Models;

use App\Modules\Admissions\Enums\CycleStatus;
use App\Modules\Institution\Models\AcademicYear;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One intake round, e.g. "B.E. admissions 2026-27".
 *
 * @property int $academic_year_id
 * @property string $name
 * @property CycleStatus $status
 * @property Carbon $opens_on
 * @property Carbon $closes_on
 * @property string $application_fee
 * @property int $offer_valid_days
 * @property list<string>|null $required_documents
 * @property string|null $instructions
 * @property-read AcademicYear $academicYear
 */
#[Fillable(['academic_year_id', 'name', 'status', 'opens_on', 'closes_on', 'application_fee', 'offer_valid_days', 'required_documents', 'instructions'])]
class AdmissionCycle extends TenantModel
{
    protected string $auditLog = 'admissions';

    protected $attributes = ['status' => 'draft', 'offer_valid_days' => 7, 'application_fee' => 0];

    protected function casts(): array
    {
        return [
            'status' => CycleStatus::class,
            'opens_on' => 'date',
            'closes_on' => 'date',
            'application_fee' => 'decimal:2',
            'offer_valid_days' => 'integer',
            'required_documents' => 'array',
        ];
    }

    /**
     * Open, and today is inside the application window.
     */
    public function isAcceptingApplications(): bool
    {
        return $this->status === CycleStatus::Open && now()->startOfDay()->betweenIncluded($this->opens_on, $this->closes_on);
    }

    /**
     * @return list<string>
     */
    public function requiredDocuments(): array
    {
        return $this->required_documents ?? [];
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * @return HasMany<AdmissionSeat, $this>
     */
    public function seats(): HasMany
    {
        return $this->hasMany(AdmissionSeat::class);
    }

    /**
     * @return HasMany<Application, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }
}

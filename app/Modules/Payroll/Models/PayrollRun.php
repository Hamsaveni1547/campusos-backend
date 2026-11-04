<?php

namespace App\Modules\Payroll\Models;

use App\Models\User;
use App\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A month's payroll: draft → computed → approved → finalized (locked).
 *
 * @property string $month YYYY-MM
 * @property string $status draft|computed|approved|finalized
 * @property int|null $computed_by
 * @property Carbon|null $computed_at
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $finalized_by
 * @property Carbon|null $finalized_at
 * @property Carbon|null $paid_on
 * @property-read User|null $computer
 * @property-read User|null $approver
 */
#[Fillable(['month'])]
class PayrollRun extends TenantModel
{
    protected string $auditLog = 'payroll';

    protected function casts(): array
    {
        return ['computed_at' => 'datetime', 'approved_at' => 'datetime', 'finalized_at' => 'datetime', 'paid_on' => 'date'];
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'computed'], true);
    }

    public function daysInMonth(): int
    {
        return Carbon::createFromFormat('Y-m-d', $this->month.'-01')->daysInMonth;
    }

    /**
     * @return HasMany<Payslip, $this>
     */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function computer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'computed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}

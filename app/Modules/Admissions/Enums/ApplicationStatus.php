<?php

namespace App\Modules\Admissions\Enums;

/**
 * draft → submitted ⇄ correction_requested → verified → eligible → (approved for management quota)
 * → offered → offer_accepted → admitted. Side exits: ineligible, rejected, withdrawn, expired.
 */
enum ApplicationStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case CorrectionRequested = 'correction_requested';
    case Verified = 'verified';
    case Eligible = 'eligible';
    case Ineligible = 'ineligible';
    case Approved = 'approved';
    case Offered = 'offered';
    case OfferAccepted = 'offer_accepted';
    case Admitted = 'admitted';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }

    /**
     * The applicant may still change the form and documents.
     */
    public function isEditableByApplicant(): bool
    {
        return in_array($this, [self::Draft, self::CorrectionRequested], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Admitted, self::Ineligible, self::Rejected, self::Withdrawn, self::Expired], true);
    }

    /**
     * Statuses this one may move to.
     *
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted, self::Withdrawn],
            self::Submitted => [self::CorrectionRequested, self::Verified, self::Rejected, self::Withdrawn],
            self::CorrectionRequested => [self::Submitted, self::Rejected, self::Withdrawn],
            self::Verified => [self::Eligible, self::Ineligible, self::CorrectionRequested, self::Rejected, self::Withdrawn],
            self::Eligible => [self::Approved, self::Offered, self::Rejected, self::Withdrawn],
            self::Approved => [self::Offered, self::Rejected, self::Withdrawn],
            self::Offered => [self::OfferAccepted, self::Withdrawn, self::Expired],
            self::OfferAccepted => [self::Admitted, self::Withdrawn],
            default => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }
}

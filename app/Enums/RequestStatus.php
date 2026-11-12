<?php

namespace App\Enums;

enum RequestStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case PmApproved = 'pm_approved';
    case PmRejected = 'pm_rejected';
    case OverallApproved = 'overall_approved';
    case OverallRejected = 'overall_rejected';
    case PartiallyIssued = 'partially_issued';
    case FullyIssued = 'fully_issued';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Awaiting PM',
            self::PmApproved => 'Awaiting Overall Manager',
            self::PmRejected => 'Rejected by PM',
            self::OverallApproved => 'Approved - ready to issue',
            self::OverallRejected => 'Rejected by Overall Manager',
            self::PartiallyIssued => 'Partially issued',
            self::FullyIssued => 'Fully issued',
            self::Cancelled => 'Cancelled',
        };
    }

    /** CSS badge tone. */
    public function tone(): string
    {
        return match ($this) {
            self::Draft, self::Cancelled => 'muted',
            self::Submitted, self::PmApproved => 'pending',
            self::PmRejected, self::OverallRejected => 'rejected',
            self::OverallApproved, self::FullyIssued => 'approved',
            self::PartiallyIssued => 'partial',
        };
    }

    public function isIssuable(): bool
    {
        return in_array($this, [self::OverallApproved, self::PartiallyIssued], true);
    }

    public function isRejected(): bool
    {
        return in_array($this, [self::PmRejected, self::OverallRejected], true);
    }
}

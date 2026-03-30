<?php
declare(strict_types=1);

namespace CSP\Domain\Case;

final class CaseStatus
{
    public const DRAFT     = 'draft';
    public const IN_REVIEW = 'in_review';
    public const RETURNED  = 'returned';
    public const APPROVED  = 'approved';
    public const REJECTED  = 'rejected';
}

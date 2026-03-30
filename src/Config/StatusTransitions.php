<?php

declare(strict_types=1);

namespace CSP\Config;

use CSP\Domain\Case\CaseStatus;

return [
    CaseStatus::DRAFT => [
        'submit' => CaseStatus::IN_REVIEW, // Or approved directly if superadmin
    ],
    CaseStatus::IN_REVIEW => [
        'approve' => CaseStatus::APPROVED,
        'reject'  => CaseStatus::REJECTED,
        'return'  => CaseStatus::RETURNED,
    ],
    CaseStatus::RETURNED => [
        'submit'  => CaseStatus::IN_REVIEW,
        'approve' => CaseStatus::APPROVED, // Based on docs matrix, manager can approve returned cases
        'reject'  => CaseStatus::REJECTED,
    ],
    CaseStatus::APPROVED => [], // No normal transitions from here; override only
    CaseStatus::REJECTED => [], // Override only
];

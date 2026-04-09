<?php

declare(strict_types=1);

namespace CSP\Services;

use WP_User;
use CSP\Domain\Case\CaseStatus;

class CasePermissionService
{
    private CaseService $caseService;

    public function __construct(CaseService $caseService)
    {
        $this->caseService = $caseService;
    }

    public function getPermissions(int $case_id, int $user_id): array
    {
        return [
            'can_edit' => $this->canEdit($case_id, $user_id),
            'can_delete' => $this->canDelete($case_id, $user_id),
            'can_approve' => $this->canApprove($case_id, $user_id),
            'can_reject' => $this->canReject($case_id, $user_id),
            'can_return' => $this->canReturn($case_id, $user_id),
            'can_submit' => $this->canSubmit($case_id, $user_id),
        ];
    }

    private function getCaseInfo(int $case_id, int $user_id)
    {
        $case = $this->caseService->getCase($case_id);
        if (!$case)
            return null;

        $user = get_userdata($user_id);
        if (!$user)
            return null;

        $is_author = ($case['author_id'] === $user_id);
        $is_supervisor = ($case['supervisor_id'] === $user_id);
        $is_admin = in_array('administrator', $user->roles) || in_array('hm_administrator', $user->roles);
        $is_marketing = in_array('hm_marketing', $user->roles);

        return [
            'status' => $case['post_status'],
            'is_author' => $is_author,
            'is_supervisor' => $is_supervisor,
            'is_admin' => $is_admin,
            'is_marketing' => $is_marketing
        ];
    }

    public function canView(int $case_id, int $user_id): bool
    {
        $info = $this->getCaseInfo($case_id, $user_id);
        if (!$info)
            return false;

        if ($info['is_admin'] || $info['is_marketing'])
            return true;
        if ($info['is_author'] || $info['is_supervisor'])
            return true;

        // Allow viewing of approved cases by anyone
        if ($info['status'] === CaseStatus::APPROVED || $info['status'] === 'complete') {
            return true;
        }

        return false;
    }

    public function canEdit(int $case_id, int $user_id): bool
    {
        $info = $this->getCaseInfo($case_id, $user_id);
        if (!$info || $info['is_marketing'])
            return false;

        $status = $info['status'];

        if ($info['is_admin']) {
            if ($info['is_author'])
                return true;
            // Admin can edit any case that is not in a terminal-only state (draft belongs to author).
            return in_array($status, [CaseStatus::IN_REVIEW, CaseStatus::RETURNED, CaseStatus::APPROVED], true);
        }

        if ($info['is_author']) {
            return in_array($status, [CaseStatus::DRAFT, CaseStatus::RETURNED], true);
        }

        if ($info['is_supervisor']) {
            return in_array($status, [CaseStatus::IN_REVIEW, CaseStatus::RETURNED], true);
        }

        return false;
    }

    public function canDelete(int $case_id, int $user_id): bool
    {
        $info = $this->getCaseInfo($case_id, $user_id);
        if (!$info || $info['is_marketing'])
            return false;

        if ($info['is_admin'])
            return true;

        if ($info['is_author'] && $info['status'] === CaseStatus::DRAFT) {
            return true;
        }

        return false;
    }

    public function canSubmit(int $case_id, int $user_id): bool
    {
        $info = $this->getCaseInfo($case_id, $user_id);
        if (!$info || $info['is_marketing'])
            return false;

        if ($info['is_author']) {
            return in_array($info['status'], [CaseStatus::DRAFT, CaseStatus::RETURNED], true);
        }

        return false;
    }

    public function canApprove(int $case_id, int $user_id): bool
    {
        $info = $this->getCaseInfo($case_id, $user_id);
        if (!$info || $info['is_marketing'])
            return false;

        if ($info['is_admin'] && !$info['is_author']) {
            return in_array($info['status'], [CaseStatus::IN_REVIEW, CaseStatus::RETURNED], true);
        }

        if ($info['is_supervisor']) {
            return in_array($info['status'], [CaseStatus::IN_REVIEW, CaseStatus::RETURNED], true);
        }

        return false;
    }

    public function canReject(int $case_id, int $user_id): bool
    {
        return $this->canApprove($case_id, $user_id);
    }

    public function canReturn(int $case_id, int $user_id): bool
    {
        $info = $this->getCaseInfo($case_id, $user_id);
        if (!$info || $info['is_marketing'])
            return false;

        if ($info['is_admin'] && !$info['is_author']) {
            return $info['status'] === CaseStatus::IN_REVIEW;
        }

        if ($info['is_supervisor']) {
            return $info['status'] === CaseStatus::IN_REVIEW;
        }

        return false;
    }
}

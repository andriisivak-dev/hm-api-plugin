<?php

declare(strict_types=1);

namespace CSP\Services;

use WP_Error;
use CSP\Domain\Case\CaseStatus;

class CaseStatusService
{
    private CaseService $caseService;
    private CasePermissionService $casePermissionService;
    private TaxonomyService $taxonomyService;
    private NotificationService $notificationService;
    private array $transitions;

    public function __construct(
        CaseService $caseService,
        CasePermissionService $casePermissionService,
        TaxonomyService $taxonomyService,
        NotificationService $notificationService
    ) {
        $this->caseService = $caseService;
        $this->casePermissionService = $casePermissionService;
        $this->taxonomyService = $taxonomyService;
        $this->notificationService = $notificationService;
        $this->transitions = require __DIR__ . '/../Config/StatusTransitions.php';
    }

    public function submit(int $case_id, int $user_id): array|WP_Error
    {
        if (!$this->casePermissionService->canSubmit($case_id, $user_id)) {
            return new WP_Error('csp_forbidden', __('You cannot submit this case.', 'csp'), ['status' => 403]);
        }

        $case = $this->caseService->getCase($case_id);
        $current_status = $case['post_status'];

        if (!isset($this->transitions[$current_status]['submit'])) {
            return new WP_Error('csp_invalid_transition', __('Invalid status transition.', 'csp'), ['status' => 400]);
        }

        $next_status = $this->transitions[$current_status]['submit'];
        
        $user = get_userdata($user_id);
        $is_admin = in_array('administrator', $user->roles) || in_array('hm_administrator', $user->roles);
        $is_manager = in_array('hm_manager', $user->roles);

        // If author is admin or manager, it's auto-approved. Otherwise it goes to IN_REVIEW.
        if ($is_admin || $is_manager) {
            $next_status = CaseStatus::APPROVED;
        }

        // 1. Sync taxonomies
        $this->taxonomyService->syncTaxonomies($case_id, $case['hm_form_data']);

        // 2. Clear any return reasons
        update_post_meta($case_id, 'return_reason', '');
        update_post_meta($case_id, '_case_submitted_at', current_time('mysql', 1));

        // 3. Update status
        wp_update_post([
            'ID' => $case_id,
            'post_status' => $next_status
        ]);

        // 4. Record who approved (auto-approve: the submitter IS the approver)
        if ($next_status === CaseStatus::APPROVED) {
            update_post_meta($case_id, '_case_approved_by_id', $user_id);
        }

        // 5. Dispatch Notifications
        if ($next_status === CaseStatus::APPROVED) {
            $this->notificationService->onCaseApproved($case_id, (int)$case['author_id']);
        } else {
            $reviewer_id = (int)$case['supervisor_id'];
            if ($reviewer_id > 0) {
                $this->notificationService->onCaseSubmitted($case_id, $reviewer_id);
            }
        }

        return $this->caseService->getCase($case_id);
    }

    public function approve(int $case_id, int $user_id): array|WP_Error
    {
        if (!$this->casePermissionService->canApprove($case_id, $user_id)) {
            return new WP_Error('csp_forbidden', __('You cannot approve this case.', 'csp'), ['status' => 403]);
        }

        return $this->transition($case_id, 'approve', null, $user_id, function($case) {
            $this->notificationService->onCaseApproved((int)$case['id'], (int)$case['author_id']);
        });
    }

    public function reject(int $case_id, int $user_id, string $reason): array|WP_Error
    {
        if (!$this->casePermissionService->canReject($case_id, $user_id)) {
            return new WP_Error('csp_forbidden', __('You cannot reject this case.', 'csp'), ['status' => 403]);
        }

        if (empty(trim($reason))) {
            return new WP_Error('csp_invalid_input', __('A reason must be provided.', 'csp'), ['status' => 400]);
        }

        return $this->transition($case_id, 'reject', $reason, $user_id, function($case) use ($reason) {
            $this->notificationService->onCaseRejected((int)$case['id'], (int)$case['author_id'], $reason);
        });
    }

    public function return(int $case_id, int $user_id, string $reason): array|WP_Error
    {
        if (!$this->casePermissionService->canReturn($case_id, $user_id)) {
            return new WP_Error('csp_forbidden', __('You cannot return this case.', 'csp'), ['status' => 403]);
        }

        if (empty(trim($reason))) {
            return new WP_Error('csp_invalid_input', __('A reason must be provided.', 'csp'), ['status' => 400]);
        }

        return $this->transition($case_id, 'return', $reason, $user_id, function($case) use ($reason) {
            $this->notificationService->onCaseReturned((int)$case['id'], (int)$case['author_id'], $reason);
        });
    }

    public function override(int $case_id, int $user_id, string $status, ?string $reason = null): array|WP_Error
    {
        $user = get_userdata($user_id);
        $is_admin = in_array('administrator', $user->roles) || in_array('hm_administrator', $user->roles);
        
        if (!$is_admin) {
            return new WP_Error('csp_forbidden', __('Only admins can override status.', 'csp'), ['status' => 403]);
        }

        // Just override directly
        wp_update_post([
            'ID' => $case_id,
            'post_status' => $status
        ]);

        if ($reason !== null) {
            update_post_meta($case_id, 'return_reason', sanitize_text_field($reason));
        }

        // Record actor meta depending on target status
        if ($status === CaseStatus::APPROVED) {
            update_post_meta($case_id, '_case_approved_by_id', $user_id);
        } elseif ($status === CaseStatus::RETURNED) {
            update_post_meta($case_id, '_case_returned_by_id', $user_id);
        }

        // Ensure terms are synced just in case
        $case = $this->caseService->getCase($case_id);
        $this->taxonomyService->syncTaxonomies($case_id, $case['hm_form_data']);

        return $this->caseService->getCase($case_id);
    }

    /**
     * Internal FSM transition helper.
     *
     * @param int           $case_id
     * @param string        $action      Transition action key (approve|reject|return).
     * @param string|null   $reason      Optional reason text (required for reject/return).
     * @param int           $actor_id    WP user ID of the person performing the action.
     * @param callable      $onSuccess   Callback executed after a successful transition; receives the pre-transition case array.
     */
    private function transition(int $case_id, string $action, ?string $reason, int $actor_id, callable $onSuccess): array|WP_Error
    {
        $case = $this->caseService->getCase($case_id);
        $current_status = $case['post_status'];

        if (!isset($this->transitions[$current_status][$action])) {
            return new WP_Error('csp_invalid_transition', __('Invalid status transition.', 'csp'), ['status' => 400]);
        }

        $next_status = $this->transitions[$current_status][$action];

        wp_update_post([
            'ID' => $case_id,
            'post_status' => $next_status
        ]);

        if ($reason !== null) {
            update_post_meta($case_id, 'return_reason', sanitize_text_field($reason));
        } else {
            update_post_meta($case_id, 'return_reason', '');
        }

        // Record who performed the action
        if ($next_status === CaseStatus::APPROVED) {
            update_post_meta($case_id, '_case_approved_by_id', $actor_id);
        } elseif ($next_status === CaseStatus::RETURNED) {
            // Overwrite on repeat returns — intentional
            update_post_meta($case_id, '_case_returned_by_id', $actor_id);
        }

        $onSuccess($case);

        return $this->caseService->getCase($case_id);
    }
}

<?php

declare(strict_types=1);

namespace CSP\PostTypes;

use CSP\Domain\Case\CaseStatus;

class CasePostType
{
    public const POST_TYPE = 'hm_case';

    public function register(): void
    {
        register_post_type(
            self::POST_TYPE,
            [
                'label' => __('Cases', 'csp'),
                'labels' => [
                    'name' => __('Cases', 'csp'),
                    'singular_name' => __('Case', 'csp'),
                ],
                'public' => false,
                'show_ui' => true,
                'show_in_menu' => true,
                'show_in_rest' => true,
                'rest_base' => 'hm-cases', // To avoid conflict if needed, or 'cases' if rest API matters, though custom API will use its own endpoints
                'supports' => ['author', 'title', 'custom-fields'],
                'capability_type' => ['hm_case', 'hm_cases'],
                'map_meta_cap' => true,
                'rewrite' => false,
                'query_var' => true,
            ]
        );

        $this->register_statuses();
    }

    private function register_statuses(): void
    {
        $this->register_status(CaseStatus::IN_REVIEW, __('In Review', 'csp'));
        $this->register_status(CaseStatus::RETURNED, __('Returned', 'csp'));
        $this->register_status(CaseStatus::APPROVED, __('Approved', 'csp'));
        $this->register_status(CaseStatus::REJECTED, __('Rejected', 'csp'));
    }

    private function register_status(string $status, string $label): void
    {
        register_post_status(
            $status,
            [
                'label' => $label,
                'public' => false,
                'internal' => false,
                'protected' => true,
                'show_in_rest' => true,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
                'date_floating' => false,
            ]
        );
    }
}

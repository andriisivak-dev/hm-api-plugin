<?php

declare(strict_types=1);

namespace CSP\Services;

use CSP\Domain\Case\CaseStatus;
use CSP\PostTypes\CasePostType;
use WP_Error;

class CaseService
{
    /**
     * Creates a new draft case study for the current user.
     * 
     * @param int $form_id
     * @param int $total_steps
     * @return int|WP_Error The ID of the newly created case, or WP_Error on failure.
     */
    public function createDraftCase(int $form_id, int $total_steps)
    {
        $current_user_id = get_current_user_id();

        if (!$current_user_id) {
            return new WP_Error('csp_unauthorized', __('User must be logged in to create a case.', 'csp'), ['status' => 401]);
        }

        $user = get_userdata($current_user_id);
        if (!$user) {
            return new WP_Error('csp_invalid_user', __('Invalid user.', 'csp'), ['status' => 400]);
        }

        $supervisor_id = $this->determineSupervisorId($user);

        // Define initial post data
        $post_data = [
            'post_type' => CasePostType::POST_TYPE,
            'post_status' => CaseStatus::DRAFT,
            'post_author' => $current_user_id,
            'post_title' => __('Draft Case', 'csp') . ' - ' . wp_date('Y-m-d H:i:s'),
        ];

        // Insert the post
        $case_id = wp_insert_post($post_data, true);

        if (is_wp_error($case_id)) {
            return $case_id;
        }

        // Update title to include case ID safely now that we have it
        wp_update_post([
            'ID' => $case_id,
            'post_title' => __('Case #', 'csp') . $case_id,
        ]);

        // 1. hm_form_data - empty JSON object
        update_post_meta($case_id, 'hm_form_data', wp_json_encode(new \stdClass()));

        // 2. total_steps
        update_post_meta($case_id, 'total_steps', $total_steps);

        // 3. current_step
        update_post_meta($case_id, 'current_step', 0);

        // Save form_id just in case we need to reference which schema it uses
        update_post_meta($case_id, 'hm_form_id', $form_id);

        // 4. author_id
        update_post_meta($case_id, 'author_id', $current_user_id);

        // 5. supervisor_id
        if ($supervisor_id !== null) {
            update_post_meta($case_id, 'supervisor_id', $supervisor_id);
        }

        // 6. return_reason (empty initially)
        update_post_meta($case_id, 'return_reason', '');

        return $case_id;
    }

    /**
     * Determine the supervisor ID based on the user's role.
     * 
     * @param \WP_User $user
     * @return int|null 
     */
    private function determineSupervisorId(\WP_User $user): ?int
    {
        if (in_array('administrator', $user->roles, true) || in_array('hm_administrator', $user->roles, true)) {
            // Admin: auto-approved on submit — no supervisor needed.
            return null;
        }

        if (in_array('hm_manager', $user->roles, true)) {
            // Manager: auto-approved on submit — no supervisor needed.
            return null;
        }

        if (in_array('hm_field_agent', $user->roles, true)) {
            // Field Agent's supervisor is their assigned manager.
            $manager_id = (int) get_user_meta($user->ID, '_assigned_manager_id', true);
            return $manager_id > 0 ? $manager_id : null;
        }

        return null; // Fallback
    }

    /**
     * Get a case by ID with its structured metadata.
     */
    public function getCase(int $case_id): ?array
    {
        $post = get_post($case_id);
        if (!$post || $post->post_type !== CasePostType::POST_TYPE) {
            return null;
        }

        $hm_form_data_raw = get_post_meta($case_id, 'hm_form_data', true);
        $hm_form_data = !empty($hm_form_data_raw) ? json_decode($hm_form_data_raw, true) : [];

        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'post_status' => $post->post_status, // WP Post status
            'author_id' => (int) get_post_meta($case_id, 'author_id', true),
            'supervisor_id' => (int) get_post_meta($case_id, 'supervisor_id', true),
            'total_steps' => (int) get_post_meta($case_id, 'total_steps', true),
            'current_step' => (int) get_post_meta($case_id, 'current_step', true),
            'return_reason' => get_post_meta($case_id, 'return_reason', true),
            'hm_form_data' => $hm_form_data,
        ];
    }
}

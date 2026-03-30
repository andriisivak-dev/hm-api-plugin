<?php

declare(strict_types=1);

namespace CSP\DTO;

use WP_Post;
use WP_User;

class DTOMapper
{
    private array $formFieldMap;

    public function __construct()
    {
        $this->formFieldMap = require __DIR__ . '/../Config/FormFieldMap.php';
    }

    public function toCaseListItem(int $case_id, array $case_raw): array
    {
        $post = get_post($case_id);
        if (!$post) {
            return [];
        }

        $author_id = (int) $case_raw['author_id'];
        $supervisor_id = (int) $case_raw['supervisor_id'];

        $author_user = get_userdata($author_id);
        $supervisor_user = get_userdata($supervisor_id);

        $dto = [
            'id'           => $post->ID,
            'title'        => $post->post_title,
            'status'       => $post->post_status,
            'progress'     => $this->calculateProgress($case_raw['current_step'], $case_raw['total_steps']),
            'current_step' => $case_raw['current_step'],
            'total_steps'  => $case_raw['total_steps'],
            'author'       => $author_user ? [
                'id'        => $author_user->ID,
                'full_name' => $author_user->display_name,
                'role'      => !empty($author_user->roles) ? $author_user->roles[0] : ''
            ] : null,
            'reviewer'     => $supervisor_user ? [
                'id'        => $supervisor_user->ID,
                'full_name' => $supervisor_user->display_name,
            ] : null,
            'created_at'   => get_the_date('c', $post),
            'updated_at'   => get_the_modified_date('c', $post),
            'submitted_at' => get_post_meta($post->ID, '_case_submitted_at', true) ?: null,
        ];

        // Apply dynamic fields marked as "in_list"
        foreach ($this->formFieldMap as $map_entry) {
            if (!empty($map_entry['display']['in_list'])) {
                $field_val = null;
                // If it's a taxonomy, extract term name
                if (!empty($map_entry['storage']['taxonomy'])) {
                    $terms = wp_get_post_terms($post->ID, $map_entry['storage']['taxonomy']);
                    if (!empty($terms) && !is_wp_error($terms)) {
                        $field_val = $terms[0]->name;
                    }
                } 
                // Or if it has a meta key
                elseif (!empty($map_entry['storage']['meta_key'])) {
                    $field_val = get_post_meta($post->ID, $map_entry['storage']['meta_key'], true);
                } 
                // Fallback to form data JSON
                elseif (!empty($case_raw['hm_form_data'][$map_entry['field_id']])) {
                    $field_val = $case_raw['hm_form_data'][$map_entry['field_id']];
                }

                // Add to root of list item, using a sanitized key. Often the label or taxonomy slug is used.
                // We'll use taxonomy slug if present, otherwise meta_key without underscore, otherwise label
                $key = $map_entry['storage']['taxonomy'] 
                        ?? ltrim($map_entry['storage']['meta_key'] ?? '', '_case_') 
                        ?: sanitize_title($map_entry['label']);
                
                $dto[$key] = $field_val;
            }
        }

        return $dto;
    }

    public function toCaseDetail(int $case_id, array $case_raw, array $permissions = []): array
    {
        $post = get_post($case_id);
        if (!$post) {
            return [];
        }

        $author_id = (int) $case_raw['author_id'];
        $supervisor_id = (int) $case_raw['supervisor_id'];

        $author_user = get_userdata($author_id);
        $supervisor_user = get_userdata($supervisor_id);

        $taxonomies = [];
        $meta_fields = [];

        // Aggregate from FormFieldMap
        foreach ($this->formFieldMap as $map_entry) {
            if (!empty($map_entry['storage']['taxonomy'])) {
                $tax_slug = $map_entry['storage']['taxonomy'];
                $terms = wp_get_object_terms($post->ID, $tax_slug);
                $taxonomies[$tax_slug] = [];
                if (!is_wp_error($terms)) {
                    foreach ($terms as $term) {
                        $taxonomies[$tax_slug][] = [
                            'term_id' => $term->term_id,
                            'name'    => $term->name,
                            'slug'    => $term->slug
                        ];
                    }
                }
            }

            if (!empty($map_entry['storage']['meta_key'])) {
                $meta_key = $map_entry['storage']['meta_key'];
                $meta_fields[$meta_key] = get_post_meta($post->ID, $meta_key, true);
            }
        }

        // Review history logic
        $history_raw = get_post_meta($post->ID, '_case_review_history', true);
        $review_history = !empty($history_raw) ? json_decode($history_raw, true) : [];

        return [
            'id'             => $post->ID,
            'title'          => $post->post_title,
            'status'         => $post->post_status,
            'progress'       => $this->calculateProgress($case_raw['current_step'], $case_raw['total_steps']),
            'current_step'   => $case_raw['current_step'],
            'total_steps'    => $case_raw['total_steps'],
            'gf_form_id'     => (int) get_post_meta($post->ID, 'hm_form_id', true),
            'form_data'      => $case_raw['hm_form_data'],
            'taxonomies'     => $taxonomies,
            'meta_fields'    => $meta_fields,
            'author'         => $author_user ? [
                'id'        => $author_user->ID,
                'full_name' => $author_user->display_name,
                'role'      => !empty($author_user->roles) ? $author_user->roles[0] : ''
            ] : null,
            'reviewer'       => $supervisor_user ? [
                'id'        => $supervisor_user->ID,
                'full_name' => $supervisor_user->display_name,
                'role'      => !empty($supervisor_user->roles) ? $supervisor_user->roles[0] : ''
            ] : null,
            'review_message' => $case_raw['return_reason'] ?: null,
            'review_history' => $review_history,
            'permissions'    => $permissions,
            'created_at'     => get_the_date('c', $post),
            'updated_at'     => get_the_modified_date('c', $post),
            'submitted_at'   => get_post_meta($post->ID, '_case_submitted_at', true) ?: null,
        ];
    }

    public function toUser(int $user_id): array
    {
        $user = get_userdata($user_id);
        if (!$user) {
            return [];
        }

        $status = get_user_meta($user_id, '_user_status', true) ?: 'active';
        
        $supervisor_id = (int) get_user_meta($user_id, '_assigned_manager_id', true);
        $supervisor = null;
        if ($supervisor_id > 0) {
            $sup_user = get_userdata($supervisor_id);
            if ($sup_user) {
                $supervisor = [
                    'id'        => $sup_user->ID,
                    'full_name' => $sup_user->display_name
                ];
            }
        }

        $agent_ids_str = get_user_meta($user_id, '_assigned_agent_ids', true);
        $agents = !empty($agent_ids_str) ? json_decode($agent_ids_str, true) : [];

        // Count cases (simplified, real app might use WP_Query for accuracy)
        $cases_count = [
            'total'     => 0,
            'draft'     => 0,
            'in_review' => 0,
            'approved'  => 0
        ];

        return [
            'id'          => $user->ID,
            'full_name'   => $user->display_name,
            'email'       => $user->user_email,
            'role'        => !empty($user->roles) ? $user->roles[0] : '',
            'status'      => $status,
            'avatar_url'  => get_avatar_url($user->ID), // Could use WP avatar
            'supervisor'  => $supervisor,
            'agents'      => $agents, // array of IDs, could hydrate
            'cases_count' => $cases_count,
            'created_at'  => get_date_from_gmt($user->user_registered, 'c'),
        ];
    }

    public function toNotification(array $notif_raw): array
    {
        $case_post = get_post($notif_raw['case_id']);
        $case_title = $case_post ? $case_post->post_title : 'Unknown Case';

        return [
            'id'         => (int) $notif_raw['id'],
            'type'       => $notif_raw['type'],
            'case_id'    => (int) $notif_raw['case_id'],
            'case_title' => $case_title,
            'message'    => $notif_raw['message'],
            'is_read'    => (bool) $notif_raw['is_read'],
            'created_at' => gmdate('c', strtotime($notif_raw['created_at'])),
        ];
    }

    private function calculateProgress(int $current_step, int $total_steps): int
    {
        if ($total_steps <= 0) return 0;
        return (int) min(100, max(0, round(($current_step / $total_steps) * 100)));
    }
}

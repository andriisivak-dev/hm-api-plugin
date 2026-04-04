<?php

declare(strict_types=1);

namespace CSP\API\Controllers;

use WP_REST_Request;
use CSP\API\Responses\ApiResponse;
use CSP\API\Responses\ErrorCodes;
use CSP\Repositories\CaseRepository;

class DashboardController
{
    private CaseRepository $caseRepo;

    public function __construct(CaseRepository $caseRepo)
    {
        $this->caseRepo = $caseRepo;
    }

    public function getStats(WP_REST_Request $request)
    {
        $current_user_id = get_current_user_id();
        $user = get_userdata($current_user_id);

        if (!$user) {
            return ApiResponse::error(ErrorCodes::UNAUTHORIZED, __('Unauthorized', 'csp'), 401);
        }

        $is_admin = in_array('administrator', $user->roles) || in_array('hm_administrator', $user->roles);
        $is_manager = in_array('hm_manager', $user->roles);
        $is_marketing = in_array('hm_marketing', $user->roles);

        $args = ['per_page' => -1]; // Get all to count, or better yet use custom query, but Repository works for now

        if (!$is_admin && !$is_marketing) {
            if ($is_manager) {
                $agent_ids_raw = get_user_meta($current_user_id, '_assigned_agent_ids', true);
                $agent_ids = is_array($agent_ids_raw) ? $agent_ids_raw : (!empty($agent_ids_raw) ? json_decode((string)$agent_ids_raw, true) : []);
                $agent_ids = is_array($agent_ids) ? $agent_ids : [];
                $agent_ids[] = $current_user_id;
                $args['author__in'] = $agent_ids;
            } else {
                $args['author__in'] = [$current_user_id];
            }
        }

        // Just fetching via repo for counts. Real implementation should use aggregate query for performance.
        $result = $this->caseRepo->getCases($args);

        // Calculate stats
        $stats = [
            'pending_review' => 0,
            'returned'       => 0,
            'approved'       => 0,
            'rejected'       => 0,
            'draft'          => 0,
            'total'          => $result['total'],
        ];

        foreach ($result['cases'] as $case_id) {
            $status = get_post_field('post_status', $case_id);
            if ($status === 'in_review') {
                $stats['pending_review']++;
            } elseif (isset($stats[$status])) {
                $stats[$status]++;
            }
        }

        if ($is_admin) {
            $user_counts = count_users();
            $supervisors = $user_counts['avail_roles']['hm_manager'] ?? 0;
            $agents      = $user_counts['avail_roles']['hm_field_agent'] ?? 0;
            $marketing   = $user_counts['avail_roles']['hm_marketing'] ?? 0;
            
            $stats['users'] = [
                'total'       => $supervisors + $agents + $marketing,
                'supervisors' => $supervisors,
                'agents'      => $agents,
                'marketing'   => $marketing,
            ];
        }

        return ApiResponse::success($stats);
    }

    public function getFilters(WP_REST_Request $request)
    {
        // Dynamic filters based on FormFieldMap
        $filters = [
            'product_types'     => [],
            'industry_segments' => [],
            'machine_types'     => [],
            'machine_makes'     => [],
            'tool_brands'       => [],
            'solution_types'    => [],
            'submitted_by'      => []
        ];

        $taxonomies_map = [
            'hm_product_type'     => 'product_types',
            'hm_industry_segment' => 'industry_segments',
            'hm_machine_type'     => 'machine_types',
            'hm_machine_make'     => 'machine_makes',
            'hm_tool_brand'       => 'tool_brands',
            'hm_solution_type'    => 'solution_types',
        ];

        foreach ($taxonomies_map as $tax_slug => $filter_key) {
            $terms = get_terms(['taxonomy' => $tax_slug, 'hide_empty' => true]);
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $filters[$filter_key][] = [
                        'term_id' => $term->term_id,
                        'name'    => $term->name,
                        'slug'    => $term->slug,
                        'count'   => $term->count
                    ];
                }
            }
        }

        return ApiResponse::success($filters);
    }
}

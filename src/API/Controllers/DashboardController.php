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
                $agent_ids = is_array($agent_ids_raw) ? $agent_ids_raw : (!empty($agent_ids_raw) ? json_decode((string) $agent_ids_raw, true) : []);
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
            'returned' => 0,
            'approved' => 0,
            'rejected' => 0,
            'draft' => 0,
            'total' => $result['total'],
        ];

        foreach ($result['cases'] as $case_id) {
            $status = get_post_field('post_status', $case_id);
            if ($status === 'in_review') {
                $stats['pending_review']++;
            } elseif (isset($stats[$status])) {
                $stats[$status]++;
            }
        }

        if ($is_admin || $is_marketing) {
            $stats['users'] = [
                'total' => 0,
                'supervisors' => 0,
                'agents' => 0,
                'marketing' => 0,
            ];

            $roles_map = [
                'hm_manager' => 'supervisors',
                'hm_field_agent' => 'agents',
                'hm_marketing' => 'marketing',
            ];

            foreach ($roles_map as $role => $key) {
                $q = new \WP_User_Query([
                    'role' => $role,
                    'fields' => 'ID',
                    'meta_query' => [
                        'relation' => 'OR',
                        [
                            'key' => '_user_status',
                            'value' => 'inactive',
                            'compare' => '!=',
                        ],
                        [
                            'key' => '_user_status',
                            'compare' => 'NOT EXISTS',
                        ],
                    ]
                ]);
                $count = $q->get_total();
                $stats['users'][$key] = $count;
                $stats['users']['total'] += $count;
            }
        }

        return ApiResponse::success($stats);
    }

    public function getFilters(WP_REST_Request $request)
    {
        $current_user_id = get_current_user_id();
        $user = get_userdata($current_user_id);

        // Dynamic filters based on FormFieldMap
        $filters = [
            'statuses' => [
                ['id' => 'draft', 'name' => __('Draft', 'csp')],
                ['id' => 'in_review', 'name' => __('Submitted', 'csp')],
                ['id' => 'returned', 'name' => __('Returned', 'csp')],
                ['id' => 'approved', 'name' => __('Approved', 'csp')],
                ['id' => 'rejected', 'name' => __('Rejected', 'csp')]
            ],
            'product_types' => [],
            'industry_segments' => [],
            'machine_types' => [],
            'machine_makes' => [],
            'tool_brands' => [],
            'solution_types' => [],
            'submitted_by' => [
                ['id' => 'my', 'name' => __('My Cases', 'csp')]
            ]
        ];

        $taxonomies_map = [
            'hm_product_type' => 'product_types',
            'hm_industry_segment' => 'industry_segments',
            'hm_machine_type' => 'machine_types',
            'hm_machine_make' => 'machine_makes',
            'hm_tool_brand' => 'tool_brands',
            'hm_solution_type' => 'solution_types',
        ];

        foreach ($taxonomies_map as $tax_slug => $filter_key) {
            $terms = get_terms(['taxonomy' => $tax_slug, 'hide_empty' => true]);
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $filters[$filter_key][] = [
                        'term_id' => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                        'count' => $term->count
                    ];
                }
            }
        }

        if ($user) {
            $is_admin = in_array('administrator', $user->roles);
            $is_manager = in_array('hm_manager', $user->roles);
            $is_marketing = in_array('hm_marketing', $user->roles);
            $context = $request->get_param('context');

            global $wpdb;
            $author_ids = [];

            if ($context === 'library') {
                $author_ids = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('approved', 'complete')",
                        'hm_case'
                    )
                );
            } elseif ($is_admin || $is_marketing) {
                // Anyone who has written a case
                $author_ids = $wpdb->get_col(
                    $wpdb->prepare("SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type = %s", 'hm_case')
                );
            } elseif ($is_manager) {
                $agent_ids_raw = get_user_meta($current_user_id, '_assigned_agent_ids', true);
                $agent_ids = is_array($agent_ids_raw) ? $agent_ids_raw : (!empty($agent_ids_raw) ? json_decode((string) $agent_ids_raw, true) : []);
                $agent_ids = is_array($agent_ids) ? $agent_ids : [];

                if (!empty($agent_ids)) {
                    $agent_ids_in = implode(',', array_map('intval', $agent_ids));
                    $author_ids = $wpdb->get_col(
                        $wpdb->prepare("SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type = %s AND post_author IN ({$agent_ids_in}) AND post_author != %d", 'hm_case', $current_user_id)
                    );
                }
            }

            if (!empty($author_ids)) {
                $authors = get_users(['include' => $author_ids]);
                foreach ($authors as $author) {
                    if ($author->ID === $current_user_id)
                        continue;
                    $filters['submitted_by'][] = [
                        'id' => (string) $author->ID,
                        'name' => trim($author->first_name . ' ' . $author->last_name) ?: $author->display_name ?: $author->user_login
                    ];
                }
            }
        }

        return ApiResponse::success($filters);
    }

    /**
     * GET /dashboard/hierarchy
     * Returns the full user hierarchy tree for Super Admin view only.
     * Structure: { super_admin: {...}, managers: [ { ...manager, agents: [...] } ] }
     *
     * Access: administrator only → 403 for all other roles.
     */
    public function getHierarchy(WP_REST_Request $request)
    {
        $current_user = get_userdata(get_current_user_id());

        if (!$current_user || !in_array('administrator', (array) $current_user->roles, true)) {
            return ApiResponse::error(ErrorCodes::FORBIDDEN, __('Forbidden', 'csp'), 403);
        }

        // Build super admin stub
        $super_admin = [
            'id' => $current_user->ID,
            'full_name' => $current_user->display_name,
            'role' => 'administrator',
            'avatar_url' => get_avatar_url($current_user->ID),
        ];

        // Fetch all active managers
        $manager_query = new \WP_User_Query([
            'role' => 'hm_manager',
            'fields' => 'ID',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_user_status',
                    'value' => 'inactive',
                    'compare' => '!=',
                ],
                [
                    'key' => '_user_status',
                    'compare' => 'NOT EXISTS',
                ],
            ],
            'orderby' => 'display_name',
            'order' => 'ASC',
        ]);

        $managers = [];

        foreach ($manager_query->get_results() as $manager_id) {
            $manager_id = (int) $manager_id;
            $manager_user = get_userdata($manager_id);
            if (!$manager_user) {
                continue;
            }

            // Fetch agents assigned to this manager
            $agent_ids_raw = get_user_meta($manager_id, '_assigned_agent_ids', true);
            $agent_ids = is_array($agent_ids_raw)
                ? $agent_ids_raw
                : (!empty($agent_ids_raw) ? json_decode((string) $agent_ids_raw, true) : []);
            $agent_ids = is_array($agent_ids) ? array_map('intval', $agent_ids) : [];

            $agents = [];
            foreach ($agent_ids as $agent_id) {
                $agent_user = get_userdata($agent_id);
                if (!$agent_user) {
                    continue;
                }
                $agent_status = get_user_meta($agent_id, '_user_status', true) ?: 'active';
                $agents[] = [
                    'id' => $agent_user->ID,
                    'full_name' => $agent_user->display_name,
                    'role' => 'hm_field_agent',
                    'status' => $agent_status,
                    'avatar_url' => get_avatar_url($agent_user->ID),
                ];
            }

            $manager_status = get_user_meta($manager_id, '_user_status', true) ?: 'active';

            $managers[] = [
                'id' => $manager_user->ID,
                'full_name' => $manager_user->display_name,
                'role' => 'hm_manager',
                'status' => $manager_status,
                'avatar_url' => get_avatar_url($manager_user->ID),
                'agents' => $agents,
                'agents_count' => count($agents),
            ];
        }

        return ApiResponse::success([
            'super_admin' => $super_admin,
            'managers' => $managers,
        ]);
    }

    public function autocomplete(WP_REST_Request $request)
    {
        global $wpdb;

        $field = sanitize_text_field($request->get_param('field'));
        $term = sanitize_text_field($request->get_param('term'));
        $context = sanitize_text_field($request->get_param('context'));

        if (strlen($term) < 1) {
            return ApiResponse::success([]);
        }

        $meta_key_map = [
            'customer_name' => '_case_customer_name',
            'tool_specification' => '_case_tool_specification',
            'insert_specification' => '_case_insert_specification'
        ];

        if (!isset($meta_key_map[$field])) {
            return ApiResponse::error(ErrorCodes::INVALID_PARAMETER, 'Invalid field for autocomplete', 400);
        }

        $meta_key = $meta_key_map[$field];
        $like = '%' . $wpdb->esc_like($term) . '%';

        // Base query
        $sql = "SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm 
                JOIN {$wpdb->posts} p ON p.ID = pm.post_id 
                WHERE pm.meta_key = %s AND pm.meta_value LIKE %s AND p.post_type = 'hm_case'";

        if ($context === 'library') {
            $sql .= " AND p.post_status IN ('approved', 'complete')";
        }

        $sql .= " ORDER BY pm.meta_value ASC LIMIT 10";

        $results = $wpdb->get_col($wpdb->prepare($sql, $meta_key, $like));

        // Format for frontend
        $formatted = array_map(function ($value) {
            return ['text' => $value, 'value' => $value];
        }, $results);

        return ApiResponse::success($formatted);
    }

    /**
     * GET /dashboard/recent-activity
     *
     * Returns a chronological, paginated feed of recent system actions.
     * Sources:
     *   1. Case events from {prefix}csp_notifications
     *   2. New user registrations from {prefix}users (custom roles only)
     *
     * Actor resolution per event type:
     *   - case_submitted  → post_author     (the field agent who submitted)
     *   - case_approved   → _case_approved_by_id meta  (manager/admin who approved)
     *   - case_returned   → _case_returned_by_id meta  (manager/admin who returned)
     *   - case_rejected   → post_author is the field agent; actor is shown as the case author
     *
     * Each case event also includes:
     *   - case_author_name  display_name of post_author (field agent who created the case)
     *   - case_url          frontend deep-link /case-study/?cid={id}
     *
     * Access: administrator only → 403 for all other roles.
     *
     * Query params:
     *   ?page      int  Page number (default 1)
     *   ?per_page  int  Items per page (default 10, max 50)
     */
    public function getRecentActivity(WP_REST_Request $request): \WP_REST_Response
    {
        $current_user = get_userdata(get_current_user_id());

        if (!$current_user || !in_array('administrator', (array) $current_user->roles, true)) {
            return ApiResponse::error(ErrorCodes::FORBIDDEN, __('Forbidden', 'csp'), 403);
        }

        $per_page = min(50, max(1, (int) ($request->get_param('per_page') ?? 10)));
        $page = max(1, (int) ($request->get_param('page') ?? 1));

        // We fetch (page * per_page) items from each source, merge & sort, then slice.
        // This gives correct ordering across both sources without complex UNION SQL.
        $fetch_limit = $page * $per_page;

        global $wpdb;

        // ── 1. Case events from notifications table ─────────────────────────
        $notif_table = $wpdb->prefix . 'csp_notifications';

        $notif_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT n.id, n.type, n.case_id, n.message, n.created_at,
                        p.post_author AS case_author_id, p.post_title AS case_title
                 FROM {$notif_table} n
                 LEFT JOIN {$wpdb->posts} p ON p.ID = n.case_id AND p.post_type = %s
                 ORDER BY n.created_at DESC
                 LIMIT %d",
                'hm_case',
                $fetch_limit
            ),
            ARRAY_A
        );

        // Count total notifications for pagination meta
        $total_notifs = (int) $wpdb->get_var(
            "SELECT COUNT(id) FROM {$notif_table}"
        );

        $activities = [];

        foreach ((array) $notif_rows as $row) {
            $type = $row['type'];
            $case_id = (int) $row['case_id'];

            // ── Resolve case author (field agent who created the case) ──────
            $case_author_id = (int) ($row['case_author_id'] ?? 0);
            $case_author_user = $case_author_id > 0 ? get_userdata($case_author_id) : false;
            $case_author_name = $case_author_user ? $case_author_user->display_name : '';

            // ── Resolve actor (who performed the status action) ─────────────
            $actor_name = '';

            if ($type === 'case_submitted') {
                // Actor is the person who submitted = case author
                $actor_name = $case_author_name;

            } elseif ($type === 'case_approved') {
                $approved_by_id = (int) get_post_meta($case_id, '_case_approved_by_id', true);
                if ($approved_by_id > 0) {
                    $actor_user = get_userdata($approved_by_id);
                    $actor_name = $actor_user ? $actor_user->display_name : '';
                }

            } elseif ($type === 'case_returned') {
                $returned_by_id = (int) get_post_meta($case_id, '_case_returned_by_id', true);
                if ($returned_by_id > 0) {
                    $actor_user = get_userdata($returned_by_id);
                    $actor_name = $actor_user ? $actor_user->display_name : '';
                }

            } elseif ($type === 'case_rejected') {
                // For rejected we show the case title + author; no specific actor stored.
                // Leave actor_name empty; frontend renders it differently.
            }

            $case_url = home_url('/case-study/?cid=' . $case_id . '&mode=view');

            $activities[] = [
                'id' => 'notif_' . $row['id'],
                'type' => $type,
                'case_id' => $case_id,
                'case_title' => $row['case_title'] ?: ('Case #' . $case_id),
                'case_url' => $case_url,
                'case_author_name' => $case_author_name,
                'actor_name' => $actor_name,
                'message' => $row['message'],
                'created_at' => gmdate('c', strtotime($row['created_at'])),
            ];
        }

        // ── 2. Recently registered custom-role users ────────────────────────
        $user_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT u.ID, u.display_name, u.user_registered,
                        um_mgr.meta_value AS manager_id
                 FROM {$wpdb->users} u
                 INNER JOIN {$wpdb->usermeta} um_cap
                         ON um_cap.user_id = u.ID
                        AND um_cap.meta_key = %s
                 LEFT  JOIN {$wpdb->usermeta} um_mgr
                         ON um_mgr.user_id = u.ID
                        AND um_mgr.meta_key = '_assigned_manager_id'
                 WHERE (um_cap.meta_value LIKE %s
                    OR  um_cap.meta_value LIKE %s
                    OR  um_cap.meta_value LIKE %s)
                 ORDER BY u.user_registered DESC
                 LIMIT %d",
                $wpdb->prefix . 'capabilities',
                '%hm_manager%',
                '%hm_field_agent%',
                '%hm_marketing%',
                $fetch_limit
            ),
            ARRAY_A
        );

        // Count total custom-role users for pagination meta
        $total_users = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(u.ID)
                 FROM {$wpdb->users} u
                 INNER JOIN {$wpdb->usermeta} um_cap
                         ON um_cap.user_id = u.ID
                        AND um_cap.meta_key = %s
                 WHERE (um_cap.meta_value LIKE %s
                    OR  um_cap.meta_value LIKE %s
                    OR  um_cap.meta_value LIKE %s)",
                $wpdb->prefix . 'capabilities',
                '%hm_manager%',
                '%hm_field_agent%',
                '%hm_marketing%'
            )
        );

        foreach ((array) $user_rows as $row) {
            $user = get_userdata((int) $row['ID']);
            $role = !empty($user->roles) ? $user->roles[0] : 'unknown';

            $manager_name = '';
            $manager_id = (int) ($row['manager_id'] ?? 0);
            if ($manager_id > 0) {
                $mgr_user = get_userdata($manager_id);
                $manager_name = $mgr_user ? $mgr_user->display_name : '';
            }

            $activities[] = [
                'id' => 'user_' . $row['ID'],
                'type' => 'user_registered',
                'user_id' => (int) $row['ID'],
                'user_name' => $row['display_name'],
                'user_role' => $role,
                'manager_name' => $manager_name,
                'message' => '',
                'created_at' => gmdate('c', strtotime($row['user_registered'])),
            ];
        }

        // ── 3. Merge, sort DESC, paginate ────────────────────────────────────
        usort($activities, static function (array $a, array $b): int {
            return strcmp($b['created_at'], $a['created_at']);
        });

        $total = $total_notifs + $total_users;
        $offset = ($page - 1) * $per_page;
        $page_items = array_slice($activities, $offset, $per_page);
        $total_pages = $per_page > 0 ? (int) ceil($total / $per_page) : 1;

        return ApiResponse::success($page_items, '', [
            'total' => $total,
            'total_pages' => $total_pages,
            'page' => $page,
            'per_page' => $per_page,
        ]);
    }
}

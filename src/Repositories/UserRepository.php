<?php

declare(strict_types=1);

namespace CSP\Repositories;

use WP_User_Query;

class UserRepository
{
    /**
     * Retrieves users based on filters, returning their IDs.
     * 
     * @param array $args
     * @return array [ 'users' => int[] (user IDs), 'total' => int, 'total_pages' => int ]
     */
    public function getUsers(array $args = []): array
    {
        $page = isset($args['page']) ? max(1, (int) $args['page']) : 1;
        $per_page = isset($args['per_page']) ? (int) $args['per_page'] : 20;

        $query_args = [
            'number' => $per_page,
            'paged' => $page,
            'fields' => 'ID', // Return only IDs for performance and DTO mapping consistency
        ];

        // 1. Role filter
        $allowed_roles = ['hm_manager', 'hm_field_agent', 'hm_marketing'];
        if (!empty($args['role'])) {
            $role = sanitize_text_field($args['role']);
            if (in_array($role, $allowed_roles, true)) {
                $query_args['role__in'] = [$role];
            } else {
                $query_args['role__in'] = ['__invalid_role__'];
            }
        } else {
            $query_args['role__in'] = $allowed_roles;
        }

        // 2. Status filter
        $meta_query = [];
        $status = !empty($args['status']) ? $args['status'] : 'active';

        if ($status === 'active') {
            $meta_query[] = [
                'relation' => 'OR',
                [
                    'key' => '_user_status',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key' => '_user_status',
                    'value' => 'inactive',
                    'compare' => '!=',
                ]
            ];
        } elseif ($status === 'inactive') {
            $meta_query[] = [
                'key' => '_user_status',
                'value' => 'inactive',
                'compare' => '=',
            ];
        }

        // 3. Include specific users (scoping manager visibility)
        if (!empty($args['include'])) {
            $query_args['include'] = is_array($args['include']) ? $args['include'] : explode(',', $args['include']);
        }

        if (count($meta_query) > 0) {
            $query_args['meta_query'] = $meta_query;
        }

        // 4. Search filter
        if (!empty($args['search'])) {
            $search = sanitize_text_field($args['search']);
            $query_args['search'] = '*' . $search . '*';
            $query_args['search_columns'] = ['user_login', 'user_nicename', 'user_email', 'display_name'];
        }

        // 5. Order & Orderby
        $orderby_map = [
            'date' => 'user_registered',
            'name' => 'display_name',
        ];

        $arg_orderby = !empty($args['orderby']) ? $args['orderby'] : 'date';
        $orderby = $orderby_map[$arg_orderby] ?? 'user_registered';
        $order = !empty($args['order']) && strtolower($args['order']) === 'asc' ? 'ASC' : 'DESC';

        $query_args['orderby'] = $orderby;
        $query_args['order'] = $order;

        $query = new WP_User_Query($query_args);

        return [
            'users' => $query->get_results(),
            'total' => $query->get_total(),
            'total_pages' => $per_page > 0 ? (int) ceil($query->get_total() / $per_page) : 1,
            'page' => $page,
            'per_page' => $per_page,
        ];
    }
}

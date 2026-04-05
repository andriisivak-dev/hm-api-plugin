<?php

declare(strict_types=1);

namespace CSP\Repositories;

use WP_Query;
use CSP\PostTypes\CasePostType;

class CaseRepository
{
    private array $formFieldMap;

    public function __construct()
    {
        $this->formFieldMap = require __DIR__ . '/../Config/FormFieldMap.php';
    }

    /**
     * Query cases based on filters.
     * 
     * @param array $args
     * @return array [ 'cases' => int[] (post IDs), 'total' => int, 'total_pages' => int ]
     */
    public function getCases(array $args = []): array
    {
        $page = isset($args['page']) ? max(1, (int) $args['page']) : 1;
        $per_page = isset($args['per_page']) ? (int) $args['per_page'] : 20;

        $query_args = [
            'post_type' => CasePostType::POST_TYPE,
            'post_status' => 'any', // We filter via post_status if 'status' argument is passed
            'posts_per_page' => $per_page,
            'paged' => $page,
            'fields' => 'ids',
        ];

        // 1. Status filter
        if (!empty($args['status'])) {
            if (is_array($args['status'])) {
                $query_args['post_status'] = $args['status'];
            } else {
                $query_args['post_status'] = array_map('trim', explode(',', $args['status']));
            }
        } else {
            // Default: do not show trash
            $query_args['post_status'] = ['draft', 'in_review', 'returned', 'approved', 'rejected'];
        }

        // 2. Author/Submitter filter
        if (!empty($args['submitted_by'])) {
            $requested_author = $args['submitted_by'] === 'my' ? get_current_user_id() : (int) $args['submitted_by'];

            if (isset($args['author__in']) && is_array($args['author__in'])) {
                if (in_array($requested_author, $args['author__in'])) {
                    $query_args['author'] = $requested_author;
                } else {
                    $query_args['author__in'] = [0]; // Force no results if requested unallowed author
                }
            } else {
                $query_args['author'] = $requested_author;
            }
        } elseif (isset($args['author__in']) && is_array($args['author__in'])) {
            $query_args['author__in'] = $args['author__in'];
        }

        // 3. Taxonomy filters (dynamic from FormFieldMap)
        $tax_query = [];
        foreach ($this->formFieldMap as $map_entry) {
            if (!empty($map_entry['storage']['taxonomy'])) {
                $tax_slug = $map_entry['storage']['taxonomy'];
                if (!empty($args[$tax_slug])) {
                    $tax_query[] = [
                        'taxonomy' => $tax_slug,
                        'field' => 'slug',
                        'terms' => $args[$tax_slug],
                    ];
                }
            }
        }
        if (count($tax_query) > 0) {
            $tax_query['relation'] = 'AND';
            $query_args['tax_query'] = $tax_query;
        }

        // 4. Search filter (Title + Customer Name)
        if (!empty($args['search'])) {
            $search = sanitize_text_field($args['search']);
            $query_args['s'] = $search;
        }

        // 4.5. Exact/Like Meta Data Filters
        $meta_query = [];
        if (!empty($args['customer_name'])) {
            $meta_query[] = [
                'key' => '_case_customer_name',
                'value' => sanitize_text_field($args['customer_name']),
                'compare' => 'LIKE'
            ];
        }
        if (!empty($args['tool_specification'])) {
            $meta_query[] = [
                'key' => '_case_tool_specification',
                'value' => sanitize_text_field($args['tool_specification']),
                'compare' => 'LIKE'
            ];
        }
        if (!empty($args['insert_specification'])) {
            $meta_query[] = [
                'key' => '_case_insert_specification',
                'value' => sanitize_text_field($args['insert_specification']),
                'compare' => 'LIKE'
            ];
        }

        if (count($meta_query) > 0) {
            $meta_query['relation'] = 'AND';
            $query_args['meta_query'] = $meta_query;
        }

        // 5. Date filters
        $date_query = [];
        if (!empty($args['date_from'])) {
            $date_query[] = [
                'after' => $args['date_from'],
                'inclusive' => true,
                'column' => 'post_date',
            ];
        }
        if (!empty($args['date_to'])) {
            $date_query[] = [
                'before' => $args['date_to'],
                'inclusive' => true,
                'column' => 'post_date',
            ];
        }
        if (count($date_query) > 0) {
            $query_args['date_query'] = $date_query;
        }

        // 6. Order & Orderby
        $orderby_allowed = ['date', 'title', 'status']; // 'status' might need special mapping or fallback to standard post_status sorting
        $orderby = !empty($args['orderby']) && in_array($args['orderby'], $orderby_allowed) ? $args['orderby'] : 'date';
        $order = !empty($args['order']) && strtolower($args['order']) === 'asc' ? 'ASC' : 'DESC';

        if ($orderby === 'status') {
            $query_args['orderby'] = 'post_status';
        } else {
            $query_args['orderby'] = $orderby;
        }
        $query_args['order'] = $order;

        // Execute Query
        $query = new WP_Query($query_args);

        return [
            'cases' => $query->posts,
            'total' => $query->found_posts,
            'total_pages' => $query->max_num_pages,
            'page' => $page,
            'per_page' => $per_page,
        ];
    }
}

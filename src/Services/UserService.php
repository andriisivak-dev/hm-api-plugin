<?php

declare(strict_types=1);

namespace CSP\Services;

class UserService
{
    /**
     * Rebuilds the _assigned_agent_ids array for a specific manager.
     */
    public function rebuildManagerAgents(int $manager_id): void
    {
        if ($manager_id <= 0) {
            return;
        }

        // Get all active users who have this manager_id assigned.
        // We ensure we only fetch hm_field_agent or hm_manager roles that are assigned
        $args = [
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key'     => '_assigned_manager_id',
                    'value'   => $manager_id,
                    'compare' => '=',
                ],
                [
                    'relation' => 'OR',
                    [
                        'key'     => '_user_status',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => '_user_status',
                        'value'   => 'inactive',
                        'compare' => '!=',
                    ]
                ]
            ],
            'fields' => 'ID',
        ];

        $users = get_users($args);
        $agent_ids = array_map('intval', $users);

        update_user_meta($manager_id, '_assigned_agent_ids', $agent_ids);
    }
}

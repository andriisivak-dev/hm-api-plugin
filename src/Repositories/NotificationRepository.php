<?php

declare(strict_types=1);

namespace CSP\Repositories;

class NotificationRepository
{
    /**
     * Retrieves notifications for a user based on filters.
     * 
     * @param int $user_id
     * @param array $args
     * @return array
     */
    public function getNotifications(int $user_id, array $args = []): array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'csp_notifications';

        $page     = isset($args['page']) ? max(1, (int) $args['page']) : 1;
        $per_page = isset($args['per_page']) ? (int) $args['per_page'] : 20;
        $offset   = ($page - 1) * $per_page;

        $where = ["user_id = %d"];
        $params = [$user_id];

        if (isset($args['is_read'])) {
            $where[] = "is_read = %d";
            $params[] = $args['is_read'] ? 1 : 0;
        }

        $where_sql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

        // Safely parse dynamically built queries using spread operator for prepare
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(id) FROM $table_name $where_sql",
            ...$params
        ));

        // Add sorting and pagination
        $sql = "SELECT * FROM $table_name $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;

        $results = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        return [
            'notifications' => $results,
            'total'         => $total,
            'total_pages'   => $per_page > 0 ? (int) ceil($total / $per_page) : 1,
            'page'          => $page,
            'per_page'      => $per_page,
        ];
    }
    
    /**
     * Mark a single notification as read for a specific user to ensure ownership.
     */
    public function markAsRead(int $id, int $user_id): bool
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'csp_notifications';
        
        $updated = $wpdb->update(
            $table_name,
            ['is_read' => 1],
            ['id' => $id, 'user_id' => $user_id],
            ['%d'],
            ['%d', '%d']
        );
        
        return $updated !== false;
    }
    
    /**
     * Mark all unread notifications as read for a user.
     */
    public function markAllAsRead(int $user_id): bool
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'csp_notifications';
        
        $updated = $wpdb->update(
            $table_name,
            ['is_read' => 1],
            ['user_id' => $user_id, 'is_read' => 0],
            ['%d'],
            ['%d', '%d']
        );
        
        return $updated !== false;
    }
    
    /**
     * Get exact unread count for badge displaying.
     */
    public function getUnreadCount(int $user_id): int
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'csp_notifications';
        
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(id) FROM $table_name WHERE user_id = %d AND is_read = 0",
            $user_id
        ));
    }
}

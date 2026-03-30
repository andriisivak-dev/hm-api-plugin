<?php

declare(strict_types=1);

namespace CSP\Database;

class Migrations
{
    public function up(): void
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'csp_notifications';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(50) NOT NULL,
            case_id BIGINT UNSIGNED NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY case_id (case_id),
            KEY is_read (is_read)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

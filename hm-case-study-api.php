<?php
/**
 * Plugin Name: HM Case Study API
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Absolute path to the plugin main file — used for assets URL resolution. */
define('HM_CASE_STUDY_API_FILE', __FILE__);

require_once __DIR__ . '/vendor/autoload.php';

use CSP\Core\Plugin;

register_activation_hook(__FILE__, function () {
    // Notifications table
    (new \CSP\Database\Migrations())->up();
    // Customers table (wp_csp_clients)
    (new \CSP\Database\CustomerMigrations())->up();
});

add_action('plugins_loaded', function () {
    (new Plugin())->init();
});
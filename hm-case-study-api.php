<?php
/**
 * Plugin Name: HM Case Study API
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use CSP\Core\Plugin;

register_activation_hook(__FILE__, function () {
    (new \CSP\Database\Migrations())->up();
});

add_action('plugins_loaded', function () {
    (new Plugin())->init();
});
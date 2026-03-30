<?php
/**
 * Plugin Name: HM Case Study API
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use CSP\Core\Plugin;

add_action('plugins_loaded', function () {
    (new Plugin())->init();
});
<?php
/**
 * Clover Business Hours - Admin Settings
 * Integrates business hours settings into main plugin admin page
 * NOTE: Banner settings have been moved to main class-admin.php to avoid duplication
 */

namespace Src\BusinessHours;

if (!defined('ABSPATH')) {
    exit;
}

class Admin_Settings {

    public function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        // Add settings link to plugin actions
        add_filter('plugin_action_links_' . plugin_basename(CLOVER_PLUGIN_PATH . 'wp-clover-plugin.php'), array($this, 'add_settings_link'));
    }

    public function sanitize_checkbox($input) {
        return !empty($input) ? 'yes' : 'no';
    }

    public function sanitize_text($input) {
        return sanitize_text_field(trim($input));
    }

    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=clover-api-config') . '">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
}

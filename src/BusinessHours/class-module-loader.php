<?php
/**
 * Clover Business Hours - Module Loader
 * Initializes all business hours functionality
 */

namespace Src\BusinessHours;

if (!defined('ABSPATH')) {
    exit;
}

class Module_Loader {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_classes();
    }

    private function load_classes() {
        // Load AJAX handlers first
        new AJAX_Handlers();

        // Load admin settings
        new Admin_Settings();

        // Load shortcodes
        new Shortcodes();

        // Load banner display
        new Banner_Display();

        // Load category-specific hours (optional feature)
        new Category_Hours();
    }
}

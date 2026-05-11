<?php
/**
 * Clover Business Hours - AJAX Handlers
 * Handles AJAX requests for testing and debugging
 */

namespace Src\BusinessHours;

if (!defined('ABSPATH')) {
    exit;
}

class AJAX_Handlers {

    private $business_hours;

    public function __construct() {
        $this->business_hours = new Business_Hours();
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('wp_ajax_clover_bh_test_connection', array($this, 'test_connection'));
        add_action('wp_ajax_clover_bh_debug_data', array($this, 'debug_data'));
    }

    public function test_connection() {
        check_ajax_referer('clover_bh_test_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $status = $this->business_hours->get_business_status();

        if ($status['error']) {
            wp_send_json_error(array('message' => '❌ ' . esc_html($status['message'])));
        } elseif ($status['open']) {
            $msg = '✅ Currently OPEN';
            if (!empty($status['close_time'])) {
                $msg .= ' (until ' . $this->business_hours->format_minutes_to_time($status['close_time']) . ')';
            }
            wp_send_json_success(array('message' => $msg));
        } else {
            wp_send_json_success(array('message' => '✅ Currently CLOSED - ' . esc_html($status['message'])));
        }
    }

    public function debug_data() {
        check_ajax_referer('clover_bh_debug_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $data = $this->business_hours->fetch_business_hours(true);
        $hours_map = $this->business_hours->parse_hours_structure($data);

        $output = "🔍 CLOVER OPENING_HOURS - PARSED DEBUG\n";
        $output .= str_repeat("=", 60) . "\n\n";
        $output .= "⏰ Site Time: " . date('Y-m-d H:i:s T', current_time('timestamp')) . "\n";
        $output .= "📅 Site Day: " . strtoupper(date('l', current_time('timestamp'))) . "\n\n";

        if (is_wp_error($data) || isset($data['error'])) {
            $output .= "❌ Error: " . (is_wp_error($data) ? $data->get_error_message() : $data['error']) . "\n";
            wp_send_json_success(array('raw' => $output));
            return;
        }

        if (empty($hours_map)) {
            $output .= "⚠️  Could not parse any hours from API response\n";
        } else {
            $output .= "✅ Successfully parsed hours:\n\n";
            $day_order = array('MONDAY','TUESDAY','WEDNESDAY','THURSDAY','FRIDAY','SATURDAY','SUNDAY');
            foreach ($day_order as $day) {
                if (isset($hours_map[$day])) {
                    $open = $this->business_hours->format_minutes_to_time($hours_map[$day]['open']);
                    $close = $this->business_hours->format_minutes_to_time($hours_map[$day]['close']);
                    $output .= "  {$day}: {$open} - {$close}\n";
                }
            }
        }

        $output .= "\n" . str_repeat("=", 60) . "\n🗄️  Raw JSON:\n" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        wp_send_json_success(array('raw' => $output));
    }
}

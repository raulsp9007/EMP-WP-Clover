<?php
/**
 * Clover Business Hours - Main Class
 * Handles business hours fetching and status calculation
 */

namespace Src\BusinessHours;

if (!defined('ABSPATH')) {
    exit;
}

class Business_Hours {

    private $api_base = 'https://api.clover.com/v3';
    private $cache_key = 'clover_business_hours_data';
    private $cache_duration = 3600; // 1 hour

    public function __construct() {
        // Use existing plugin API credentials
    }

    /**
     * Get API credentials from main plugin settings
     */
    private function get_api_credentials() {
        return array(
            'merchant_id' => get_option('clover_merchid'),
            'api_token' => get_option('clover_token'),
            'base_url' => get_option('clover_api_base_url', 'https://api.clover.com/v3/merchants/')
        );
    }

    /**
     * Fetch business hours from Clover API
     */
    public function fetch_business_hours($force_refresh = false) {
        // Check cache first
        if (!$force_refresh) {
            $cached = get_transient($this->cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }

        $creds = $this->get_api_credentials();

        if (empty($creds['merchant_id']) || empty($creds['api_token'])) {
            return new \WP_Error('missing_creds', 'Merchant ID or API Token missing. Please configure in Clover API settings.');
        }

        // Build endpoint URL
        $endpoint = rtrim($creds['base_url'], '/') . '/' . $creds['merchant_id'] . '/opening_hours';

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $creds['api_token'],
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'timeout' => 15,
        );

        $response = wp_remote_get($endpoint, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            return array(
                'error' => isset($data['message']) ? $data['message'] : 'HTTP Error ' . $code
            );
        }

        // Cache the response
        set_transient($this->cache_key, $data, $this->cache_duration);

        return $data;
    }

    /**
     * Parse Clover's nested opening_hours structure
     * Structure: { elements: [{ monday: { elements: [{start: 1000, end: 2200}] } }] }
     */
    public function parse_hours_structure($data) {
        $hours_map = array();

        if (!isset($data['elements']) || !is_array($data['elements']) || empty($data['elements'])) {
            return $hours_map;
        }

        // Get the first element containing the hours config
        $config = $data['elements'][0];

        // Days to check (lowercase as they appear in API)
        $days = array('sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday');

        foreach ($days as $day) {
            if (isset($config[$day]['elements']) && is_array($config[$day]['elements']) && !empty($config[$day]['elements'])) {
                $slots = array();
                foreach ($config[$day]['elements'] as $slot) {
                    if (isset($slot['start'], $slot['end'])) {
                        $start_min = $this->parse_hhmm_to_minutes($slot['start']);
                        $end_min   = $this->parse_hhmm_to_minutes($slot['end']);
                        if ($start_min !== false && $end_min !== false) {
                            $slots[] = array('open' => $start_min, 'close' => $end_min);
                        }
                    }
                }
                if (!empty($slots)) {
                    $hours_map[strtoupper($day)] = $slots;
                }
            }
        }

        return $hours_map;
    }

    /**
     * Parse HHMM integer (e.g., 1000, 2200) to minutes since midnight
     */
    private function parse_hhmm_to_minutes($value) {
        if (!is_numeric($value)) {
            return false;
        }

        $val = (int)$value;
        if ($val < 0 || $val > 2359) {
            return false;
        }

        $hour = floor($val / 100);
        $min = $val % 100;

        if ($min > 59) {
            return false; // Invalid minutes
        }

        return ($hour * 60) + $min;
    }

    /**
     * Format minutes to readable time like "9:00 AM"
     */
    public function format_minutes_to_time($minutes) {
        if ($minutes === false || $minutes === null) {
            return 'N/A';
        }

        $hour = floor($minutes / 60);
        $min = $minutes % 60;
        $ampm = $hour >= 12 ? 'PM' : 'AM';
        $display_hour = $hour % 12;
        if ($display_hour == 0) {
            $display_hour = 12;
        }

        return sprintf('%d:%02d %s', $display_hour, $min, $ampm);
    }

    /**
     * Get business status
     */
    public function get_business_status($category_id = null) {
        // Check if category has custom hours
        if ($category_id) {
            $category_hours = new Category_Hours();
            $hours_data = $category_hours->get_category_hours($category_id);
            
            // If category has custom hours, use them
            if ($hours_data) {
                return $this->get_status_from_hours($hours_data);
            }
        }
        
        // Otherwise use store-wide hours
        $data = $this->fetch_business_hours();
        return $this->get_status_from_hours_data($data);
    }

    /**
     * Get business status from hours data
     */
    private function get_status_from_hours_data($data) {
        if (is_wp_error($data) || isset($data['error'])) {
            // Log error but fail OPEN — don't block orders because of API failures
            clover_log('Clover Business Hours API Error: ' . (is_wp_error($data) ? $data->get_error_message() : ($data['error'] ?? 'Unknown error')));

            return array(
                'open'       => true,  // fail open — API down should not block orders
                'error'      => true,
                'message'    => 'Hours unavailable',
                'next_open'  => null,
                'close_time' => null
            );
        }

        // Parse the Clover structure
        $hours_map = $this->parse_hours_structure($data);

        if (empty($hours_map)) {
            clover_log('Business Hours: hours_map empty after parse — check Clover dashboard has hours configured');
            return array(
                'open'       => true,  // fail open — don't block orders if hours can't be parsed
                'error'      => true,
                'message'    => 'Could not parse hours from API - check credentials and Clover dashboard',
                'next_open'  => null,
                'close_time' => null
            );
        }

        return $this->calculate_status($hours_map);
    }

    /**
     * Get business status from custom hours array
     */
    private function get_status_from_hours($hours_data) {
        if (empty($hours_data) || !isset($hours_data['elements'])) {
            return $this->get_status_from_hours_data($this->fetch_business_hours());
        }

        // Parse the hours structure
        $hours_map = $this->parse_hours_structure($hours_data);

        if (empty($hours_map)) {
            return array(
                'open' => false,
                'error' => true,
                'message' => 'Could not parse category hours',
                'next_open' => null,
                'close_time' => null
            );
        }

        return $this->calculate_status($hours_map);
    }

    /**
     * Calculate open/closed status from hours map
     */
    private function calculate_status($hours_map) {
        // Current time
        $now = current_time('timestamp');
        $current_day_upper = strtoupper(date('l', $now));
        $current_minutes = ((int)date('H', $now) * 60) + (int)date('i', $now);

        $day_order = array('MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY');

        // === CHECK CURRENT DAY — iterate all slots ===
        if (isset($hours_map[$current_day_upper])) {
            $today_slots  = $hours_map[$current_day_upper];
            $next_slot_ts = null; // earliest future slot opening today

            foreach ($today_slots as $slot) {
                $open  = $slot['open'];
                $close = $slot['close'];

                // Overnight slot (e.g. 22:00–02:00)
                if ($close < $open) {
                    if ($current_minutes >= $open || $current_minutes < $close) {
                        return array(
                            'open'       => true,
                            'error'      => false,
                            'message'    => 'Open until ' . $this->format_minutes_to_time($close),
                            'next_open'  => null,
                            'close_time' => $close
                        );
                    }
                } else {
                    // Normal slot
                    if ($current_minutes >= $open && $current_minutes < $close) {
                        $close_ts = strtotime(date('Y-m-d', $now) . ' ' . sprintf('%02d:%02d:00', floor($close / 60), $close % 60));
                        return array(
                            'open'       => true,
                            'error'      => false,
                            'message'    => 'Open until ' . $this->format_minutes_to_time($close),
                            'next_open'  => null,
                            'close_time' => $close,
                            'next_close' => $close_ts
                        );
                    }
                    // Future slot today — track earliest
                    if ($current_minutes < $open) {
                        $ts = strtotime(date('Y-m-d', $now) . ' ' . sprintf('%02d:%02d:00', floor($open / 60), $open % 60));
                        if ($next_slot_ts === null || $ts < $next_slot_ts) {
                            $next_slot_ts  = $ts;
                            $next_slot_min = $open;
                        }
                    }
                }
            }

            // Closed now, but there's a later slot today
            if ($next_slot_ts !== null) {
                return array(
                    'open'          => false,
                    'error'         => false,
                    'message'       => 'Opens today at ' . $this->format_minutes_to_time($next_slot_min),
                    'next_open'     => $next_slot_ts,
                    'next_open_day' => 'today'
                );
            }
        }

        // === FIND NEXT OPENING DAY ===
        $current_idx = array_search($current_day_upper, $day_order);
        if ($current_idx === false) {
            $current_idx = 0;
        }

        for ($i = 1; $i <= 7; $i++) {
            $next_idx = ($current_idx + $i) % 7;
            $next_day = $day_order[$next_idx];

            if (isset($hours_map[$next_day])) {
                $next = $hours_map[$next_day];
                $days_ahead = $i;

                $base_date = strtotime("+{$days_ahead} days", $now);
                $next_open_ts = strtotime(
                    date('Y-m-d', $base_date) . ' ' .
                    sprintf('%02d:%02d:00', floor($next['open'] / 60), $next['open'] % 60)
                );

                $day_label = ($i == 1) ? 'tomorrow' : ucfirst(strtolower($next_day));

                return array(
                    'open' => false,
                    'error' => false,
                    'message' => "Opens {$day_label} at " . $this->format_minutes_to_time($next['open']),
                    'next_open' => $next_open_ts,
                    'next_open_day' => $day_label
                );
            }
        }

        return array(
            'open' => false,
            'error' => true,
            'message' => 'No opening times found for next 7 days',
            'next_open' => null,
            'close_time' => null
        );
    }

    /**
     * Get formatted hours table HTML
     */
    public function get_hours_table_html() {
        $data = $this->fetch_business_hours();

        if (is_wp_error($data) || isset($data['error'])) {
            $err = is_wp_error($data) ? $data->get_error_message() : ($data['error'] ?? 'Unknown error');
            return '<p class="clover-hours-error" style="color:#dc3545;background:#f8d7da;padding:15px;border-radius:5px;">Clover Hours: ' . esc_html($err) . '</p>';
        }

        $hours_map = $this->parse_hours_structure($data);

        ob_start();
        ?>
        <style>
            .clover-hours-table{width:100%;border-collapse:collapse;margin:20px 0;font-family:sans-serif}
            .clover-hours-table th,.clover-hours-table td{border:1px solid #ddd;padding:10px 12px;text-align:left}
            .clover-hours-table th{background:#f8f9fa;font-weight:600}
            .clover-hours-table tr:nth-child(even){background:#f8f9fa}
        </style>
        <div class="clover-hours-container">
            <h3 style="margin-bottom:15px;">Business Hours</h3>
            <?php
            if (!empty($hours_map)) {
                echo '<table class="clover-hours-table"><thead><tr><th>Day</th><th>Hours</th></tr></thead><tbody>';
                $day_order = array('MONDAY','TUESDAY','WEDNESDAY','THURSDAY','FRIDAY','SATURDAY','SUNDAY');
                foreach ($day_order as $day) {
                    if (isset($hours_map[$day])) {
                        $d     = ucfirst(strtolower($day));
                        $slots = $hours_map[$day];
                        $times = array();
                        foreach ($slots as $slot) {
                            $times[] = $this->format_minutes_to_time($slot['open']) . ' - ' . $this->format_minutes_to_time($slot['close']);
                        }
                        echo '<tr><td><strong>' . esc_html($d) . '</strong></td><td>' . esc_html(implode(', ', $times)) . '</td></tr>';
                    }
                }
                echo '</tbody></table>';
            } else {
                echo '<p><em>⚠️ No opening hours available. Check API credentials and ensure hours are set in Clover dashboard.</em></p>';
            }
            ?>
        </div>
        <?php
        return ob_get_clean();
    }
}

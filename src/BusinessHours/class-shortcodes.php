<?php

/**
 * Clover Business Hours - Shortcodes
 * Handles all shortcodes for business hours display
 */

namespace Src\BusinessHours;

if (!defined('ABSPATH')) {
    exit;
}

class Shortcodes
{
    private $business_hours;

    public function __construct()
    {
        $this->business_hours = new Business_Hours();
        $this->register_shortcodes();
    }

    private function register_shortcodes()
    {
        add_shortcode('clover_business_hours', array($this, 'render_hours_shortcode'));
        add_shortcode('clover_status', array($this, 'render_status_shortcode'));
        add_shortcode('clover_countdown', array($this, 'render_countdown_shortcode'));
    }

    /**
     * [clover_business_hours] - Display full hours table
     */
    public function render_hours_shortcode($atts)
    {
        return $this->business_hours->get_hours_table_html();
    }

    /**
     * [clover_status] - Show OPEN/CLOSED status
     * Usage: [clover_status] or [clover_status category_id="123"]
     */
    public function render_status_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'category_id' => null
        ), $atts);
        
        $category_id = $atts['category_id'] ? intval($atts['category_id']) : null;
        
        // Auto-detect category on category pages
        if (!$category_id && is_product_category()) {
            $category_id = get_queried_object_id();
        }
        
        $status = $this->business_hours->get_business_status($category_id);
        return $status['open']
            ? '<span class="clover-status-open" style="color:#28a745;font-weight:700;">🟢 OPEN</span>'
            : '<span class="clover-status-closed" style="color:#dc3545;font-weight:700;">🔴 CLOSED</span>';
    }

    /**
     * [clover_countdown] - Display countdown until next opening
     * Usage: [clover_countdown] or [clover_countdown category_id="123"]
     */
    public function render_countdown_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'category_id' => null
        ), $atts);
        
        $category_id = $atts['category_id'] ? intval($atts['category_id']) : null;
        
        // Auto-detect category on category pages
        if (!$category_id && is_product_category()) {
            $category_id = get_queried_object_id();
        }
        
        $status = $this->business_hours->get_business_status($category_id);

        if ($status['open']) {
            $extra = !empty($status['close_time']) ? ' <em>(until ' . $this->business_hours->format_minutes_to_time($status['close_time']) . ')</em>' : '';
            return '<div class="clover-countdown-box clover-open" style="background:#d4edda;color:#155724;padding:15px;border-radius:5px;text-align:center;margin:20px 0;"><strong>🟢 OPEN</strong>' . $extra . '</div>';
        }

        if (!empty($status['next_open']) && empty($status['error'])) {
            ob_start();
            ?>
            <div class="clover-countdown-box" style="background:#f8d7da;color:#721c24;padding:15px;border-radius:5px;text-align:center;margin:20px 0;">
                <strong>🔴 CLOSED</strong>
                <p style="margin:10px 0 5px;"><?php echo esc_html($status['message']); ?></p>
                <div class="clover-countdown" data-next-open="<?php echo esc_attr($status['next_open']); ?>" style="margin-top:10px;">
                    <span class="clover-countdown-label">Opens in:</span><br>
                    <span class="clover-countdown-timer" style="background:rgba(0,0,0,0.1);padding:10px 20px;border-radius:20px;display:inline-block;margin-top:5px;font-size:18px;">
                        <span class="clover-days">0</span>d <span class="clover-hours">00</span>h <span class="clover-minutes">00</span>m <span class="clover-seconds">00</span>s
                    </span>
                </div>
            </div>
            <script>
            jQuery(document).ready(function($){
                function updateCountdown(){
                    $('.clover-countdown').each(function(){
                        var nextOpen = $(this).data('next-open') * 1000;
                        var now = new Date().getTime();
                        var distance = nextOpen - now;
                        if(distance < 0){
                            $(this).html('<span style="color:#28a745;font-weight:700;font-size:18px;">🟢 OPEN NOW!</span>');
                            return;
                        }
                        var days = Math.floor(distance / 86400000); // 1000*60*60*24 = 86400000
                        var hours = Math.floor(distance % 86400000 / 3600000); // 1000*60*60*24 = 86400000, 1000*60*60 = 3600000
                        var minutes = Math.floor(distance % 3600000 / 60000); // 1000*60*60 = 3600000, 1000*60 = 60000
                        var seconds = Math.floor(distance % 60000 / 1000); // 1000*60 = 60000
                        $(this).find('.clover-days').text(days);
                        $(this).find('.clover-hours').text(hours.toString().padStart(2,'0'));
                        $(this).find('.clover-minutes').text(minutes.toString().padStart(2,'0'));
                        $(this).find('.clover-seconds').text(seconds.toString().padStart(2,'0'));
                    });
                }
                updateCountdown();
                setInterval(updateCountdown, 1000);
            });
            </script>
            <?php
            return ob_get_clean();
        }

        $msg = !empty($status['message']) ? esc_html($status['message']) : 'Hours unavailable';
        return '<div class="clover-countdown-box" style="background:#f8d7da;color:#721c24;padding:15px;border-radius:5px;text-align:center;margin:20px 0;"><strong>🔴 CLOSED</strong><p style="margin:5px 0 0;">' . $msg . '</p></div>';
    }
}
